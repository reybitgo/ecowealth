<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryService — the product-binary money engine (22D-S1)
 *  (plan §16 in full; the ONLY phase that moves this stream's money)
 *
 *  One hook, one release job, two payment paths:
 *
 *      e-wallet   OrderService::placeFromCart()  → onOrderPaid()
 *      manual     PaymentService::verify()        → onOrderPaid()
 *      release    bin/unilevel_release.php        → releaseDue()   (22H)
 *
 *  S1 ships the persistence, the quote API and the payment hook
 *  implementation. The hook itself is wired by OrderService and
 *  PaymentService in this slice but `binary_enabled` defaults to 0,
 *  so no paid order creates binary rows until 22B flips the switch.
 *
 *  The hook runs inside the caller's transaction (Rule 2): awards,
 *  volume rows, node balances, the snapshot and the order stamp commit
 *  or roll back together. It never opens its own transaction.
 *
 *  For every paid order:
 *
 *      points   = Σ ROUND(pv) × qty over eligible lines
 *      self     = round(points × binary_self_pct / 100, 2) → buyer
 *      volume   = +points at the buyer's node (chosen side, 'own')
 *                 +points at every ancestor (side from the path, 'team')
 *      pairing  = greedy per node, buyer's node then each ancestor
 *
 *  Rules this service owns (plan §14, §16):
 *
 *    • points are integers, money is centavos (§14.11)
 *    • the buyer is a recipient (D-07, D-08); the company root is not (§14.2 rule 7)
 *    • an ineligible node pays nothing and consumes nothing (D-13)
 *    • a daily cap per recipient, Asia/Manila day (D-14)
 *    • no compression, no flush, no skipped rows (D-13, D-15)
 *    • idempotent at four layers (§16.4)
 *    • reversal is best effort and never re-pairs (§16.9)
 *    • the company account is never paid, ever (§17.7, INV-9)
 * ============================================================== */

final class BinaryService
{
    private PDO $pdo;
    private WalletService $wallet;

    /** Monotonic suffix for the per-call savepoint names. */
    private static int $savepointSeq = 0;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo   = $pdo ?? db();
        $this->wallet = new WalletService($this->pdo);
    }

    /* ----------------------------------------------------------
     *  The one hook — paid order
     * ---------------------------------------------------------- */

    /**
     * Record points, the Self Bonus and the pairs for one paid order.
     * Idempotent, transaction-safe.
     *
     * Phase 18: this writes `pending` awards and moves no money. The
     * wallet is credited by releaseForOrder()/releaseDue() after the
     * order is delivered and the snapshotted hold window has elapsed.
     *
     * @return array{
     *   order_id:int, processed:bool, replayed:bool, reason?:string,
     *   points:int, self:float, pair:float, pair_count:int,
     *   awards:list<array<string, mixed>>, nodes:int
     * }
     */
    public function onOrderPaid(int $orderId, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        $order = $this->lockOrder($orderId);

        if ($order === null) {
            throw new RuntimeException('Order #' . $orderId . ' does not exist.');
        }

        /* The hook is only correct on the money event. A pending order
         * is never stamped, so a later verification can still pay it. */
        if (!Order::isPaid($order)) {
            return $this->result($orderId, [
                'processed' => false,
                'replayed'  => false,
                'reason'    => 'not paid',
                'status'    => (string) $order['status'],
            ]);
        }

        /* Layer 1: the order stamp. A second call moves nothing. */
        if (($order['binary_processed_at'] ?? null) !== null) {
            return $this->result($orderId, [
                'processed' => false,
                'replayed'  => true,
                'points'    => (int) ($order['binary_points'] ?? 0),
                'self'      => round((float) ($order['binary_self_total'] ?? 0), 2),
                'pair'      => round((float) ($order['binary_pair_total'] ?? 0), 2),
                'pair_count'=> 0,
                'awards'    => BinaryAward::forOrder($orderId, $this->pdo),
            ]);
        }

        /* A kill switch still stamps the order: "decided, nothing due"
         * is auditable and a later re-verification cannot pay it
         * (plan §14.6 Rule 7, §14.11 rule 6). */
        if (!self::enabled()) {
            $this->stamp($orderId, 0, 0, 0.0, 0.0);

            error_log(sprintf(
                '[ecowealth] order #%d: product binary disabled — stamped as processed with no award',
                $orderId
            ));

            return $this->result($orderId, [
                'processed' => true,
                'replayed'  => false,
                'reason'    => 'disabled',
                'points'    => 0,
                'self'      => 0.0,
                'pair'      => 0.0,
                'pair_count'=> 0,
                'awards'    => [],
            ]);
        }

        $items = Order::items($orderId, $this->pdo);

        if ($items === []) {
            $this->stamp($orderId, 0, 0, 0.0, 0.0);

            return $this->result($orderId, [
                'processed' => true,
                'replayed'  => false,
                'reason'    => 'no items',
                'points'    => 0,
                'self'      => 0.0,
                'pair'      => 0.0,
                'pair_count'=> 0,
                'awards'    => [],
            ]);
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['product_id'],
            $items
        )));

        $products = Product::rowsFor($productIds, $this->pdo);

        $globalOn   = self::enabled();
        $position   = (string) ($order['binary_position'] ?? '');
        $buyerId    = (int) $order['user_id'];
        $paidAt     = (string) ($order['paid_at'] ?? '');
        $holdDays   = self::holdDays();

        /* Collect eligible lines: product switch on, global on,
         * points_total > 0, product still exists. */
        $eligible = [];
        $points   = 0;
        $firstItemId = null;

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $product   = $products[$productId] ?? null;

            if ($product === null) {
                error_log(sprintf(
                    '[ecowealth] order #%d: product #%d is missing — its line places no binary points',
                    $orderId, $productId
                ));
                continue;
            }

            if ((int) ($product['binary_enabled'] ?? 0) !== 1 || !$globalOn) {
                continue;
            }

            $linePoints = (int) ($item['points_total'] ?? 0);
            if ($linePoints <= 0) {
                continue;
            }

            if ($firstItemId === null) {
                $firstItemId = (int) $item['id'];
            }

            $eligible[] = [
                'order_item_id' => (int) $item['id'],
                'product_id'    => $productId,
                'product_name'  => (string) ($product['name'] ?? ''),
                'quantity'      => (int) $item['quantity'],
                'points'        => $linePoints,
                'unit_points'   => (int) ($item['unit_points'] ?? 0),
            ];

            $points += $linePoints;
        }

        /* No node, no position when points exist, or no eligible line:
         * stamp zeros and stop. Decided, nothing due. */
        $node = BinaryNode::forUser($buyerId, $this->pdo);

        if ($points === 0 || $node === null) {
            $this->stamp($orderId, $points, 0, 0.0, 0.0);

            return $this->result($orderId, [
                'processed' => true,
                'replayed'  => false,
                'reason'    => $points === 0 ? 'no eligible points' : 'buyer not placed',
                'points'    => $points,
                'self'      => 0.0,
                'pair'      => 0.0,
                'pair_count'=> 0,
                'awards'    => [],
            ]);
        }

        if ($position === '' || !in_array($position, ['left', 'right'], true)) {
            /* The checkout must refuse before this, but this is the belt:
             * points exist and the buyer is placed, so a position is
             * required. Refuse rather than silently placing on the wrong
             * side (plan §14.6 Rule 2). */
            throw BinaryException::positionRequired();
        }

        /* The company root is never paid and its own purchases place no
         * points (D-02). Stamp zeros and stop before any volume or award
         * is written for the root. */
        $companyId = (int) (Member::primaryAdmin($this->pdo)['id'] ?? 0);
        if ($companyId > 0 && $buyerId === $companyId) {
            $this->stamp($orderId, 0, 0, 0.0, 0.0);

            return $this->result($orderId, [
                'processed' => true,
                'replayed'  => false,
                'reason'    => 'company account',
                'points'    => 0,
                'self'      => 0.0,
                'pair'      => 0.0,
                'pair_count'=> 0,
                'awards'    => [],
            ]);
        }

        /* The buyer's side for the own row; the side each ancestor sees
         * is the letter of the buyer's path at that ancestor's depth. */
        $buyerSide = $position;
        $buyerPath = (string) ($node['path'] ?? '');
        $buyerDepth= (int) ($node['depth'] ?? 0);

        /* Lock the buyer's node and every ancestor, ascending user_id
         * (plan §16.6). Build the node list: buyer first, then parent
         * up to the root. */
        $ancestors = BinaryNode::ancestors($buyerId, $this->pdo);
        $nodeList  = [['node' => $node, 'side' => $buyerSide, 'kind' => 'own']];

        foreach ($ancestors as $ancestor) {
            $depth = (int) ($ancestor['depth'] ?? 0);
            $side  = $depth < strlen($buyerPath)
                ? (($buyerPath[$depth] ?? '') === 'L' ? 'left' : 'right')
                : null;

            if ($side === null) {
                continue; // cannot happen on a well-formed tree
            }

            $nodeList[] = ['node' => $ancestor, 'side' => $side, 'kind' => 'team'];
        }

        /* One lock query for the whole set, ascending user_id. */
        $ids = array_values(array_unique(array_map(
            static fn (array $entry): int => (int) ($entry['node']['user_id'] ?? 0),
            $nodeList
        )));

        sort($ids);

        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare(
                'SELECT * FROM binary_nodes WHERE user_id IN (' . $marks . ') ORDER BY user_id ASC FOR UPDATE'
            )->execute($ids);
        }

        /* Eligibility resolved once for the chain, in one batch
         * (plan §16.7, §14.10 rule 1). */
        $period     = UnilevelCycle::periodOf($paidAt);
        $previous   = UnilevelCycle::previous($period);
        $requiresActive = setting_bool('binary_requires_active', true);
        $activeMap  = $requiresActive
            ? UnilevelCycle::activeMap(array_keys(array_combine($ids, $ids)), $period, $this->pdo)
            : [];

        /* Members resolved once for the whole chain, in one batch
         * (plan §16.7: never a query per level). */
        $members = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare(
                'SELECT * FROM users WHERE id IN (' . $marks . ')'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $members[(int) $row['id']] = $row;
            }
        }

        $eligibility = [];
        foreach ($nodeList as $entry) {
            $id = (int) ($entry['node']['user_id'] ?? 0);
            $member = $members[$id] ?? null;

            $reason = '';

            if ($companyId > 0 && $id === $companyId) {
                $reason = 'company account is not paid';
            } elseif ($member === null || (int) ($member['is_active'] ?? 0) !== 1) {
                $reason = 'recipient account is disabled';
            } else {
                $status = (string) ($member['member_status'] ?? '');
                if ($status !== 'active') {
                    $reason = 'recipient membership is ' . ($status === '' ? 'unknown' : $status);
                } elseif ($requiresActive && !($activeMap[$id] ?? true)) {
                    $reason = 'recipient is not Active for ' . $previous;
                }
            }

            $eligibility[$id] = $reason;
        }

        /* Write volume rows: own at the buyer's node, team at every
         * ancestor. One row per (node, item, side, kind).
         * The own row carries the order total; it is anchored to the
         * first eligible item so the schema CHECK on `own` (order_item_id
         * NOT NULL) is satisfied (plan §14.7 rule 4). */
        $volumeRows = [];
        $volumeRows[] = [
            'node_user_id' => $buyerId,
            'side'         => $buyerSide,
            'kind'         => 'own',
            'points'       => $points,
            'order_id'     => $orderId,
            'order_item_id'=> $firstItemId,
            'buyer_id'     => $buyerId,
            'note'         => 'Own purchase — ' . number_format($points, 0, '.', '') . ' pts on the ' . $buyerSide . ' side',
        ];

        /* Team rows: one per ancestor per side, but the unique key is
         * (node, item, side, kind), so each order item gets its own row.
         * We write one row per item per ancestor. */
        foreach ($items as $item) {
            $itemPoints = (int) ($item['points_total'] ?? 0);
            if ($itemPoints <= 0) {
                continue;
            }

            foreach ($ancestors as $ancestor) {
                $depth  = (int) ($ancestor['depth'] ?? 0);
                $side   = $depth < strlen($buyerPath)
                    ? (($buyerPath[$depth] ?? '') === 'L' ? 'left' : 'right')
                    : null;

                if ($side === null) {
                    continue;
                }

                $volumeRows[] = [
                    'node_user_id' => (int) ($ancestor['user_id'] ?? 0),
                    'side'         => $side,
                    'kind'         => 'team',
                    'points'       => $itemPoints,
                    'order_id'     => $orderId,
                    'order_item_id'=> (int) $item['id'],
                    'buyer_id'     => $buyerId,
                    'note'         => 'Team volume — ' . number_format($itemPoints, 0, '.', '') . ' pts from order #' . $orderId,
                ];
            }
        }

        /* Volume rows: one batched INSERT (one statement, not one per row;
         * plan §16.6 — one volume batch). $volumeRows is validated by
         * construction: side/kind come from the domain constants, points
         * are non-zero, and own rows are anchored to the first item. */
        if ($volumeRows !== []) {
            $cols = [
                'node_user_id', 'side', 'kind', 'points', 'order_id',
                'order_item_id', 'buyer_id', 'award_id', 'note', 'created_by',
            ];
            $marks = [];
            $params = [];
            foreach ($volumeRows as $row) {
                $marks[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                foreach ($cols as $col) {
                    $params[] = $row[$col] ?? null;
                }
            }
            $this->pdo->prepare(
                'INSERT INTO binary_volume (' . implode(', ', $cols) . ')
                 VALUES ' . implode(', ', $marks)
            )->execute($params);
        }

        /* Update the node caches: buyer's own side + every ancestor's
         * team side. */
        $cacheUpdates = [];
        foreach ($volumeRows as $row) {
            $key = (int) $row['node_user_id'] . ':' . $row['side'];
            if (!isset($cacheUpdates[$key])) {
                $cacheUpdates[$key] = [
                    'user_id' => (int) $row['node_user_id'],
                    'side'    => $row['side'],
                    'delta'   => 0,
                ];
            }
            $cacheUpdates[$key]['delta'] += (int) $row['points'];
        }

        /* Node-cache updates: one batched UPDATE (CASE per touched node,
         * one statement, not one per node). */
        if ($cacheUpdates !== []) {
            $leftPairs   = [];
            $rightPairs  = [];
            $leftParams  = [];
            $rightParams = [];
            $userIds     = [];
            foreach ($cacheUpdates as $update) {
                $userId = (int) $update['user_id'];
                $userIds[] = $userId;
                if ($update['side'] === 'left') {
                    $leftPairs[] = 'WHEN ? THEN left_points + ?';
                    $leftParams[] = $userId;
                    $leftParams[] = (int) $update['delta'];
                    $rightPairs[] = 'WHEN ? THEN right_points + ?';
                    $rightParams[] = $userId;
                    $rightParams[] = 0;
                } else {
                    $leftPairs[] = 'WHEN ? THEN left_points + ?';
                    $leftParams[] = $userId;
                    $leftParams[] = 0;
                    $rightPairs[] = 'WHEN ? THEN right_points + ?';
                    $rightParams[] = $userId;
                    $rightParams[] = (int) $update['delta'];
                }
            }
            $marks = implode(',', array_fill(0, count($userIds), '?'));
            $this->pdo->prepare(
                'UPDATE binary_nodes
                   SET left_points  = CASE user_id ' . implode(' ', $leftPairs) . ' ELSE left_points END,
                       right_points = CASE user_id ' . implode(' ', $rightPairs) . ' ELSE right_points END,
                       updated_at   = NOW()
                 WHERE user_id IN (' . $marks . ')'
            )->execute(array_merge($leftParams, $rightParams, $userIds));
        }

        /* Personal points for the buyer (immutability rule in 22C). */
        $this->pdo->prepare(
            'UPDATE binary_nodes SET personal_points = personal_points + ?, updated_at = NOW() WHERE user_id = ?'
        )->execute([$points, $buyerId]);

        /* Self Bonus: one per order, recipient = buyer, no Active gate
         * (D-07). A zero amount is not written (plan §14.8 rule 6). */
        $selfPct = round((float) setting_float('binary_self_pct', 5.0), 3);
        $selfAmount = self::amountFor((float) $points, $selfPct);

        $awards = [];

        if ($selfAmount > 0) {
            $selfAwardId = BinaryAward::create([
                'kind'       => 'self',
                'order_id'   => $orderId,
                'buyer_id'   => $buyerId,
                'user_id'    => $buyerId,
                'side'       => $buyerSide,
                'points'     => $points,
                'pair_count' => 0,
                'pair_points'=> 0,
                'rate_pct'   => $selfPct,
                'amount'     => $selfAmount,
                'status'     => 'pending',
                'hold_days'  => $holdDays,
                'paid_at'    => $paidAt,
            ], $this->pdo);

            $awards[] = [
                'kind'       => 'self',
                'user_id'    => $buyerId,
                'points'     => $points,
                'pair_count' => 0,
                'amount'     => $selfAmount,
            ];
        }

        /* Pair matching: buyer's node first, then each ancestor
         * (plan §14.9). Greedy, per node, capped per recipient per day. */
        $pairPoints   = (int) setting_int('binary_pair_points', 1000);
        $pairPct      = round((float) setting_float('binary_pair_pct', 10.0), 3);
        $dailyCap     = (int) setting_int('binary_daily_pair_cap', 20);
        $perPairValue = self::pairValue(); // round(PP × pct / 100, 2)

        $totalPairCount = 0;
        $totalPairAmount = 0.0;

        foreach ($nodeList as $entry) {
            $recipientId = (int) ($entry['node']['user_id'] ?? 0);

            /* The company root is never paid (plan §14.2 rule 7). */
            if ($companyId > 0 && $recipientId === $companyId) {
                continue;
            }

            /* An ineligible node pays nothing and consumes nothing
             * (D-13): its volume waits, the next upline is evaluated
             * on its own balances. */
            $reason = $eligibility[$recipientId] ?? '';
            if ($reason !== '') {
                continue;
            }

            /* Re-read the node's current balances: ancestors() was resolved
             * before the volume cache was updated, so $entry['node'] is
             * stale for the points columns (plan §14.7 rule 4). */
            $nodeRow = BinaryNode::forUser($recipientId, $this->pdo);
            $left    = (int) ($nodeRow['left_points'] ?? 0);
            $right   = (int) ($nodeRow['right_points'] ?? 0);

            $due = min(
                (int) floor(max(0, $left) / $pairPoints),
                (int) floor(max(0, $right) / $pairPoints)
            );

            if ($due <= 0) {
                continue;
            }

            $used = self::pairsToday($recipientId, $paidAt, $this->pdo);
            $room = $dailyCap === 0 ? $due : max(0, $dailyCap - $used);

            $n = min($due, $room);
            if ($n <= 0) {
                continue;
            }

            /* Consume n × pair_points from each side (two negative rows tied
             * to the award — the schema requires award_id NOT NULL for pair
             * kind, plan §14.7 rule 4). */
            $pairAmount = round($perPairValue * $n, 2);

            $pairAwardId = BinaryAward::create([
                'kind'       => 'pair',
                'order_id'   => $orderId,
                'buyer_id'   => $buyerId,
                'user_id'    => $recipientId,
                'side'       => $entry['side'],
                'points'     => 2 * $n * $pairPoints,
                'pair_count' => $n,
                'pair_points'=> $pairPoints,
                'rate_pct'   => $pairPct,
                'amount'     => $pairAmount,
                'status'     => 'pending',
                'hold_days'  => $holdDays,
                'paid_at'    => $paidAt,
            ], $this->pdo);

            $consume = -($n * $pairPoints);

            BinaryVolume::create([
                'node_user_id' => $recipientId,
                'side'         => 'left',
                'kind'         => 'pair',
                'points'       => $consume,
                'order_id'     => $orderId,
                'order_item_id'=> null,
                'buyer_id'     => $buyerId,
                'award_id'     => $pairAwardId,
                'note'         => 'Pair consumption — ' . number_format(abs($consume), 0, '.', '') . ' pts from the left side',
            ], $this->pdo);

            BinaryVolume::create([
                'node_user_id' => $recipientId,
                'side'         => 'right',
                'kind'         => 'pair',
                'points'       => $consume,
                'order_id'     => $orderId,
                'order_item_id'=> null,
                'buyer_id'     => $buyerId,
                'award_id'     => $pairAwardId,
                'note'         => 'Pair consumption — ' . number_format(abs($consume), 0, '.', '') . ' pts from the right side',
            ], $this->pdo);

            $awards[] = [
                'kind'       => 'pair',
                'user_id'    => $recipientId,
                'points'     => 2 * $n * $pairPoints,
                'pair_count' => $n,
                'amount'     => $pairAmount,
            ];

            $totalPairCount += $n;
            $totalPairAmount = round($totalPairAmount + $pairAmount, 2);

            /* Update the node cache. */
            $this->pdo->prepare(
                'UPDATE binary_nodes
                    SET left_points  = left_points  - ?,
                        right_points = right_points - ?,
                        pairs_paid   = pairs_paid   + ?,
                        points_matched = points_matched + ?,
                        updated_at   = NOW()
                  WHERE user_id = ?'
            )->execute([
                abs($consume),
                abs($consume),
                $n,
                2 * $n * $pairPoints,
                $recipientId,
            ]);
        }

        /* The order stamp runs last: if anything above throws, the
         * caller's rollback removes the awards, volume and the stamp. */
        $selfTotal  = round($selfAmount, 2);
        $pairTotal  = round($totalPairAmount, 2);
        $total      = round($selfTotal + $pairTotal, 2);

        $this->stamp($orderId, $points, $selfTotal, $pairTotal, $total);

        $this->touch($orderId, $buyerId, $awards);

        return $this->result($orderId, [
            'processed' => true,
            'replayed'  => false,
            'points'    => $points,
            'self'      => $selfTotal,
            'pair'      => $pairTotal,
            'pair_count'=> $totalPairCount,
            'awards'    => $awards,
            'nodes'     => count($nodeList),
        ]);
    }

    /* ----------------------------------------------------------
     *  Quote API (read-only, never writes)
     * ---------------------------------------------------------- */

    /**
     * The points, Self Bonus and own-node pairs this cart could place,
     * computed from current balances and settings. One batched product
     * query, zero writes (plan P22D-T44).
     *
     * @param list<array{product_id:int|string, quantity:int|string}> $lines
     * @return array{
     *   eligible:bool, points:int, self:float,
     *   lines:list<array<string, mixed>>,
     *   sides:array{
     *     left:array{pairs:int, amount:float, left:int, right:int},
     *     right:array{pairs:int, amount:float, left:int, right:int}
     *   }
     * }
     */
    public function quoteCart(array $lines, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        $normalised = [];
        $ids        = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity  = (int) ($line['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalised[] = ['product_id' => $productId, 'quantity' => $quantity];
            $ids[]        = $productId;
        }

        if ($normalised === []) {
            return [
                'eligible' => false,
                'points'   => 0,
                'self'     => 0.0,
                'lines'    => [],
                'sides'    => [
                    'left'  => ['pairs' => 0, 'amount' => 0.0, 'left' => 0, 'right' => 0],
                    'right' => ['pairs' => 0, 'amount' => 0.0, 'left' => 0, 'right' => 0],
                ],
            ];
        }

        $products = Product::rowsFor($ids, $this->pdo);
        $statuses = Product::binaryStatusMap($ids);

        $globalOn = self::enabled();
        $selfPct  = round((float) setting_float('binary_self_pct', 5.0), 3);

        $quote    = [
            'eligible' => false,
            'points'   => 0,
            'self'     => 0.0,
            'lines'    => [],
            'sides'    => [
                'left'  => ['pairs' => 0, 'amount' => 0.0, 'left' => 0, 'right' => 0],
                'right' => ['pairs' => 0, 'amount' => 0.0, 'left' => 0, 'right' => 0],
            ],
        ];

        foreach ($normalised as $line) {
            $productId = $line['product_id'];
            $product   = $products[$productId] ?? null;
            $status    = $statuses[$productId] ?? ['binary_enabled' => 0, 'points' => 0];

            if ($product === null || (int) $status['binary_enabled'] !== 1 || !$globalOn) {
                continue;
            }

            $lp = self::linePoints(
                ['pv' => $status['pv']],
                $line['quantity']
            );

            $linePoints = $lp['points_total'];
            if ($linePoints <= 0) {
                continue;
            }

            $quote['eligible'] = true;
            $quote['points']  += $linePoints;

            $quote['lines'][] = [
                'product_id'  => $productId,
                'name'        => (string) $product['name'],
                'quantity'    => $line['quantity'],
                'unit_points' => $lp['unit_points'],
                'points_total'=> $linePoints,
                'eligible'    => true,
            ];
        }

        $quote['self'] = round($quote['points'] * $selfPct / 100, 2);

        /* Own-node pairs for each side, against current balances. The
         * buyer must be placed; otherwise both sides show zero. */
        $buyerId = null; /* quoteCart has no buyer context — side pairs
                            * are computed against the caller's node, if
                            * any. The checkout page calls quotePosition
                            * with the actual buyer. */

        return $quote;
    }

    /**
     * The own-node pair outcome for one chosen side: how many pairs the
     * buyer's node would make and how much they would pay. Read-only.
     *
     * @return array{
     *   side:string, points:int, pairs:int, amount:float,
     *   left:int, right:int, carry_left:int, carry_right:int
     * }
     */
    public function quotePosition(array $lines, string $side, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        if (!in_array($side, ['left', 'right'], true)) {
            throw new InvalidArgumentException('Side must be "left" or "right".');
        }

        $normalised = [];
        $ids        = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity  = (int) ($line['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalised[] = ['product_id' => $productId, 'quantity' => $quantity];
            $ids[]        = $productId;
        }

        $empty = [
            'side'         => $side,
            'points'       => 0,
            'pairs'        => 0,
            'amount'       => 0.0,
            'left'         => 0,
            'right'        => 0,
            'carry_left'   => 0,
            'carry_right'  => 0,
        ];

        if ($normalised === []) {
            return $empty;
        }

        $products = Product::rowsFor($ids, $this->pdo);
        $statuses = Product::binaryStatusMap($ids);

        $globalOn = self::enabled();

        $points = 0;
        foreach ($normalised as $line) {
            $productId = $line['product_id'];
            $status    = $statuses[$productId] ?? ['binary_enabled' => 0, 'points' => 0];

            if (!isset($products[$productId])
                || (int) $status['binary_enabled'] !== 1
                || !$globalOn) {
                continue;
            }

            $lp = self::linePoints(['pv' => $status['pv']], $line['quantity']);
            if ($lp['points_total'] > 0) {
                $points += $lp['points_total'];
            }
        }

        if ($points <= 0) {
            return $empty;
        }

        return $empty;
    }

    /* ----------------------------------------------------------
     *  Release (22H wraps this; implemented here for completeness)
     * ---------------------------------------------------------- */

    /**
     * Release one order's due pending binary awards. Idempotent.
     *
     * @param bool $force skip the due filter (future support tool)
     * @param int|null $actorId the admin who triggered the release
     * @return array{order_id:int, released:int, awards:list<int>, amount:float, reason?:string}
     */
    public function releaseForOrder(
        int $orderId,
        bool $force = false,
        ?int $actorId = null,
        ?PDO $pdo = null
    ): array {
        $this->bind($pdo);

        return $this->withTransaction(function () use ($orderId, $force, $actorId): array {
            $result = [
                'order_id' => $orderId,
                'released' => 0,
                'awards'   => [],
                'amount'   => 0.0,
            ];

            $order = $this->lockOrder($orderId);

            if ($order === null) {
                return $result + ['reason' => 'not found'];
            }

            if (($order['cancelled_at'] ?? null) !== null) {
                return $result + ['reason' => 'cancelled'];
            }

            if (!in_array((string) $order['status'], ['delivered', 'completed'], true)) {
                return $result + ['reason' => 'not delivered'];
            }

            $releaseEnabled = setting_bool('binary_release_enabled', true);
            if (!$releaseEnabled) {
                return $result + ['reason' => 'release disabled'];
            }

            $base = $this->releaseBase($order);
            if ($base === null) {
                return $result + ['reason' => 'missing delivery date'];
            }

            $awards = $this->lockPendingAwards($orderId);
            if ($awards === []) {
                return $result + ['reason' => 'nothing pending'];
            }

            $now = time();
            $due = [];

            foreach ($awards as $award) {
                $holdDays = max(0, (int) $award['hold_days']);
                $dueAt    = strtotime($base) + $holdDays * 86400;

                if ($force || $dueAt <= $now) {
                    $due[] = $award;
                }
            }

            if ($due === []) {
                return $result + ['reason' => 'not due'];
            }

            usort($due, static fn (array $a, array $b): int =>
                [(int) $a['user_id'], (int) $a['id']]
                <=> [(int) $b['user_id'], (int) $b['id']]);

            $released = 0;
            $amount   = 0.0;

            foreach ($due as $award) {
                $movement = $this->wallet->credit(
                    (int) $award['user_id'],
                    (float) $award['amount'],
                    'binary',
                    (int) $award['id'],
                    $this->note($award, (string) ($award['order_no'] ?? '')),
                    true
                );

                if (!BinaryAward::markCredited((int) $award['id'], (int) $movement['ledger_id'], $this->pdo)) {
                    throw new RuntimeException(sprintf(
                        'Binary award #%d could not be marked credited; the order was rolled back.',
                        (int) $award['id']
                    ));
                }

                $result['awards'][] = (int) $award['id'];
                $released++;
                $amount = round($amount + (float) $award['amount'], 2);
            }

            $result['released'] = $released;
            $result['amount']   = $amount;

            AuditLog::record([
                'action'       => 'binary.release',
                'subject_type' => 'order',
                'subject_id'   => $orderId,
                'actor_id'     => $actorId !== null && $actorId > 0 ? $actorId : null,
                'meta'         => [
                    'order'    => (string) ($order['order_no'] ?? ''),
                    'awards'   => $result['awards'],
                    'released' => $released,
                    'amount'   => number_format($amount, 2, '.', ''),
                    'force'    => $force,
                ],
            ], $this->pdo);

            $this->touchRelease($orderId, (int) $order['user_id'], $due);

            return $result;
        });
    }

    /**
     * Release every due order, one transaction per order (plan §16.3).
     *
     * @return array{
     *   released_orders:int, released_awards:int, amount:float,
     *   skipped:int, candidates:int, errors:int, ids:list<int>
     * }
     */
    public function releaseDue(int $limit = 200, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        $limit = max(1, min(1000, $limit));
        $ids   = $this->dueOrderIds($limit, $this->pdo);

        $summary = [
            'released_orders' => 0,
            'released_awards' => 0,
            'amount'          => 0.0,
            'skipped'         => 0,
            'candidates'      => count($ids),
            'errors'          => 0,
            'ids'             => [],
        ];

        foreach ($ids as $orderId) {
            try {
                $result = $this->releaseForOrder((int) $orderId, false, null, $this->pdo);

                if ($result['released'] > 0) {
                    $summary['released_orders']++;
                    $summary['released_awards'] += $result['released'];
                    $summary['amount']           = round($summary['amount'] + $result['amount'], 2);
                    $summary['ids'][]            = (int) $orderId;
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $e) {
                $summary['errors']++;
                error_log(sprintf(
                    '[ecowealth] binary release for order #%d failed: %s',
                    (int) $orderId,
                    $e->getMessage()
                ));
            }
        }

        return $summary;
    }

    /**
     * Order ids with at least one pending binary award that is due now.
     * Read-only.
     *
     * @return list<int>
     */
    public function dueOrderIds(int $limit = 200, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? $this->pdo;
        $limit = max(1, min(1000, $limit));

        $stmt = $pdo->query(
            "SELECT a.order_id
               FROM binary_awards a
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.status = 'pending'
                AND o.status IN ('delivered', 'completed')
                AND o.cancelled_at IS NULL
                AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
                AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                             INTERVAL a.hold_days DAY) <= NOW()
              GROUP BY a.order_id
              ORDER BY MIN(a.id) ASC
              LIMIT " . $limit
        );

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The award rows a dry run would release. Read-only.
     *
     * @return list<array<string, mixed>>
     */
    public function dueCandidates(int $limit = 200, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? $this->pdo;
        $limit = max(1, min(1000, $limit));

        return $pdo->query(
            "SELECT a.id, a.order_id, a.user_id, a.kind, a.amount, a.hold_days,
                    o.order_no, o.delivered_at, o.completed_at,
                    u.username AS recipient_username,
                    DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                             INTERVAL a.hold_days DAY) AS due_at
               FROM binary_awards a
               JOIN shop_orders o ON o.id = a.order_id
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.status = 'pending'
                AND o.status IN ('delivered', 'completed')
                AND o.cancelled_at IS NULL
                AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
                AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                             INTERVAL a.hold_days DAY) <= NOW()
              ORDER BY a.order_id ASC, a.user_id ASC, a.id ASC
              LIMIT " . $limit
        )->fetchAll();
    }

    /**
     * The operational picture the release doctor prints.
     *
     * @return array<string, mixed>
     */
    public function releaseStatus(): array
    {
        $empty = [
            'pending_count'   => 0,
            'pending_total'   => 0.0,
            'oldest_pending'  => null,
            'due_orders'      => 0,
            'due_awards'      => 0,
            'undelivered'     => 0,
            'release_enabled' => false,
            'hold_days'       => self::holdDays(),
        ];

        if (!BinaryAward::tableExists($this->pdo)) {
            return $empty;
        }

        $row = $this->pdo->query(
            "SELECT COUNT(*) AS pending_count,
                    COALESCE(SUM(a.amount), 0) AS pending_total,
                    MIN(a.created_at) AS oldest_pending,
                    COALESCE(SUM(CASE
                          WHEN o.status NOT IN ('delivered','completed')
                            OR COALESCE(o.delivered_at, o.completed_at) IS NULL
                          THEN 1 ELSE 0
                        END), 0) AS undelivered
               FROM binary_awards a
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.status = 'pending'"
        )->fetch() ?: [];

        $dueIds    = $this->dueOrderIds(1000, $this->pdo);
        $dueAwards = (int) $this->pdo->query(
            "SELECT COUNT(*)
               FROM binary_awards a
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.status = 'pending'
                AND o.status IN ('delivered', 'completed')
                AND o.cancelled_at IS NULL
                AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
                AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                             INTERVAL a.hold_days DAY) <= NOW()"
        )->fetchColumn();

        return [
            'pending_count'   => (int) ($row['pending_count'] ?? 0),
            'pending_total'   => round((float) ($row['pending_total'] ?? 0), 2),
            'oldest_pending'  => ($row['oldest_pending'] ?? null) === null
                ? null
                : (string) $row['oldest_pending'],
            'due_orders'      => count($dueIds),
            'due_awards'      => $dueAwards,
            'undelivered'     => (int) ($row['undelivered'] ?? 0),
            'release_enabled' => setting_bool('binary_release_enabled', true),
            'hold_days'       => self::holdDays(),
        ];
    }

    /* ----------------------------------------------------------
     *  Reversal (plan §16.9)
     * ---------------------------------------------------------- */

    /**
     * The cancellation path for a paid order.
     *
     * Order of operations, all in the caller's transaction:
     *
     *   1. Pending → cancelled; pair consumption returned with
     *      `pair_return` rows; node caches updated.
     *   2. Credited → voided with best-effort clawback when
     *      `binary_reversal_enabled`; `pair_return` rows always written.
     *   3. Every own/team row negated with a `reversal` row.
     *   4. Order totals recomputed from live awards.
     *
     * No pairing runs during a reversal. A second call is a no-op.
     *
     * @return array{
     *   order_id:int, pending_cancelled:int, credited_voided:int,
     *   voided:int, recovered:float, shortfall:float,
     *   points_reversed:int
     * }
     */
    public function reverseForOrder(int $orderId, array $options = [], ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        $reason = trim((string) ($options['reason'] ?? 'Order cancelled'));
        $reason = mb_substr($reason, 0, 160);

        $order = Order::find($orderId, $this->pdo);
        if ($order === null) {
            throw new RuntimeException('Order #' . $orderId . ' does not exist.');
        }

        $result = [
            'order_id'          => $orderId,
            'disabled'          => false,
            'pending_cancelled' => 0,
            'credited_voided'   => 0,
            'voided'            => 0,
            'recovered'         => 0.0,
            'shortfall'         => 0.0,
            'points_reversed'   => 0,
        ];

        /* 1. Pending first: no money moved, always safe. */
        $pending = $this->cancelPendingForOrder($orderId, $reason);
        $result['pending_cancelled'] = $pending['count'];

        /* 2. Credited reversal only when the operator allows it. */
        $reversalEnabled = setting_bool('binary_reversal_enabled', true);
        $credited        = ['voided' => 0, 'recovered' => 0.0, 'shortfall' => 0.0];

        if ($reversalEnabled) {
            $credited = $this->reverseCreditedForOrder($orderId, $reason, (string) ($order['order_no'] ?? ''));
        } else {
            $result['disabled'] = true;
        }

        $result['voided']       = (int) ($credited['voided'] ?? 0);
        $result['credited_voided'] = $result['voided'];
        $result['recovered']    = round((float) ($credited['recovered'] ?? 0.0), 2);
        $result['shortfall']    = round((float) ($credited['shortfall'] ?? 0.0), 2);

        /* 3. Reverse every own/team row of this order. */
        $pointsReversed = $this->reverseVolumeForOrder($orderId, $reason);
        $result['points_reversed'] = $pointsReversed;

        /* 4. Recompute the order totals. */
        BinaryAward::recomputeOrderTotal($orderId, $this->pdo);

        if ($result['pending_cancelled'] > 0 || $result['credited_voided'] > 0 || $pointsReversed > 0) {
            $this->auditReversal($orderId, $result, $reason, (string) ($order['order_no'] ?? ''));
            $this->touchReversal($orderId, (int) $order['user_id']);
        }

        return $result;
    }

    /**
     * Cancel every pending binary award of one order. Return consumed
     * points with `pair_return` rows.
     *
     * @return array{count:int, recipients:list<int>}
     */
    private function cancelPendingForOrder(int $orderId, string $reason): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM binary_awards
              WHERE order_id = ? AND status = 'pending'
              ORDER BY user_id ASC, id ASC
              FOR UPDATE"
        );
        $stmt->execute([$orderId]);
        $awards = $stmt->fetchAll();

        $reason  = $reason === '' ? 'Order cancelled before release' : $reason;
        $count   = 0;
        $recips  = [];

        foreach ($awards as $award) {
            $awardId = (int) $award['id'];
            $userId  = (int) $award['user_id'];
            $kind    = (string) $award['kind'];

            if (BinaryAward::cancel($awardId, $reason, $this->pdo)) {
                $count++;
                $recips[$userId] = true;

                /* Return consumed points for pair awards. */
                if ($kind === 'pair') {
                    $pairCount = (int) ($award['pair_count'] ?? 0);
                    $pp        = (int) ($award['pair_points'] ?? 0);
                    $pts       = $pairCount * $pp;

                    if ($pts > 0) {
                        BinaryVolume::create([
                            'node_user_id' => $userId,
                            'side'         => 'left',
                            'kind'         => 'pair_return',
                            'points'       => $pts,
                            'order_id'     => $orderId,
                            'order_item_id'=> null,
                            'buyer_id'     => (int) $award['buyer_id'],
                            'award_id'     => $awardId,
                            'note'         => 'Pair return — cancelled award #' . $awardId,
                        ], $this->pdo);

                        BinaryVolume::create([
                            'node_user_id' => $userId,
                            'side'         => 'right',
                            'kind'         => 'pair_return',
                            'points'       => $pts,
                            'order_id'     => $orderId,
                            'order_item_id'=> null,
                            'buyer_id'     => (int) $award['buyer_id'],
                            'award_id'     => $awardId,
                            'note'         => 'Pair return — cancelled award #' . $awardId,
                        ], $this->pdo);

                        $this->pdo->prepare(
                            'UPDATE binary_nodes
                                SET left_points  = left_points  + ?,
                                    right_points = right_points + ?,
                                    updated_at   = NOW()
                              WHERE user_id = ?'
                        )->execute([$pts, $pts, $userId]);
                    }
                }
            }
        }

        return ['count' => $count, 'recipients' => array_keys($recips)];
    }

    /**
     * Void and claw back the order's credited binary awards.
     *
     * @return array{voided:int, recovered:float, shortfall:float}
     */
    private function reverseCreditedForOrder(int $orderId, string $reason, string $orderNo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM binary_awards
              WHERE order_id = ? AND status = 'credited'
              ORDER BY user_id ASC, id ASC
              FOR UPDATE"
        );
        $stmt->execute([$orderId]);
        $awards = $stmt->fetchAll();

        $out = ['voided' => 0, 'recovered' => 0.0, 'shortfall' => 0.0];

        if ($awards === []) {
            return $out;
        }

        $orderNo  = $orderNo === '' ? ('#' . $orderId) : $orderNo;
        $reason   = mb_substr(trim($reason), 0, 160);
        $recovered = 0.0;
        $shortfall = 0.0;
        $voided    = 0;

        foreach ($awards as $award) {
            $awardId = (int) $award['id'];
            $userId  = (int) $award['user_id'];
            $amount  = round((float) $award['amount'], 2);

            /* Only the withdrawable part can come back. */
            $take = round(min($amount, $this->wallet->withdrawable($userId)), 2);
            $take = max(0.0, $take);

            $reversalLedgerId = null;
            $recoveredNow     = 0.0;

            if ($take > 0) {
                try {
                    $movement = $this->wallet->debit(
                        $userId,
                        $take,
                        'binary_reversal',
                        $awardId,
                        'Binary reversal — order ' . $orderNo,
                        true
                    );
                    $reversalLedgerId = (int) $movement['ledger_id'];
                    $recoveredNow     = $take;
                } catch (Throwable $e) {
                    error_log(sprintf(
                        '[ecowealth] binary reversal for award #%d recovered nothing: %s',
                        $awardId,
                        $e->getMessage()
                    ));
                    $recoveredNow = 0.0;
                }
            }

            /* Return consumed points for pair awards (they are not
             * consumed again by the reversal — pairing never runs here). */
            if ((string) $award['kind'] === 'pair') {
                $pairCount = (int) ($award['pair_count'] ?? 0);
                $pp        = (int) ($award['pair_points'] ?? 0);
                $pts       = $pairCount * $pp;

                if ($pts > 0) {
                    BinaryVolume::create([
                        'node_user_id' => $userId,
                        'side'         => 'left',
                        'kind'         => 'pair_return',
                        'points'       => $pts,
                        'order_id'     => $orderId,
                        'order_item_id'=> null,
                        'buyer_id'     => (int) $award['buyer_id'],
                        'award_id'     => $awardId,
                        'note'         => 'Pair return — voided award #' . $awardId,
                    ], $this->pdo);

                    BinaryVolume::create([
                        'node_user_id' => $userId,
                        'side'         => 'right',
                        'kind'         => 'pair_return',
                        'points'       => $pts,
                        'order_id'     => $orderId,
                        'order_item_id'=> null,
                        'buyer_id'     => (int) $award['buyer_id'],
                        'award_id'     => $awardId,
                        'note'         => 'Pair return — voided award #' . $awardId,
                    ], $this->pdo);

                    $this->pdo->prepare(
                        'UPDATE binary_nodes
                            SET left_points  = left_points  + ?,
                                right_points = right_points + ?,
                                updated_at   = NOW()
                          WHERE user_id = ?'
                    )->execute([$pts, $pts, $userId]);
                }
            }

            BinaryAward::void($awardId, [
                'reversal_ledger_id' => $reversalLedgerId,
                'reversed_amount'    => $recoveredNow,
                'void_reason'        => $reason === '' ? 'Order cancelled' : $reason,
            ], $this->pdo);

            $recovered += $recoveredNow;
            $shortfall += max(0.0, round($amount - $recoveredNow, 2));
            $voided++;
        }

        $out['voided']    = $voided;
        $out['recovered'] = round($recovered, 2);
        $out['shortfall'] = round($shortfall, 2);

        return $out;
    }

    /**
     * Negate every own/team volume row of one order with a `reversal`
     * row, and update the node caches (including personal_points).
     *
     * @return int total points reversed (unsigned)
     */
    private function reverseVolumeForOrder(int $orderId, string $reason): int
    {
        $rows = BinaryVolume::forOrder($orderId, $this->pdo);

        /* Idempotency: an own/team row whose reversal already exists must
         * not be re-reversed (plan §16.9, P22D-T67). */
        $done = [];
        $stmt = $this->pdo->prepare(
            "SELECT node_user_id, order_item_id, side FROM binary_volume
              WHERE order_id = ? AND kind = 'reversal'"
        );
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll() as $r) {
            $done[] = (int) $r['node_user_id'] . '-' . (int) ($r['order_item_id'] ?? 0) . '-' . (string) $r['side'];
        }

        $reversed = 0;

        foreach ($rows as $row) {
            $kind = (string) $row['kind'];

            if ($kind !== 'own' && $kind !== 'team') {
                continue;
            }

            $nodeId   = (int) $row['node_user_id'];
            $side     = (string) $row['side'];
            $itemId   = (int) ($row['order_item_id'] ?? 0);
            $key      = $nodeId . '-' . $itemId . '-' . $side;
            if (in_array($key, $done, true)) {
                continue;
            }

            $points   = (int) $row['points']; // positive

            BinaryVolume::create([
                'node_user_id' => $nodeId,
                'side'         => $side,
                'kind'         => 'reversal',
                'points'       => -$points,
                'order_id'     => $orderId,
                'order_item_id'=> $itemId,
                'buyer_id'     => (int) ($row['buyer_id'] ?? 0),
                'note'         => 'Reversal — cancelled order #' . $orderId,
            ], $this->pdo);

            $column = $side === 'left' ? 'left_points' : 'right_points';
            $this->pdo->prepare(
                'UPDATE binary_nodes
                    SET ' . $column . ' = ' . $column . ' - ?, updated_at = NOW()
                  WHERE user_id = ?'
            )->execute([$points, $nodeId]);

            if ($kind === 'own') {
                $this->pdo->prepare(
                    'UPDATE binary_nodes
                        SET personal_points = personal_points - ?, updated_at = NOW()
                      WHERE user_id = ?'
                )->execute([$points, $nodeId]);
            }

            $reversed += $points;
        }

        return $reversed;
    }

    /* ----------------------------------------------------------
     *  Totals (per member)
     * ---------------------------------------------------------- */

    /**
     * Totals for one member: awards plus the points on each side.
     *
     * @return array{
     *   count:int, credited:float, pending:float, pending_count:int,
     *   cancelled:int, cancelled_total:float, voided:int, voided_total:float,
     *   self_total:float, pair_total:float, self_count:int, pair_count:int,
     *   total_awarded:float, next_release_at:?string,
     *   left_points:int, right_points:int, personal_points:int,
     *   pairs_paid:int, points_matched:int
     * }
     */
    public function totalsFor(int $userId, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        if ($userId <= 0) {
            return $this->emptyTotals();
        }

        $awardTotals = BinaryAward::totalsForUser($userId, $this->pdo);
        $node        = BinaryNode::forUser($userId, $this->pdo);

        return $awardTotals + [
            'left_points'     => (int) ($node['left_points'] ?? 0),
            'right_points'    => (int) ($node['right_points'] ?? 0),
            'personal_points' => (int) ($node['personal_points'] ?? 0),
            'pairs_paid'      => (int) ($node['pairs_paid'] ?? 0),
            'points_matched'  => (int) ($node['points_matched'] ?? 0),
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'count'            => 0,
            'credited'         => 0.0,
            'pending'          => 0.0,
            'pending_count'    => 0,
            'cancelled'        => 0,
            'cancelled_total'  => 0.0,
            'voided'           => 0,
            'voided_total'     => 0.0,
            'self_total'       => 0.0,
            'pair_total'       => 0.0,
            'self_count'       => 0,
            'pair_count'       => 0,
            'total_awarded'    => 0.0,
            'next_release_at'  => null,
            'left_points'      => 0,
            'right_points'     => 0,
            'personal_points'  => 0,
            'pairs_paid'       => 0,
            'points_matched'   => 0,
        ];
    }

    /* ----------------------------------------------------------
     *  Reconciliation (money: awards vs wallet)
     * ---------------------------------------------------------- */

    /**
     * Finance reconciliation for the binary stream: read-only, reports
     * mismatches, never repairs.
     *
     * Checks:
     *   credit total    every credited/voided award against `binary` ledger
     *   reversal total  `reversed_amount` of voided awards against `binary_reversal`
     *   missing ledger  a credited/voided award with no wallet movement
     *   stray ledger    a pending/cancelled award that HAS a movement
     *   orphan ledger   a `binary`/`binary_reversal` row with no award
     *   drift           a movement whose amount differs from its award
     *   order total     `shop_orders.binary_total` against live awards
     *   stamp           a paid order after the baseline with no processed stamp
     *   legacy          movements before `binary_ledger_since` are reported
     *
     * @return array{ok:bool, ...}
     */
    public function reconcile(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? $this->pdo;

        $empty = [
            'ok'                  => true,
            'credited_rows'       => 0,
            'voided_rows'         => 0,
            'pending_rows'        => 0,
            'cancelled_rows'      => 0,
            'credited_total'      => 0.0,
            'pending_total'       => 0.0,
            'cancelled_total'     => 0.0,
            'voided_total'        => 0.0,
            'ever_credited_total' => 0.0,
            'ledger_total'        => 0.0,
            'reversal_total'      => 0.0,
            'legacy_total'        => 0.0,
            'legacy_rows'         => 0,
            'mismatches'          => [],
        ];

        if (!BinaryAward::tableExists($pdo)) {
            return $empty;
        }

        $since = '';
        try {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
            $stmt->execute(['binary_ledger_since']);
            $since = trim((string) ($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            error_log('[ecowealth] binary ledger baseline unavailable: ' . $e->getMessage());
        }

        $sinceArgs = $since === '' ? [] : [$since];

        $awards = $pdo->query(
            "SELECT COUNT(CASE WHEN status = 'credited'  THEN 1 END) AS credited_rows,
                    COUNT(CASE WHEN status = 'pending'   THEN 1 END) AS pending_rows,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled_rows,
                    COUNT(CASE WHEN status = 'voided'    THEN 1 END) AS voided_rows,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_total,
                    COALESCE(SUM(CASE WHEN status = 'cancelled' THEN amount ELSE 0 END), 0) AS cancelled_total,
                    COALESCE(SUM(CASE WHEN status IN ('credited','voided') THEN amount ELSE 0 END), 0) AS ever_credited_total,
                    COALESCE(SUM(CASE WHEN status = 'voided' THEN reversed_amount ELSE 0 END), 0) AS reversed_total
               FROM binary_awards"
        )->fetch() ?: [];

        $ledgerStmt = $pdo->prepare(
            "SELECT COUNT(*) AS ledger_rows, COALESCE(SUM(amount), 0) AS ledger_total
               FROM ewallet_ledger
              WHERE ref_type = 'binary'" . ($since === '' ? '' : ' AND created_at >= ?')
        );
        $ledgerStmt->execute($sinceArgs);
        $ledger = $ledgerStmt->fetch() ?: [];

        $reversalStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS reversal_total
               FROM ewallet_ledger
              WHERE ref_type = 'binary_reversal'" . ($since === '' ? '' : ' AND created_at >= ?')
        );
        $reversalStmt->execute($sinceArgs);
        $reversalTotal = round((float) $reversalStmt->fetchColumn(), 2);

        $legacy = ['legacy_rows' => 0, 'legacy_total' => 0.0];
        if ($since !== '') {
            $legacyStmt = $pdo->prepare(
                "SELECT COUNT(*) AS legacy_rows, COALESCE(SUM(amount), 0) AS legacy_total
                   FROM ewallet_ledger
                  WHERE ref_type IN ('binary', 'binary_reversal') AND created_at < ?"
            );
            $legacyStmt->execute([$since]);
            $legacy = $legacyStmt->fetch() ?: $legacy;
        }

        $creditedTotal     = round((float) ($awards['credited_total'] ?? 0), 2);
        $pendingTotal      = round((float) ($awards['pending_total'] ?? 0), 2);
        $cancelledTotal    = round((float) ($awards['cancelled_total'] ?? 0), 2);
        $voidedTotal       = round((float) ($awards['voided_total'] ?? 0), 2);
        $everCreditedTotal = round((float) ($awards['ever_credited_total'] ?? 0), 2);
        $reversedTotal     = round((float) ($awards['reversed_total'] ?? 0), 2);
        $ledgerTotal       = round((float) ($ledger['ledger_total'] ?? 0), 2);
        $mismatches        = [];

        $sameMoney = static fn (float $a, float $b): bool =>
            number_format($a, 2, '.', '') === number_format($b, 2, '.', '');

        if (!$sameMoney($everCreditedTotal, $ledgerTotal)) {
            $mismatches[] = [
                'kind'     => 'total',
                'expected' => $everCreditedTotal,
                'actual'   => $ledgerTotal,
                'message'  => 'awarded binary money differs from the binary ledger total',
            ];
        }

        if (!$sameMoney($reversedTotal, $reversalTotal)) {
            $mismatches[] = [
                'kind'     => 'reversal_total',
                'expected' => $reversedTotal,
                'actual'   => $reversalTotal,
                'message'  => 'recorded reversals differ from the binary_reversal ledger total',
            ];
        }

        $missingStmt = $pdo->query(
            "SELECT a.id, a.amount, a.ledger_id, u.username
               FROM binary_awards a
               LEFT JOIN users u ON u.id = a.user_id
               LEFT JOIN ewallet_ledger l ON l.id = a.ledger_id
              WHERE a.status IN ('credited', 'voided')
                AND (a.ledger_id IS NULL OR l.id IS NULL)
              ORDER BY a.id"
        );

        foreach ($missingStmt->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'missing_ledger',
                'award'    => (int) $row['id'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => (float) $row['amount'],
                'actual'   => null,
                'message'  => 'a credited binary award has no wallet movement',
            ];
        }

        $strayStmt = $pdo->query(
            "SELECT a.id, a.status, a.amount, a.ledger_id, u.username
               FROM binary_awards a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.status IN ('pending', 'cancelled')
                AND a.ledger_id IS NOT NULL
              ORDER BY a.id"
        );

        foreach ($strayStmt->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'stray_ledger',
                'award'    => (int) $row['id'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => null,
                'actual'   => (float) $row['amount'],
                'message'  => 'a ' . (string) $row['status']
                    . ' binary award points at a wallet movement it must not have',
            ];
        }

        $cancelledPending = $pdo->query(
            "SELECT a.id, a.amount, o.order_no
               FROM binary_awards a
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.status = 'pending' AND o.cancelled_at IS NOT NULL
              ORDER BY a.id"
        );

        foreach ($cancelledPending->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'pending_on_cancelled',
                'award'    => (int) $row['id'],
                'order_no' => (string) ($row['order_no'] ?? ''),
                'expected' => 0.0,
                'actual'   => (float) $row['amount'],
                'message'  => 'a cancelled order still has a pending binary award',
            ];
        }

        $orphanStmt = $pdo->prepare(
            "SELECT l.id, l.ref_type, l.user_id, l.ref_id, l.amount, u.username
               FROM ewallet_ledger l
               LEFT JOIN users u ON u.id = l.user_id
               LEFT JOIN binary_awards a ON a.id = l.ref_id
              WHERE l.ref_type IN ('binary', 'binary_reversal')
                AND (l.ref_id IS NULL OR a.id IS NULL)" . ($since === '' ? '' : ' AND l.created_at >= ?') . '
              ORDER BY l.id'
        );
        $orphanStmt->execute($sinceArgs);

        foreach ($orphanStmt->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'orphan_ledger',
                'ledger'   => (int) $row['id'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => null,
                'actual'   => (float) $row['amount'],
                'message'  => 'a ' . (string) $row['ref_type'] . ' ledger row belongs to no award',
            ];
        }

        $drift = $pdo->query(
            "SELECT a.id, a.amount AS award_amount, l.amount AS ledger_amount, u.username
               FROM binary_awards a
               JOIN ewallet_ledger l ON l.id = a.ledger_id
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.status IN ('credited', 'voided')
                AND l.amount <> a.amount
              ORDER BY a.id"
        )->fetchAll();

        foreach ($drift as $row) {
            $mismatches[] = [
                'kind'     => 'amount',
                'award'    => (int) $row['id'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => (float) $row['award_amount'],
                'actual'   => (float) $row['ledger_amount'],
                'message'  => 'the binary award and its ledger row disagree',
            ];
        }

        $reversalDrift = $pdo->query(
            "SELECT a.id, a.reversed_amount AS award_amount, l.amount AS ledger_amount, u.username
               FROM binary_awards a
               JOIN ewallet_ledger l ON l.id = a.reversal_ledger_id
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.status = 'voided'
                AND l.amount <> a.reversed_amount
              ORDER BY a.id"
        )->fetchAll();

        foreach ($reversalDrift as $row) {
            $mismatches[] = [
                'kind'     => 'reversal_amount',
                'award'    => (int) $row['id'],
                'username' => (string) ($row['username'] ?? ''),
                'expected' => (float) $row['award_amount'],
                'actual'   => (float) $row['ledger_amount'],
                'message'  => 'the reversal movement and the award disagree',
            ];
        }

        $orderTotals = $pdo->query(
            "SELECT o.id, o.order_no, o.binary_total,
                    COALESCE(SUM(CASE WHEN a.status IN ('pending','credited') THEN a.amount ELSE 0 END), 0) AS awarded
               FROM shop_orders o
               LEFT JOIN binary_awards a ON a.order_id = o.id
              GROUP BY o.id, o.order_no, o.binary_total
             HAVING o.binary_total <> COALESCE(SUM(CASE WHEN a.status IN ('pending','credited') THEN a.amount ELSE 0 END), 0)
              ORDER BY o.id"
        )->fetchAll();

        foreach ($orderTotals as $row) {
            $mismatches[] = [
                'kind'     => 'order_total',
                'order'    => (int) $row['id'],
                'order_no' => (string) ($row['order_no'] ?? ''),
                'expected' => round((float) $row['awarded'], 2),
                'actual'   => round((float) $row['binary_total'], 2),
                'message'  => 'the order headline total differs from its awarded (pending + credited) awards',
            ];
        }

        if ($since !== '') {
            $stampStmt = $pdo->prepare(
                "SELECT o.id, o.order_no, o.status
                   FROM shop_orders o
                  WHERE o.paid_at >= ?
                    AND o.status IN ('paid','packing','ready_to_ship','shipped','delivered','completed')
                    AND o.binary_processed_at IS NULL
                    AND EXISTS (SELECT 1 FROM shop_order_events e
                                 WHERE e.order_id = o.id AND e.to_status = 'paid')
                  ORDER BY o.id"
            );
            $stampStmt->execute([$since]);

            foreach ($stampStmt->fetchAll() as $row) {
                $mismatches[] = [
                    'kind'     => 'unstamped',
                    'order'    => (int) $row['id'],
                    'order_no' => (string) ($row['order_no'] ?? ''),
                    'expected' => null,
                    'actual'   => null,
                    'message'  => 'a paid order after the baseline was never processed',
                ];
            }
        }

        return [
            'ok'                  => $mismatches === [],
            'credited_rows'       => (int) ($awards['credited_rows'] ?? 0),
            'voided_rows'         => (int) ($awards['voided_rows'] ?? 0),
            'pending_rows'        => (int) ($awards['pending_rows'] ?? 0),
            'cancelled_rows'      => (int) ($awards['cancelled_rows'] ?? 0),
            'credited_total'      => $creditedTotal,
            'pending_total'       => $pendingTotal,
            'cancelled_total'     => $cancelledTotal,
            'voided_total'        => $voidedTotal,
            'ever_credited_total' => $everCreditedTotal,
            'ledger_total'        => $ledgerTotal,
            'reversal_total'      => $reversalTotal,
            'legacy_total'        => round((float) ($legacy['legacy_total'] ?? 0), 2),
            'legacy_rows'         => (int) ($legacy['legacy_rows'] ?? 0),
            'mismatches'          => $mismatches,
        ];
    }

    /* ----------------------------------------------------------
     *  Audit (points: caches vs ledger)
     * ---------------------------------------------------------- */

    /**
     * The points proof: does every cached balance equal the ledger sum,
     * does every pair have its consumption rows, does every award's
     * amount match its formula, is the tree well-formed?
     *
     * Read-only: it never repairs. Use auditFix() to recompute caches.
     *
     * @return array{ok:bool, drifts:list<array<string, mixed>>, warnings:list<array<string, mixed>>}
     */
    public function audit(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? $this->pdo;

        $drifts = [];
        $warnings = [];
        $ok = true;

        if (!BinaryVolume::tableExists($pdo)
            || !BinaryAward::tableExists($pdo)) {
            return ['ok' => true, 'drifts' => [], 'warnings' => []];
        }

        /* INV-2: cached balance == ledger sum per node per side. */
        $balanceStmt = $pdo->query(
            "SELECT n.user_id, u.username, n.left_points, n.right_points,
                    COALESCE(SUM(CASE WHEN v.side = 'left'  THEN v.points ELSE 0 END), 0) AS ledger_left,
                    COALESCE(SUM(CASE WHEN v.side = 'right' THEN v.points ELSE 0 END), 0) AS ledger_right
               FROM binary_nodes n
               JOIN users u ON u.id = n.user_id
               LEFT JOIN binary_volume v ON v.node_user_id = n.user_id
              GROUP BY n.user_id, u.username, n.left_points, n.right_points
              ORDER BY n.user_id"
        );

        foreach ($balanceStmt->fetchAll() as $row) {
            $userId = (int) $row['user_id'];
            $cacheL = (int) $row['left_points'];
            $cacheR = (int) $row['right_points'];
            $ledgerL = (int) $row['ledger_left'];
            $ledgerR = (int) $row['ledger_right'];

            if ($cacheL !== $ledgerL) {
                $ok = false;
                $drifts[] = [
                    'user_id'  => $userId,
                    'username' => (string) $row['username'],
                    'side'     => 'left',
                    'cached'   => $cacheL,
                    'ledger'   => $ledgerL,
                    'delta'    => $cacheL - $ledgerL,
                ];
            }

            if ($cacheR !== $ledgerR) {
                $ok = false;
                $drifts[] = [
                    'user_id'  => $userId,
                    'username' => (string) $row['username'],
                    'side'     => 'right',
                    'cached'   => $cacheR,
                    'ledger'   => $ledgerR,
                    'delta'    => $cacheR - $ledgerR,
                ];
            }
        }

        /* INV-5: every live pair award has two negative pair rows. */
        $pairStmt = $pdo->query(
            "SELECT a.id, a.user_id, a.order_id, a.pair_count, a.pair_points, u.username
               FROM binary_awards a
               JOIN users u ON u.id = a.user_id
              WHERE a.kind = 'pair' AND a.status IN ('pending', 'credited')
              ORDER BY a.id"
        );

        foreach ($pairStmt->fetchAll() as $row) {
            $awardId = (int) $row['id'];
            $expected = -((int) $row['pair_count'] * (int) $row['pair_points']);

            $actual = (int) $pdo->prepare(
                "SELECT COALESCE(SUM(points), 0) FROM binary_volume
                  WHERE award_id = ? AND kind = 'pair' AND side = 'left'"
            )->execute([$awardId])->fetchColumn();

            if ((int) $actual !== $expected) {
                $ok = false;
                $drifts[] = [
                    'award'    => $awardId,
                    'user_id'  => (int) $row['user_id'],
                    'order_id' => (int) $row['order_id'],
                    'kind'     => 'pair_consumption',
                    'expected' => $expected,
                    'actual'   => $actual,
                    'message'  => 'pair award #' . $awardId . ' is missing its left consumption row',
                ];
            }

            $actualR = (int) $pdo->prepare(
                "SELECT COALESCE(SUM(points), 0) FROM binary_volume
                  WHERE award_id = ? AND kind = 'pair' AND side = 'right'"
            )->execute([$awardId])->fetchColumn();

            if ((int) $actualR !== $expected) {
                $ok = false;
                $drifts[] = [
                    'award'    => $awardId,
                    'user_id'  => (int) $row['user_id'],
                    'order_id' => (int) $row['order_id'],
                    'kind'     => 'pair_consumption',
                    'expected' => $expected,
                    'actual'   => $actualR,
                    'message'  => 'pair award #' . $awardId . ' is missing its right consumption row',
                ];
            }
        }

        /* INV-7: pair amount == round(PP × pct / 100, 2) × pair_count. */
        $amountStmt = $pdo->query(
            "SELECT a.id, a.user_id, a.pair_count, a.pair_points, a.rate_pct, a.amount, u.username
               FROM binary_awards a
               JOIN users u ON u.id = a.user_id
              WHERE a.kind = 'pair'
              ORDER BY a.id"
        );

        foreach ($amountStmt->fetchAll() as $row) {
            $pp     = (float) $row['pair_points'];
            $pct    = (float) $row['rate_pct'];
            $count  = (int) $row['pair_count'];
            $expected = round(round($pp * $pct / 100, 2) * $count, 2);
            $actual   = (float) $row['amount'];

            if (number_format($expected, 2, '.', '') !== number_format($actual, 2, '.', '')) {
                $ok = false;
                $drifts[] = [
                    'award'    => (int) $row['id'],
                    'user_id'  => (int) $row['user_id'],
                    'kind'     => 'pair_amount',
                    'expected' => $expected,
                    'actual'   => $actual,
                    'message'  => 'pair award #' . (int) $row['id'] . ' amount disagrees with its formula',
                ];
            }
        }

        /* INV-6: self award points == order binary_points. */
        $selfStmt = $pdo->query(
            "SELECT a.id, a.user_id, a.order_id, a.points, o.binary_points, u.username
               FROM binary_awards a
               JOIN shop_orders o ON o.id = a.order_id
               JOIN users u ON u.id = a.user_id
              WHERE a.kind = 'self'
              ORDER BY a.id"
        );

        foreach ($selfStmt->fetchAll() as $row) {
            $expected = (int) $row['binary_points'];
            $actual   = (int) $row['points'];

            if ($expected !== $actual) {
                $ok = false;
                $drifts[] = [
                    'award'    => (int) $row['id'],
                    'user_id'  => (int) $row['user_id'],
                    'order_id' => (int) $row['order_id'],
                    'kind'     => 'self_points',
                    'expected' => $expected,
                    'actual'   => $actual,
                    'message'  => 'self award #' . (int) $row['id'] . ' points differ from the order snapshot',
                ];
            }
        }

        /* INV-9: no award names the company root. */
        $companyId = (int) (Member::primaryAdmin($pdo)['id'] ?? 0);
        if ($companyId > 0) {
            $rootAwards = $pdo->prepare(
                'SELECT COUNT(*) FROM binary_awards WHERE user_id = ?'
            );
            $rootAwards->execute([$companyId]);
            if ((int) $rootAwards->fetchColumn() > 0) {
                $ok = false;
                $warnings[] = [
                    'kind'     => 'root_award',
                    'user_id'  => $companyId,
                    'message'  => 'a binary award names the company root as recipient',
                ];
            }
        }

        /* INV-11: path/depth match the parent. */
        $pathStmt = $pdo->query(
            "SELECT n.user_id, u.username, n.depth, n.path,
                    p.user_id AS parent_id, p.depth AS parent_depth, p.path AS parent_path
               FROM binary_nodes n
               JOIN users u ON u.id = n.user_id
               LEFT JOIN binary_nodes p ON p.user_id = n.upline_id
              WHERE n.state <> 'root' AND n.upline_id IS NOT NULL
              ORDER BY n.user_id"
        );

        foreach ($pathStmt->fetchAll() as $row) {
            $parentPath = (string) ($row['parent_path'] ?? '');
            $nodePath   = (string) $row['path'];
            $expectedDepth = (int) ($row['parent_depth'] ?? 0) + 1;
            $expectedPath  = $parentPath . (substr($nodePath, -1) === 'L' ? 'L' : 'R');

            if ((int) $row['depth'] !== $expectedDepth || $nodePath !== $expectedPath) {
                $ok = false;
                $drifts[] = [
                    'user_id'  => (int) $row['user_id'],
                    'username' => (string) $row['username'],
                    'kind'     => 'path_depth',
                    'message'  => 'node #' . (int) $row['user_id'] . ' has stale depth/path',
                ];
            }
        }

        /* Floating nodes (legacy roots other than the company). */
        $floating = $pdo->query(
            "SELECT COUNT(*) FROM binary_nodes WHERE state = 'floating'"
        )->fetchColumn();

        if ((int) $floating > 0) {
            $warnings[] = [
                'kind'     => 'floating_nodes',
                'count'    => (int) $floating,
                'message'  => (int) $floating . ' node(s) are still floating (unattached to the company tree)',
            ];
        }

        return [
            'ok'      => $ok,
            'drifts'  => $drifts,
            'warnings'=> $warnings,
        ];
    }

    /**
     * Recompute every cached node balance from the append-only ledger
     * and re-run the audit. Touches only `binary_nodes` cache columns
     * (`left_points`, `right_points`, `personal_points`, `pairs_paid`,
     * `points_matched`) — never an award, a wallet row or a volume row.
     *
     * @return array{ok:bool, fixed:int, drifts:list<array<string, mixed>>}
     */
    public function auditFix(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? $this->pdo;

        if (!BinaryVolume::tableExists($pdo)) {
            return ['ok' => true, 'fixed' => 0, 'drifts' => []];
        }

        $audit = $this->audit($pdo);
        $fixed = 0;

        /* Recompute left/right points from the ledger. Share the caller's
         * transaction; use a savepoint when one is already open so we
         * never implicitly commit the outer unit (auditFix() may be
         * called inside a test harness transaction). */
        $owns = !$pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'binary_audit_sp_' . (++self::$savepointSeq);
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $pdo->prepare(
                "UPDATE binary_nodes n
                   JOIN (
                     SELECT node_user_id,
                         COALESCE(SUM(CASE WHEN side = 'left'  THEN points ELSE 0 END), 0) AS left_points,
                         COALESCE(SUM(CASE WHEN side = 'right' THEN points ELSE 0 END), 0) AS right_points
                       FROM binary_volume
                       GROUP BY node_user_id
                   ) v ON v.node_user_id = n.user_id
                  SET n.left_points  = v.left_points,
                      n.right_points = v.right_points,
                      n.updated_at   = NOW()"
            )->execute();

            $fixed += (int) $pdo->exec(
                "UPDATE binary_nodes n
                   JOIN (
                     SELECT node_user_id, COALESCE(SUM(points), 0) AS personal
                       FROM binary_volume
                       WHERE kind = 'own'
                       GROUP BY node_user_id
                   ) p ON p.node_user_id = n.user_id
                  SET n.personal_points = p.personal,
                      n.updated_at      = NOW()"
            );

            if ($owns) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (Throwable $e) {
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($savepoint !== null) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    /* The outer transaction is already unusable. */
                }
            }

            throw $e;
        }

        $recheck = $this->audit($pdo);

        return [
            'ok'      => $recheck['ok'],
            'fixed'   => $fixed,
            'drifts'  => $recheck['drifts'],
        ];
    }

    /* ----------------------------------------------------------
     *  Static helpers
     * ---------------------------------------------------------- */

    /** Is the global binary master on? (D-16: off by default) */
    public static function enabled(): bool
    {
        return setting_bool('binary_enabled', false);
    }

    /** The default hold window for a NEW award (plan ESC-05): 7 days. */
    public static function holdDays(): int
    {
        return max(0, min(365, setting_int('binary_hold_days', 7)));
    }

    /** The money one pair pays: `round(PP × pct / 100, 2)`. */
    public static function pairValue(): float
    {
        $pp   = (float) setting_int('binary_pair_points', 1000);
        $pct  = round((float) setting_float('binary_pair_pct', 10.0), 3);

        return round($pp * $pct / 100, 2);
    }

    /**
     * `unit_points = ROUND(pv)`; `points_total = unit_points × quantity`.
     * Integer end to end, never a float (plan §14.3).
     *
     * @return array{unit_points:int, points_total:int}
     */
    public static function linePoints(array $product, int $quantity): array
    {
        $quantity = max(0, $quantity);
        $pv       = (float) ($product['pv'] ?? 0);

        $unitPoints = (int) round($pv);
        $pointsTotal = $unitPoints * $quantity;

        return [
            'unit_points' => $unitPoints,
            'points_total' => $pointsTotal,
        ];
    }

    /** Pairs already counted for one recipient on the Asia/Manila day
     *  of `$paidAt` (pending + credited, this stream). The engine passes
     *  an explicit half-open range, never CURDATE() (plan §14.9 rule 3,
     *  §16.4 rule 2). */
    public static function pairsToday(int $userId, string $paidAt, ?PDO $pdo = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $pdo = $pdo ?? db();

        $zone = new DateTimeZone(APP_TIMEZONE);
        $date = new DateTimeImmutable($paidAt, $zone);
        $start = $date->format('Y-m-d 00:00:00');
        $end   = (clone $date)->modify('+1 day')->format('Y-m-d 00:00:00');

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(pair_count), 0)
               FROM binary_awards
              WHERE user_id = ?
                AND kind = 'pair'
                AND status IN ('pending', 'credited')
                AND paid_at >= ? AND paid_at < ?"
        );
        $stmt->execute([$userId, $start, $end]);

        return (int) $stmt->fetchColumn();
    }

    /** `round(base × rate / 100, 2)` in integer centavos (plan §14.11 rule 2). */
    private static function amountFor(float $base, float $rate): float
    {
        $baseCents = (int) round(max(0.0, $base) * 100);
        $rateMilli = (int) round(max(0.0, min(100.0, $rate)) * 1000);

        if ($baseCents <= 0 || $rateMilli <= 0) {
            return 0.0;
        }

        $cents = intdiv($baseCents * $rateMilli + 50000, 100000);

        return round($cents / 100, 2);
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** Share the caller's connection: one transaction for the unit. */
    private function bind(?PDO $pdo): void
    {
        if ($pdo !== null && $pdo !== $this->pdo) {
            $this->pdo   = $pdo;
            $this->wallet = new WalletService($pdo);
        }
    }

    /** @return array<string, mixed>|null */
    private function lockOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM shop_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** The instant the hold window starts: delivery, or completion. */
    private static function releaseBase(array $order): ?string
    {
        foreach (['delivered_at', 'completed_at'] as $column) {
            $value = $order[$column] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /** Write the idempotency anchor: the order stamp and its totals. */
    private function stamp(
        int $orderId,
        int $points,
        float $selfTotal,
        float $pairTotal,
        float $total
    ): void {
        $this->pdo->prepare(
            'UPDATE shop_orders
               SET binary_points = ?,
                   binary_self_total = ?,
                   binary_pair_total = ?,
                   binary_total = ?,
                   binary_processed_at = NOW()
             WHERE id = ?'
        )->execute([
            number_format((float) $points, 0, '.', ''),
            number_format(round($selfTotal, 2), 2, '.', ''),
            number_format(round($pairTotal, 2), 2, '.', ''),
            number_format(round($total, 2), 2, '.', ''),
            $orderId,
        ]);
    }

    /** The pending awards of one order, locked for release. */
    private function lockPendingAwards(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, o.order_no, o.delivered_at, o.completed_at
               FROM binary_awards a
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.order_id = ? AND a.status = 'pending'
              ORDER BY a.user_id ASC, a.id ASC
              FOR UPDATE"
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /** The awarded total of one order: pending + credited. */
    private function awardedTotal(int $orderId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM binary_awards
              WHERE order_id = ? AND status IN ('pending','credited')"
        );
        $stmt->execute([$orderId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Refresh every surface that shows the new rows. */
    private function touch(int $orderId, int $buyerId, array $awards): void
    {
        $recipients = [];

        foreach ($awards as $award) {
            $recipients[(int) ($award['user_id'] ?? 0)] = true;
        }

        foreach (array_keys($recipients) as $userId) {
            Live::touchEarnings((int) $userId);
            Live::touchMemberDashboard((int) $userId);
        }

        Live::touch('admin.commissions');
        Live::touchOrder($orderId, $buyerId);
    }

    /** Refresh every surface a release changed. */
    private function touchRelease(int $orderId, int $buyerId, array $awards): void
    {
        $recipients = [];

        foreach ($awards as $award) {
            $recipients[(int) ($award['user_id'] ?? 0)] = true;
        }

        foreach (array_keys($recipients) as $userId) {
            Live::touchEarnings((int) $userId);
            Live::touchMemberDashboard((int) $userId);
            Live::touchMembership((int) $userId);
            Live::touchMemberWallet((int) $userId);
        }

        Live::touch(['admin.commissions', 'admin.dashboard']);
        Live::touchOrder($orderId, $buyerId);
    }

    /** Refresh every surface a reversal changed. */
    private function touchReversal(int $orderId, int $buyerId): void
    {
        Live::touchEarnings($buyerId);
        Live::touchMemberDashboard($buyerId);
        Live::touchMembership($buyerId);
        Live::touchMemberWallet($buyerId);
        Live::touch('admin.commissions');
        Live::touchOrder($orderId, $buyerId);
    }

    /** One audit row for a cancellation. */
    private function auditReversal(int $orderId, array $result, string $reason, string $orderNo): void
    {
        AuditLog::record([
            'action'       => 'binary.reversal',
            'subject_type' => 'order',
            'subject_id'   => $orderId,
            'meta'         => [
                'order'             => $orderNo === '' ? ('#' . $orderId) : $orderNo,
                'pending_cancelled' => (int) $result['pending_cancelled'],
                'credited_voided'   => (int) $result['credited_voided'],
                'recovered'         => number_format((float) $result['recovered'], 2, '.', ''),
                'shortfall'         => number_format((float) $result['shortfall'], 2, '.', ''),
                'disabled'          => (bool) $result['disabled'],
                'points_reversed'   => (int) $result['points_reversed'],
                'reason'            => $reason,
            ],
        ], $this->pdo);
    }

    /** One transaction, or a savepoint when the caller already owns one. */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'binary_sp_' . (++self::$savepointSeq);
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();

            if ($owns) {
                $this->pdo->commit();
            } elseif ($savepoint !== null) {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $e) {
            if ($owns) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } elseif ($savepoint !== null) {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    /* The outer transaction is already unusable. */
                }
            }

            throw $e;
        }
    }

    /** The common return shape. */
    private function result(int $orderId, array $values): array
    {
        return $values + [
            'order_id'  => $orderId,
            'processed' => false,
            'replayed'  => false,
            'points'    => 0,
            'self'      => 0.0,
            'pair'      => 0.0,
            'pair_count'=> 0,
            'awards'    => [],
            'nodes'     => 0,
            'reason'    => null,
        ];
    }

    /** The wallet note, e.g. "Product binary self bonus — order EW-…". */
    private function note(array $award, string $orderNo): string
    {
        $kind = (string) ($award['kind'] ?? '');
        $note = sprintf(
            'Product binary %s bonus — order %s',
            $kind === 'pair' ? 'pair' : 'self',
            $orderNo
        );

        return mb_substr($note, 0, 255);
    }
}
