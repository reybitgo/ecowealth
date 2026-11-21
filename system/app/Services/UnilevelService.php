<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelService — the repeat-purchase money engine (17C, 18)
 *  (plan §12, §14; the ONLY phase that moves this stream's money)
 *
 *  One hook, one release job, two payment paths:
 *
 *      e-wallet   OrderService::placeFromCart()  → onOrderPaid()
 *      manual     PaymentService::verify()        → onOrderPaid()
 *      release    bin/unilevel_release.php        → releaseDue()
 *
 *  The hook runs inside the caller's transaction (Rule 2): awards,
 *  the order stamp and the payment status commit or roll back
 *  together. It never opens its own transaction.
 *
 *  For every paid order:
 *
 *      base   = the line's placement snapshot (or the product for a
 *               pre-17A line), via ProductUnilevel::baseFor()
 *      chain  = CommissionService::sponsorChain(), level 1 = direct
 *      levels = 1 … min(10, unilevel_max_levels)
 *      rate   = the product's ladder at payment time (D-06)
 *      amount = round(base × rate / 100, 2), per level, per line
 *
 *  Phase 18 changes *when* the money moves, never *what* is earned:
 *  the award is written as `pending` at payment with a snapshotted
 *  `hold_days`, and the wallet credit happens at release, after the
 *  order is delivered and the return window has closed (plan ESC-02,
 *  ESC-03). No wallet movement, no ledger row and no shortfall exists
 *  while an award is pending.
 *
 *  Rules this service owns:
 *
 *    • the buyer is never a recipient (D-09) — no row, no lookup
 *    • no compression: a skipped level keeps its number; the next
 *      upline pays at its own rate (D-07)
 *    • a zero rate writes no row and consumes the level
 *    • a zero amount (zero base) writes no row, never `0.00`
 *    • both masters must be on; the order is still stamped when off
 *    • idempotency at four layers (plan §14.4): the order stamp, the
 *      per-item pre-check, the unique key and the wallet's own replay
 *    • wallet locks are taken in ascending recipient order (§14.6)
 *    • a wallet failure propagates — never swallowed, never hidden as
 *      a `skipped` row; a release failure rolls the whole order back
 *    • cancellation ALWAYS cancels pending awards, whatever the
 *      reversal switch says (ESC-06); the switch only governs awards
 *      that were already credited (ESC-07)
 *    • a credited reversal is best effort: only the withdrawable
 *      balance is clawed back; the shortfall is recorded (D-13)
 *    • no backfill: the hook runs on the transition only (D-16)
 *
 *  This file also ships the preview API (quoteLine / quoteCart) and
 *  the finance reconciliation used by bin/wallet_reconcile.php and the
 *  reset integrity pack. No controller and no view changes: the wallet
 *  statements already render the two new ref types.
 * ============================================================== */

final class UnilevelService
{
    private PDO $pdo;
    private WalletService $wallet;
    private CommissionService $commissions;

    /** Monotonic suffix for the per-call savepoint names. */
    private static int $savepointSeq = 0;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo         = $pdo ?? db();
        $this->wallet      = new WalletService($this->pdo);
        $this->commissions = new CommissionService($this->pdo);
    }

    /* ----------------------------------------------------------
     *  The one hook
     * ---------------------------------------------------------- */

    /**
     * Record the ladder for one paid order. Idempotent, transaction-safe.
     *
     * Phase 18: this writes `pending` awards and moves no money. The
     * wallet is credited by releaseForOrder()/releaseDue() after the
     * order is delivered and the snapshotted hold window has elapsed.
     *
     * @return array{
     *   order_id:int, processed:bool, replayed:bool, reason?:string,
     *   awards:list<array<string, mixed>>, credited:float, awarded:float,
     *   pending:float, skipped:int, levels:int
     * }
     */
    public function onOrderPaid(int $orderId, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        $order = $this->lockOrder($orderId);

        if ($order === null) {
            throw UnilevelException::notFound();
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
        if (($order['unilevel_processed_at'] ?? null) !== null) {
            $awarded = round((float) ($order['unilevel_total'] ?? 0), 2);

            return $this->result($orderId, [
                'processed' => false,
                'replayed'  => true,
                'credited'  => $awarded,
                'awarded'   => $awarded,
                'pending'   => $this->awardedTotal($orderId),
            ]);
        }

        $items = Order::items($orderId, $this->pdo);

        /* A kill switch still stamps the order: "decided, nothing due"
         * is auditable and a later re-verification cannot pay it
         * (plan §14.3 Rule 4). */
        if (!self::enabled() || $items === []) {
            $this->stamp($orderId, 0.0);

            error_log(sprintf(
                '[ecowealth] order #%d: repeat-purchase %s — stamped as processed with no award',
                $orderId,
                self::enabled() ? 'has no items' : 'stream is disabled'
            ));

            return $this->result($orderId, [
                'processed' => true,
                'replayed'  => false,
                'reason'    => self::enabled() ? 'no items' : 'disabled',
            ]);
        }

        /* One hold window for the whole order, snapshotted on every row
         * it creates (plan ESC-04). A later setting change moves only
         * future awards. */
        $holdDays = self::holdDays();
        $period   = UnilevelCycle::periodOf((string) ($order['paid_at'] ?? ''));
        $buyerId  = (int) $order['user_id'];

        /* One chain for the whole order: every line has the same buyer
         * (plan §12.5). The walk itself is CommissionService's, so the
         * cycle guard and depth cap are the already-tested ones. */
        $maxLevels = max(1, min(ProductUnilevel::LEVEL_MAX, setting_int('unilevel_max_levels', 10)));
        $chain     = $this->commissions->sponsorChain($buyerId, $maxLevels, $this->pdo);

        $productIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['product_id'],
            $items
        )));

        $products = Product::rowsFor($productIds, $this->pdo);
        $ladders  = ProductUnilevel::levelsMap($productIds, $this->pdo);
        $blocked  = $this->eligibilityMap($chain, $period);

        $drafts = [];

        foreach ($items as $item) {
            $product = $products[(int) $item['product_id']] ?? null;

            if ($product === null) {
                error_log(sprintf(
                    '[ecowealth] order #%d: product #%d is missing — its line pays no repeat-purchase bonus',
                    $orderId,
                    (int) $item['product_id']
                ));
                continue;
            }

            if ((int) $product['unilevel_enabled'] !== 1) {
                continue;
            }

            /* The base is frozen at placement; only a line created
             * before migration 020 falls back to the live product
             * (base_source records which happened). */
            $baseSource = ($item['base_total'] ?? null) !== null ? 'line' : 'product';
            $base       = $baseSource === 'line'
                ? round((float) $item['base_total'], 2)
                : (float) ProductUnilevel::baseFor($product, (int) $item['quantity'])['total'];

            if ($base <= 0) {
                continue;
            }

            $ladder = $ladders[(int) $product['id']]
                ?? array_fill(ProductUnilevel::LEVEL_MIN, ProductUnilevel::LEVEL_MAX, 0.0);

            $limit = min(count($chain), $maxLevels);

            for ($level = ProductUnilevel::LEVEL_MIN; $level <= $limit; $level++) {
                $rate = round((float) ($ladder[$level] ?? 0), 3);

                /* Zero means "no row, level consumed" (D-07). */
                if ($rate <= 0) {
                    continue;
                }

                $hop = $chain[$level - 1];
                $recipientId = (int) $hop['member']['id'];

                /* The buyer is never a recipient (D-09). The chain walk
                 * already stops before a self-cycle; this is the belt. */
                if ($recipientId === $buyerId) {
                    continue;
                }

                $amount = self::amountFor($base, $rate);

                if ($amount <= 0) {
                    continue;
                }

                $drafts[] = [
                    'order_id'      => $orderId,
                    'order_item_id' => (int) $item['id'],
                    'product_id'    => (int) $product['id'],
                    'product_name'  => (string) $product['name'],
                    'buyer_id'      => $buyerId,
                    'user_id'       => $recipientId,
                    'level'         => $level,
                    'base_amount'   => $base,
                    'base_source'   => $baseSource,
                    'rate_pct'      => $rate,
                    'amount'        => $amount,
                    'hold_days'     => $holdDays,
                    'reason'        => $blocked[$recipientId] ?? 'recipient not found',
                    'paid_at'       => (string) $order['paid_at'],
                ];
            }
        }

        /* Deterministic insert/lock order: ascending recipient, then
         * item, then level (plan §14.6). Phase 18 takes no wallet locks
         * at payment; the order still makes the rows deterministic and
         * mirrors the release phase's recipient order. */
        usort($drafts, static fn (array $a, array $b): int =>
            [$a['user_id'], $a['order_item_id'], $a['level']]
            <=> [$b['user_id'], $b['order_item_id'], $b['level']]);

        foreach ($drafts as $draft) {
            /* Layer 2: a retried in-transaction write returns the
             * existing decision instead of inserting a second one. */
            $existing = UnilevelAward::forEvent(
                (int) $draft['order_item_id'],
                (int) $draft['user_id'],
                (int) $draft['level'],
                $this->pdo
            );

            if ($existing !== null) {
                continue;
            }

            if ((string) $draft['reason'] !== '') {
                UnilevelAward::create($draft + ['status' => 'skipped', 'reason' => $draft['reason']], $this->pdo);
                continue;
            }

            /* Phase 18: `pending`, no wallet credit, no ledger row. The
             * release job is the only writer of the unilevel credit
             * (plan ESC-02). */
            UnilevelAward::create($draft + ['status' => 'pending'], $this->pdo);
        }

        /* The headline figure is read back from the ledger, so a
         * partially retried call can never under-report the order. It
         * is the AWARDED total (pending + credited), which is what
         * `shop_orders.unilevel_total` stores (plan ESC-08). */
        $awarded = $this->awardedTotal($orderId);
        $skipped = $this->skippedCount($orderId);

        /* The stamp runs last: if anything above throws, the caller's
         * rollback removes the awards and the stamp, leaving the order
         * retryable (plan §14.3). */
        $this->stamp($orderId, $awarded);

        $this->touch($orderId, $buyerId, $drafts);

        return $this->result($orderId, [
            'processed' => true,
            'replayed'  => false,
            'awards'    => UnilevelAward::forOrder($orderId, $this->pdo),
            'credited'  => $awarded,
            'awarded'   => $awarded,
            'pending'   => $awarded,
            'skipped'   => $skipped,
            'levels'    => count($chain),
        ]);
    }

    /* ----------------------------------------------------------
     *  Release (Phase 18)
     * ---------------------------------------------------------- */

    /**
     * Release one order's due pending awards. Idempotent.
     *
     * The wallet credit is the same movement the pre-18 code wrote at
     * payment; it simply happens after delivery + the award's hold
     * window (plan ESC-02, ESC-03). The order row is locked first, so a
     * concurrent cancellation and a release can never interleave
     * (plan §6.5).
     *
     * `$force` skips the due filter (a future support tool). It never
     * skips the delivered/completed check: an undelivered order is
     * never payable (plan §7.4).
     *
     * @return array{
     *   order_id:int, released:int, awards:list<int>, amount:float,
     *   skipped:int, reason?:string
     * }
     */
    public function releaseForOrder(
        int $orderId,
        bool $force = false,
        ?int $actorId = null,
        string $reason = '',
        ?PDO $pdo = null
    ): array {
        $this->bind($pdo);

        return $this->withTransaction(function () use ($orderId, $force, $actorId, $reason): array {
            $result = [
                'order_id' => $orderId,
                'released' => 0,
                'awards'   => [],
                'amount'   => 0.0,
                'skipped'  => 0,
            ];

            $order = $this->lockOrder($orderId);

            if ($order === null) {
                return $result + ['reason' => 'not found'];
            }

            /* A cancelled order must never pay, whatever the award rows
             * said a moment ago (plan §6.5, §8.1). */
            if (($order['cancelled_at'] ?? null) !== null) {
                return $result + ['reason' => 'cancelled'];
            }

            if (!in_array((string) $order['status'], ['delivered', 'completed'], true)) {
                return $result + ['reason' => 'not delivered'];
            }

            $base = self::releaseBase($order);

            if ($base === null) {
                return $result + ['reason' => 'missing delivery date'];
            }

            $awards = $this->lockPendingAwards($orderId);

            if ($awards === []) {
                return $result + ['reason' => 'nothing pending'];
            }

            $now    = time();
            $due    = [];
            $params = [];

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

            /* Ascending recipient, then award id: the same lock order
             * the payment hook uses, so two orders releasing the same
             * upline serialise the same way. */
            usort($due, static fn (array $a, array $b): int =>
                [(int) $a['user_id'], (int) $a['id']]
                <=> [(int) $b['user_id'], (int) $b['id']]);

            $released = 0;
            $amount   = 0.0;

            foreach ($due as $award) {
                /* The wallet credit is written first because its ledger id
                 * is what the award points at. If the award cannot be
                 * marked (a status race we did not expect under the order
                 * lock), throw: the order transaction rolls the credit
                 * back, so the wallet and the ledger never disagree. */
                $movement = $this->wallet->credit(
                    (int) $award['user_id'],
                    (float) $award['amount'],
                    'unilevel',
                    (int) $award['id'],
                    $this->note($award, (string) $award['order_no']),
                    true
                );

                if (!UnilevelAward::markCredited((int) $award['id'], (int) $movement['ledger_id'], $this->pdo)) {
                    throw new RuntimeException(sprintf(
                        'Repeat-purchase award #%d could not be marked credited; the order was rolled back.',
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
                'action'       => 'unilevel.release',
                'subject_type' => 'order',
                'subject_id'   => $orderId,
                'actor_id'     => $actorId !== null && $actorId > 0 ? $actorId : null,
                'meta'         => [
                    'order'    => (string) $order['order_no'],
                    'awards'   => $result['awards'],
                    'released' => $released,
                    'amount'   => number_format($amount, 2, '.', ''),
                    'force'    => $force,
                    'reason'   => mb_substr(trim($reason), 0, 160),
                ],
            ], $this->pdo);

            $this->touchRelease($orderId, (int) $order['user_id'], $due);

            return $result;
        });
    }

    /**
     * Release every due order, one transaction per order (plan §16.3).
     *
     * A failing order is logged and counted; the batch continues, so one
     * bad order can never hold back a day of releases.
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
        $ids   = $this->dueOrderIds($limit);

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
                $result = $this->releaseForOrder((int) $orderId, false, null, '', $this->pdo);

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
                    '[ecowealth] repeat-purchase release for order #%d failed: %s',
                    (int) $orderId,
                    $e->getMessage()
                ));
            }
        }

        return $summary;
    }

    /**
     * Order ids with at least one pending award that is due now
     * (plan Appendix B). Read-only; the dry-run and the doctor use it.
     *
     * @return list<int>
     */
    public function dueOrderIds(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));

        $stmt = $this->pdo->query(
            "SELECT a.order_id
               FROM unilevel_awards a
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
     * The award rows a dry run would release, for the `--dry-run` printout
     * (plan §17.5). Read-only.
     *
     * @return list<array<string, mixed>>
     */
    public function dueCandidates(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));

        return $this->pdo->query(
            "SELECT a.id, a.order_id, a.user_id, a.level, a.amount, a.hold_days,
                    o.order_no, o.delivered_at, o.completed_at,
                    u.username AS recipient_username,
                    DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                             INTERVAL a.hold_days DAY) AS due_at
               FROM unilevel_awards a
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
     * The operational picture the release doctor prints (plan §17.4).
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
            'stuck_shipped'   => 0,
            'cancelled_due'   => 0,
            'release_enabled' => false,
            'hold_days'       => self::holdDays(),
        ];

        if (!self::tableExists($this->pdo)) {
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
                        END), 0) AS undelivered,
                    COALESCE(SUM(CASE
                          WHEN o.status = 'shipped'
                           AND o.shipped_at IS NOT NULL
                           AND o.shipped_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                          THEN 1 ELSE 0
                        END), 0) AS stuck_shipped,
                    COALESCE(SUM(CASE
                          WHEN o.cancelled_at IS NOT NULL
                           AND o.status IN ('delivered','completed')
                          THEN 1 ELSE 0
                        END), 0) AS cancelled_due
               FROM unilevel_awards a
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.status = 'pending'"
        )->fetch() ?: [];

        $dueIds    = $this->dueOrderIds(1000);
        $dueAwards = (int) $this->pdo->query(
            "SELECT COUNT(*)
               FROM unilevel_awards a
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
            'stuck_shipped'   => (int) ($row['stuck_shipped'] ?? 0),
            /* A pending award on a cancelled order is a money bug, not a
             * delay: the doctor prints it loudly (plan §17.4). */
            'cancelled_due'   => $this->pendingOnCancelledOrders(),
            'release_enabled' => self::releaseEnabled(),
            'hold_days'       => self::holdDays(),
        ];
    }

    /** Pending awards whose order is cancelled — must always be 0. */
    public function pendingOnCancelledOrders(): int
    {
        if (!self::tableExists($this->pdo)) {
            return 0;
        }

        return (int) $this->pdo->query(
            "SELECT COUNT(*)
               FROM unilevel_awards a
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.status = 'pending' AND o.cancelled_at IS NOT NULL"
        )->fetchColumn();
    }

    /* ----------------------------------------------------------
     *  Reversal
     * ---------------------------------------------------------- */

    /**
     * The cancellation path for a paid order.
     *
     * Phase 18 order of operations (plan §8.2):
     *
     *   1. ALWAYS cancel the order's pending awards — no money moved, so
     *      this is safe and mandatory whatever the switch says. A
     *      cancelled order must never release a bonus later (ESC-06).
     *   2. Only reverse awards that were already credited, and only when
     *      `unilevel_reversal_enabled` allows it (ESC-07). That path is
     *      best effort: debit only the withdrawable balance, record the
     *      shortfall (D-13).
     *   3. Recompute the order total and audit on every path — including
     *      the switch-off path — so a cancelled order never keeps its
     *      awarded total (plan §16.4).
     *
     * Called inside PaymentService::cancelByAdmin()'s transaction, after
     * the buyer's refund and before the status update.
     *
     * @return array{
     *   order_id:int, disabled:bool, pending_cancelled:int, credited_voided:int,
     *   voided:int, recovered:float, shortfall:float
     * }
     */
    public function reverseForOrder(int $orderId, string $reason, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        $order = Order::find($orderId, $this->pdo);

        if ($order === null) {
            throw UnilevelException::notFound();
        }

        $reason = mb_substr(trim($reason), 0, 160);

        $result = [
            'order_id'          => $orderId,
            'disabled'          => false,
            'pending_cancelled' => 0,
            'credited_voided'   => 0,
            'voided'            => 0,
            'recovered'         => 0.0,
            'shortfall'         => 0.0,
        ];

        /* 1. Pending first: no money moved, so this is always safe. */
        $pending = $this->cancelPendingForOrder($orderId, $reason);
        $result['pending_cancelled'] = $pending['count'];

        /* 2. Credited reversal only when the operator allows it. */
        $credited = ['voided' => 0, 'recovered' => 0.0, 'shortfall' => 0.0, 'recipients' => []];

        if (self::reversalEnabled()) {
            $credited = $this->reverseCreditedForOrder($orderId, $reason, (string) ($order['order_no'] ?? ''));
        } else {
            $result['disabled'] = true;
        }

        $result['voided']         = (int) ($credited['voided'] ?? 0);
        $result['credited_voided'] = $result['voided'];
        $result['recovered']      = round((float) ($credited['recovered'] ?? 0.0), 2);
        $result['shortfall']      = round((float) ($credited['shortfall'] ?? 0.0), 2);

        /* 3. The order total reflects what is still pending + credited.
         * A cancelled order's pending rows are gone, so this is normally
         * 0 (plan §6.3). */
        UnilevelAward::recomputeOrderTotal($orderId, $this->pdo);

        /* 4. Audit and touch when anything actually changed — including
         * the switch-off path, where only pending rows were cancelled
         * (plan §16.4). An order with no repeat-purchase awards has
         * nothing to record and must not fake a second surface bump. */
        if ($result['pending_cancelled'] > 0 || $result['credited_voided'] > 0) {
            $this->auditReversal($orderId, $result, $reason, (string) ($order['order_no'] ?? ''));
            $this->touchReversal(
                $orderId,
                (int) $order['user_id'],
                $pending['recipients'],
                $credited['recipients'] ?? []
            );
        }

        return $result;
    }

    /**
     * Cancel every pending award of one order. No wallet movement, no
     * ledger row, no shortfall (plan §6.3).
     *
     * @return array{count:int, recipients:list<int>}
     */
    private function cancelPendingForOrder(int $orderId, string $reason): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM unilevel_awards
              WHERE order_id = ? AND status = 'pending'
              ORDER BY user_id ASC, id ASC
              FOR UPDATE"
        );
        $stmt->execute([$orderId]);
        $awards = $stmt->fetchAll();

        $reason     = $reason === '' ? 'Order cancelled before release' : $reason;
        $count      = 0;
        $recipients = [];

        foreach ($awards as $award) {
            if (UnilevelAward::cancel((int) $award['id'], $reason, $this->pdo)) {
                $count++;
                $recipients[(int) $award['user_id']] = true;
            }
        }

        return ['count' => $count, 'recipients' => array_keys($recipients)];
    }

    /**
     * Void and claw back the order's credited awards. This is the Phase
     * 17 path, unchanged except that it only selects `credited` rows —
     * pending money never reaches it (plan §6.4).
     *
     * @return array{voided:int, recovered:float, shortfall:float, recipients:list<int>}
     */
    private function reverseCreditedForOrder(int $orderId, string $reason, string $orderNo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM unilevel_awards
              WHERE order_id = ? AND status = 'credited'
              ORDER BY user_id ASC, id ASC
              FOR UPDATE"
        );
        $stmt->execute([$orderId]);
        $awards = $stmt->fetchAll();

        $out = ['voided' => 0, 'recovered' => 0.0, 'shortfall' => 0.0, 'recipients' => []];

        if ($awards === []) {
            return $out;
        }

        $orderNo    = $orderNo === '' ? ('#' . $orderId) : $orderNo;
        $reason     = mb_substr(trim($reason), 0, 160);
        $recovered  = 0.0;
        $shortfall  = 0.0;
        $voided     = 0;
        $recipients = [];

        foreach ($awards as $award) {
            $awardId = (int) $award['id'];
            $userId  = (int) $award['user_id'];
            $amount  = round((float) $award['amount'], 2);

            /* Only the withdrawable part can come back. The balance is
             * read before the debit; the debit itself re-locks the
             * account and is the authority. */
            $take = round(min($amount, $this->wallet->withdrawable($userId)), 2);
            $take = max(0.0, $take);

            $reversalLedgerId = null;
            $recoveredNow     = 0.0;

            if ($take > 0) {
                try {
                    $movement = $this->wallet->debit(
                        $userId,
                        $take,
                        'unilevel_reversal',
                        $awardId,
                        'Repeat-purchase reversal — order ' . $orderNo,
                        true
                    );

                    $reversalLedgerId = (int) $movement['ledger_id'];
                    $recoveredNow     = $take;
                } catch (Throwable $e) {
                    /* A concurrent payout took the money between the
                     * read and the lock: record the shortfall and let
                     * the cancellation proceed (D-13). */
                    error_log(sprintf(
                        '[ecowealth] repeat-purchase reversal for award #%d recovered nothing: %s',
                        $awardId,
                        $e->getMessage()
                    ));
                    $recoveredNow = 0.0;
                }
            }

            UnilevelAward::void($awardId, [
                'reversal_ledger_id' => $reversalLedgerId,
                'reversed_amount'    => $recoveredNow,
                'void_reason'        => $reason === '' ? 'Order cancelled' : $reason,
            ], $this->pdo);

            $recovered += $recoveredNow;
            $shortfall += max(0.0, round($amount - $recoveredNow, 2));
            $voided++;
            $recipients[$userId] = true;
        }

        $out['voided']      = $voided;
        $out['recovered']   = round($recovered, 2);
        $out['shortfall']   = round($shortfall, 2);
        $out['recipients']  = array_keys($recipients);

        return $out;
    }

    /**
     * One audit row for a cancellation, whatever path it took
     * (plan §6.3 step 6). `cancelled` money is never a shortfall.
     *
     * @param array<string, mixed> $result
     */
    private function auditReversal(int $orderId, array $result, string $reason, string $orderNo): void
    {
        AuditLog::record([
            'action'       => 'unilevel.reversal',
            'subject_type' => 'order',
            'subject_id'   => $orderId,
            'meta'         => [
                'order'             => $orderNo === '' ? ('#' . $orderId) : $orderNo,
                'pending_cancelled' => (int) $result['pending_cancelled'],
                'credited_voided'   => (int) $result['credited_voided'],
                'voided'            => (int) $result['voided'],
                'recovered'         => number_format((float) $result['recovered'], 2, '.', ''),
                'shortfall'         => number_format((float) $result['shortfall'], 2, '.', ''),
                'disabled'          => (bool) $result['disabled'],
                'reason'            => $reason,
            ],
        ], $this->pdo);
    }

    /**
     * Refresh every surface a cancellation changed. The pending note on
     * the wallet/payout page changes too, so the member wallet surface
     * is touched (plan §21).
     *
     * @param list<int> $pendingRecipients
     * @param list<int> $creditedRecipients
     */
    private function touchReversal(
        int $orderId,
        int $buyerId,
        array $pendingRecipients,
        array $creditedRecipients
    ): void {
        $recipients = [];

        foreach (array_merge($pendingRecipients, $creditedRecipients) as $userId) {
            $recipients[(int) $userId] = true;
        }

        foreach (array_keys($recipients) as $userId) {
            Live::touchEarnings($userId);
            Live::touchMemberDashboard($userId);
            Live::touchMembership($userId);
            Live::touchMemberWallet($userId);
        }

        Live::touch(['admin.commissions', 'admin.dashboard']);
        Live::touchOrder($orderId, $buyerId);
    }

    /** Refresh every surface a release changed (plan §21). */
    private function touchRelease(int $orderId, int $buyerId, array $awards): void
    {
        $recipients = [];

        foreach ($awards as $award) {
            $recipients[(int) $award['user_id']] = true;
        }

        foreach (array_keys($recipients) as $userId) {
            Live::touchEarnings($userId);
            Live::touchMemberDashboard($userId);
            Live::touchMembership($userId);
            Live::touchMemberWallet($userId);
        }

        Live::touch(['admin.commissions', 'admin.dashboard']);
        Live::touchOrder($orderId, $buyerId);
    }

    /* ----------------------------------------------------------
     *  Previews (17E)
     * ---------------------------------------------------------- */

    /**
     * The ladder as it could pay on the product's current rates. No
     * writes and no chain/Active evaluation — the copy says "up to",
     * never "your Team chain will receive" (plan §14.10).
     *
     * @return array<string, mixed>
     */
    public function quoteLine(int $productId, int $quantity = 1): array
    {
        $quantity = max(0, $quantity);
        $product  = Product::find($productId);

        if ($product === null) {
            return [
                'product_id'  => $productId,
                'quantity'    => $quantity,
                'base'        => 0.0,
                'base_source' => 'product',
                'levels'      => [],
                'total'       => 0.0,
                'enabled'     => false,
            ];
        }

        $base   = ProductUnilevel::baseFor($product, $quantity);
        $ladder = ProductUnilevel::levels((int) $product['id']);
        $levels = [];
        $total  = 0.0;

        for ($level = ProductUnilevel::LEVEL_MIN; $level <= ProductUnilevel::LEVEL_MAX; $level++) {
            $rate   = round((float) ($ladder[$level] ?? 0), 3);
            $amount = self::amountFor((float) $base['total'], $rate);

            $levels[] = [
                'level'     => $level,
                'rate'      => $rate,
                'amount'    => $amount,
                'recipient' => $level === 1 ? 'Your direct recruit' : 'Your Team chain',
            ];

            $total = round($total + $amount, 2);
        }

        return [
            'product_id'  => (int) $product['id'],
            'name'        => (string) $product['name'],
            'quantity'    => $quantity,
            'base'        => round((float) $base['total'], 2),
            'base_source' => (string) $base['basis'],
            'enabled'     => (int) $product['unilevel_enabled'] === 1,
            'levels'      => $levels,
            'total'       => $total,
        ];
    }

    /**
     * The same preview for a whole cart with **one** ladder query.
     *
     * @param list<array{product_id:int|string, quantity:int|string}> $lines
     * @return array{
     *   lines:list<array<string, mixed>>, levels:array<int, float>, total:float
     * }
     */
    public function quoteCart(array $lines): array
    {
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
            return ['lines' => [], 'levels' => [], 'total' => 0.0];
        }

        $products = Product::rowsFor($ids, $this->pdo);
        $ladders  = ProductUnilevel::levelsMap($ids, $this->pdo);

        $quote        = ['lines' => [], 'levels' => [], 'total' => 0.0];
        $levelTotals  = [];

        for ($level = ProductUnilevel::LEVEL_MIN; $level <= ProductUnilevel::LEVEL_MAX; $level++) {
            $levelTotals[$level] = 0.0;
        }

        foreach ($normalised as $line) {
            $product = $products[$line['product_id']] ?? null;

            if ($product === null || (int) $product['unilevel_enabled'] !== 1) {
                continue;
            }

            $base   = ProductUnilevel::baseFor($product, $line['quantity']);
            $ladder = $ladders[$line['product_id']]
                ?? array_fill(ProductUnilevel::LEVEL_MIN, ProductUnilevel::LEVEL_MAX, 0.0);

            $entry = [
                'product_id'  => $line['product_id'],
                'name'        => (string) $product['name'],
                'quantity'    => $line['quantity'],
                'base'        => round((float) $base['total'], 2),
                'base_source' => (string) $base['basis'],
                'levels'      => [],
                'total'       => 0.0,
            ];

            for ($level = ProductUnilevel::LEVEL_MIN; $level <= ProductUnilevel::LEVEL_MAX; $level++) {
                $rate   = round((float) ($ladder[$level] ?? 0), 3);
                $amount = self::amountFor((float) $base['total'], $rate);

                $entry['levels'][] = [
                    'level'  => $level,
                    'rate'   => $rate,
                    'amount' => $amount,
                ];

                $entry['total']          = round($entry['total'] + $amount, 2);
                $levelTotals[$level]     = round($levelTotals[$level] + $amount, 2);
                $quote['total']          = round($quote['total'] + $amount, 2);
            }

            $quote['lines'][] = $entry;
        }

        $quote['levels'] = $levelTotals;

        return $quote;
    }

    /* ----------------------------------------------------------
     *  Reporting
     * ---------------------------------------------------------- */

    /** @return array{count:int, credited:float, this_month:float, skipped:int, last_at:?string} */
    public function totalsFor(int $userId, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        return UnilevelAward::totalsForUser($userId, $this->pdo);
    }

    /**
     * The escrow summary every A++ page reads (plan §16.5): pending
     * total, count, next release date, how many are still undelivered
     * and whether the release switch is on.
     *
     * @return array{
     *   total:float, count:int, next_release_at:?string,
     *   undelivered:int, overdue:int, release_enabled:bool
     * }
     */
    public function pendingSummary(int $userId, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        return UnilevelAward::pendingSummaryForUser($userId, $this->pdo);
    }

    /* ----------------------------------------------------------
     *  Reconciliation (plan §14.11)
     * ---------------------------------------------------------- */

    /**
     * Finance reconciliation, same contract as
     * CommissionService::reconcile(): read-only, reports mismatches,
     * never repairs.
     *
     * The stream's ledger has two movement kinds, so the checks are:
     *
     *   credit total   every award that was ever credited (credited +
     *                  voided) against `ref_type='unilevel'`
     *   reversal total `reversed_amount` of voided awards against
     *                  `ref_type='unilevel_reversal'`
     *   missing ledger a credited/voided award with no movement
     *   stray ledger   a pending/cancelled award that HAS a movement
     *   orphan ledger  a `unilevel`/`unilevel_reversal` row with no award
     *   drift          a movement whose amount differs from its award
     *   order total    `shop_orders.unilevel_total` against the order's
     *                  pending + credited awards (plan ESC-08)
     *   pending/cancel a pending award on a cancelled order, or a
     *                  cancelled row carrying reversal money
     *   stamp          a paid order after the baseline with a `paid`
     *                  event and no `unilevel_processed_at`
     *   legacy         movements before `unilevel_ledger_since` are
     *                  reported, not failed
     *
     * `cancelled` rows have no wallet credit and no reversal, so they
     * never appear in either ledger total. Stuck pending is reported as
     * a warning, not a mismatch: a delay is not a books error.
     *
     * @return array{
     *   ok:bool, credited_rows:int, skipped_rows:int, voided_rows:int,
     *   pending_rows:int, cancelled_rows:int,
     *   credited_total:float, pending_total:float, cancelled_total:float,
     *   awarded_total:float, ever_credited_total:float, ledger_total:float,
     *   reversal_total:float, legacy_total:float, legacy_rows:int,
     *   stuck_pending:int, mismatches:list<array<string, mixed>>
     * }
     */
    public function reconcile(): array
    {
        $pdo = $this->pdo;

        $empty = [
            'ok'                  => true,
            'credited_rows'       => 0,
            'skipped_rows'        => 0,
            'voided_rows'         => 0,
            'pending_rows'        => 0,
            'cancelled_rows'      => 0,
            'credited_total'      => 0.0,
            'pending_total'       => 0.0,
            'cancelled_total'     => 0.0,
            'awarded_total'       => 0.0,
            'ever_credited_total' => 0.0,
            'ledger_total'        => 0.0,
            'reversal_total'      => 0.0,
            'legacy_total'        => 0.0,
            'legacy_rows'         => 0,
            'stuck_pending'       => 0,
            'mismatches'          => [],
        ];

        if (!self::tableExists($pdo)) {
            return $empty;
        }

        /* The baseline is read through this service's own connection: a
         * reset constructs it on the injected target, and the global
         * setting() would read the application database instead. */
        $since = '';

        try {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
            $stmt->execute(['unilevel_ledger_since']);
            $since = trim((string) ($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            error_log('[ecowealth] repeat-purchase ledger baseline unavailable: ' . $e->getMessage());
        }

        $sinceArgs = $since === '' ? [] : [$since];

        $awards = $pdo->query(
            "SELECT COUNT(CASE WHEN status = 'credited'  THEN 1 END) AS credited_rows,
                    COUNT(CASE WHEN status = 'skipped'   THEN 1 END) AS skipped_rows,
                    COUNT(CASE WHEN status = 'voided'    THEN 1 END) AS voided_rows,
                    COUNT(CASE WHEN status = 'pending'   THEN 1 END) AS pending_rows,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled_rows,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_total,
                    COALESCE(SUM(CASE WHEN status = 'cancelled' THEN amount ELSE 0 END), 0) AS cancelled_total,
                    COALESCE(SUM(CASE WHEN status IN ('credited','voided') THEN amount ELSE 0 END), 0) AS ever_credited_total,
                    COALESCE(SUM(CASE WHEN status = 'voided' THEN reversed_amount ELSE 0 END), 0) AS reversed_total
               FROM unilevel_awards"
        )->fetch() ?: [];

        $ledgerStmt = $pdo->prepare(
            "SELECT COUNT(*) AS ledger_rows, COALESCE(SUM(amount), 0) AS ledger_total
               FROM ewallet_ledger
              WHERE ref_type = 'unilevel'" . ($since === '' ? '' : ' AND created_at >= ?')
        );
        $ledgerStmt->execute($sinceArgs);
        $ledger = $ledgerStmt->fetch() ?: [];

        $reversalStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS reversal_total
               FROM ewallet_ledger
              WHERE ref_type = 'unilevel_reversal'" . ($since === '' ? '' : ' AND created_at >= ?')
        );
        $reversalStmt->execute($sinceArgs);
        $reversalTotal = round((float) $reversalStmt->fetchColumn(), 2);

        $legacy = ['legacy_rows' => 0, 'legacy_total' => 0.0];

        if ($since !== '') {
            $legacyStmt = $pdo->prepare(
                "SELECT COUNT(*) AS legacy_rows, COALESCE(SUM(amount), 0) AS legacy_total
                   FROM ewallet_ledger
                  WHERE ref_type IN ('unilevel', 'unilevel_reversal') AND created_at < ?"
            );
            $legacyStmt->execute([$since]);
            $legacy = $legacyStmt->fetch() ?: $legacy;
        }

        $creditedTotal     = round((float) ($awards['credited_total'] ?? 0), 2);
        $pendingTotal      = round((float) ($awards['pending_total'] ?? 0), 2);
        $cancelledTotal    = round((float) ($awards['cancelled_total'] ?? 0), 2);
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
                'message'  => 'awarded repeat-purchase money differs from the unilevel ledger total',
            ];
        }

        if (!$sameMoney($reversedTotal, $reversalTotal)) {
            $mismatches[] = [
                'kind'     => 'reversal_total',
                'expected' => $reversedTotal,
                'actual'   => $reversalTotal,
                'message'  => 'recorded reversals differ from the unilevel_reversal ledger total',
            ];
        }

        $missingStmt = $pdo->query(
            "SELECT a.id, a.amount, a.ledger_id, u.username
               FROM unilevel_awards a
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
                'message'  => 'a credited repeat-purchase award has no wallet movement',
            ];
        }

        /* Phase 18: pending and cancelled rows never have a wallet
         * movement — that is the whole point of the escrow (plan §13.2). */
        $strayStmt = $pdo->query(
            "SELECT a.id, a.status, a.amount, a.ledger_id, u.username
               FROM unilevel_awards a
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
                    . ' repeat-purchase award points at a wallet movement it must not have',
            ];
        }

        /* A cancelled order must never keep a releasable award (ESC-06). */
        $cancelledPending = $pdo->query(
            "SELECT a.id, a.amount, o.order_no
               FROM unilevel_awards a
               JOIN shop_orders o ON o.id = a.order_id
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.status = 'pending' AND o.cancelled_at IS NOT NULL
              ORDER BY a.id"
        );

        foreach ($cancelledPending->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'pending_on_cancelled',
                'award'    => (int) $row['id'],
                'order_no' => (string) $row['order_no'],
                'expected' => 0.0,
                'actual'   => (float) $row['amount'],
                'message'  => 'a cancelled order still has a pending repeat-purchase award',
            ];
        }

        /* A cancelled row was never paid: it cannot carry recovery money. */
        $cancelledMoney = $pdo->query(
            "SELECT a.id, a.reversed_amount, a.reversal_ledger_id
               FROM unilevel_awards a
              WHERE a.status = 'cancelled'
                AND (a.reversed_amount <> 0 OR a.reversal_ledger_id IS NOT NULL)
              ORDER BY a.id"
        );

        foreach ($cancelledMoney->fetchAll() as $row) {
            $mismatches[] = [
                'kind'     => 'cancelled_with_reversal',
                'award'    => (int) $row['id'],
                'expected' => 0.0,
                'actual'   => (float) $row['reversed_amount'],
                'message'  => 'a cancelled repeat-purchase award recorded a reversal; it was never paid',
            ];
        }

        /* Stuck pending is a warning, not a books error: a delay in the
         * release job must not fail a wallet reconciliation (plan §16.6). */
        $stuckPending = 0;

        try {
            $stuckPending = (int) $pdo->query(
                "SELECT COUNT(*)
                   FROM unilevel_awards a
                   JOIN shop_orders o ON o.id = a.order_id
                  WHERE a.status = 'pending'
                    AND o.status IN ('delivered','completed')
                    AND o.cancelled_at IS NULL
                    AND COALESCE(o.delivered_at, o.completed_at) IS NOT NULL
                    AND DATE_ADD(COALESCE(o.delivered_at, o.completed_at),
                                 INTERVAL a.hold_days DAY) < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
            )->fetchColumn();
        } catch (Throwable $e) {
            /* A schema without the Phase 18 columns (a partially migrated
             * scratch database) must not break reconciliation. */
            error_log('[ecowealth] stuck-pending check unavailable: ' . $e->getMessage());
        }

        $orphanStmt = $pdo->prepare(
            "SELECT l.id, l.ref_type, l.user_id, l.ref_id, l.amount, u.username
               FROM ewallet_ledger l
               LEFT JOIN users u ON u.id = l.user_id
               LEFT JOIN unilevel_awards a ON a.id = l.ref_id
              WHERE l.ref_type IN ('unilevel', 'unilevel_reversal')
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
               FROM unilevel_awards a
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
                'message'  => 'the repeat-purchase award and its ledger row disagree',
            ];
        }

        $reversalDrift = $pdo->query(
            "SELECT a.id, a.reversed_amount AS award_amount, l.amount AS ledger_amount, u.username
               FROM unilevel_awards a
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
            "SELECT o.id, o.order_no, o.unilevel_total,
                    COALESCE(SUM(CASE WHEN a.status IN ('pending','credited') THEN a.amount ELSE 0 END), 0) AS awarded
               FROM shop_orders o
               LEFT JOIN unilevel_awards a ON a.order_id = o.id
              GROUP BY o.id, o.order_no, o.unilevel_total
             HAVING o.unilevel_total <> COALESCE(SUM(CASE WHEN a.status IN ('pending','credited') THEN a.amount ELSE 0 END), 0)
              ORDER BY o.id"
        )->fetchAll();

        foreach ($orderTotals as $row) {
            $mismatches[] = [
                'kind'     => 'order_total',
                'order'    => (int) $row['id'],
                'order_no' => (string) $row['order_no'],
                'expected' => round((float) $row['awarded'], 2),
                'actual'   => round((float) $row['unilevel_total'], 2),
                'message'  => 'the order headline total differs from its awarded (pending + credited) awards',
            ];
        }

        if ($since !== '') {
            $stampStmt = $pdo->prepare(
                "SELECT o.id, o.order_no, o.status
                   FROM shop_orders o
                  WHERE o.paid_at >= ?
                    AND o.status IN ('paid','packing','ready_to_ship','shipped','delivered','completed')
                    AND o.unilevel_processed_at IS NULL
                    AND EXISTS (SELECT 1 FROM shop_order_events e
                                 WHERE e.order_id = o.id AND e.to_status = 'paid')
                  ORDER BY o.id"
            );
            $stampStmt->execute([$since]);

            foreach ($stampStmt->fetchAll() as $row) {
                $mismatches[] = [
                    'kind'     => 'unstamped',
                    'order'    => (int) $row['id'],
                    'order_no' => (string) $row['order_no'],
                    'expected' => null,
                    'actual'   => null,
                    'message'  => 'a paid order after the baseline was never processed',
                ];
            }
        }

        return [
            'ok'                  => $mismatches === [],
            'credited_rows'       => (int) ($awards['credited_rows'] ?? 0),
            'skipped_rows'        => (int) ($awards['skipped_rows'] ?? 0),
            'voided_rows'         => (int) ($awards['voided_rows'] ?? 0),
            'pending_rows'        => (int) ($awards['pending_rows'] ?? 0),
            'cancelled_rows'      => (int) ($awards['cancelled_rows'] ?? 0),
            'credited_total'      => $creditedTotal,
            'pending_total'       => $pendingTotal,
            'cancelled_total'     => $cancelledTotal,
            'awarded_total'       => round($creditedTotal + $pendingTotal, 2),
            'ever_credited_total' => $everCreditedTotal,
            'ledger_total'        => $ledgerTotal,
            'reversal_total'      => $reversalTotal,
            'legacy_total'        => round((float) ($legacy['legacy_total'] ?? 0), 2),
            'legacy_rows'         => (int) ($legacy['legacy_rows'] ?? 0),
            'stuck_pending'       => $stuckPending,
            'mismatches'          => $mismatches,
        ];
    }

    /* ----------------------------------------------------------
     *  Static helpers
     * ---------------------------------------------------------- */

    /** Is the global repeat-purchase master on? (D-11: off by default) */
    public static function enabled(): bool
    {
        return setting_bool('unilevel_enabled', false);
    }

    /**
     * The default hold window for a NEW award (plan ESC-05): 7 days,
     * clamped to the column's 0–365 range. Each award snapshots its own
     * value at payment, so a later change moves only future awards.
     */
    public static function holdDays(): int
    {
        return max(0, min(365, setting_int('unilevel_hold_days', 7)));
    }

    /**
     * Is the release job allowed to run? This is independent of the
     * global master: turning the offer off must not strand bonuses that
     * were already earned (plan ESC-12).
     */
    public static function releaseEnabled(): bool
    {
        return setting_bool('unilevel_release_enabled', true);
    }

    /**
     * Should a cancelled order claw back awards that were already
     * credited? Pending awards are cancelled whatever this says
     * (plan ESC-06/ESC-07).
     */
    public static function reversalEnabled(): bool
    {
        return setting_bool('unilevel_reversal_enabled', true);
    }

    /**
     * One level's money: `round(base × percent / 100, 2)` computed in
     * integer centavos so float drift cannot move a centavo
     * (plan §12.8). Both factors are non-negative, so half-away-from-
     * zero is half-up.
     */
    public static function amountFor(float $base, float $rate): float
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
            $this->pdo         = $pdo;
            $this->wallet      = new WalletService($pdo);
            $this->commissions = new CommissionService($pdo);
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

    /**
     * Resolve the recipient gate once for the whole chain: company
     * account, account enabled, membership, then the monthly Active
     * rule (unless an operator relaxed it). No queries per level.
     *
     * @param list<array{level:int, member:array<string, mixed>}> $chain
     * @return array<int, string> user id => reason ('' = eligible)
     */
    private function eligibilityMap(array $chain, string $period): array
    {
        $ids = [];
        $members = [];

        foreach ($chain as $hop) {
            $id = (int) $hop['member']['id'];
            $ids[] = $id;
            $members[$id] = $hop['member'];
        }

        $companyId = Member::primaryAdmin($this->pdo)['id'] ?? 0;
        $companyId = (int) $companyId;

        $requiresActive = setting_bool('unilevel_requires_active_upline', true);
        $active         = $requiresActive
            ? UnilevelCycle::activeMap($ids, $period, $this->pdo)
            : [];
        $previous       = UnilevelCycle::previous($period);

        $reasons = [];

        foreach ($members as $id => $member) {
            $reason = '';

            if ($companyId > 0 && $id === $companyId) {
                $reason = 'company account is not paid';
            } elseif ((int) ($member['is_active'] ?? 0) !== 1) {
                $reason = 'recipient account is disabled';
            } else {
                $status = (string) ($member['member_status'] ?? '');

                if ($status !== 'active') {
                    $reason = 'recipient membership is ' . ($status === '' ? 'unknown' : $status);
                } elseif ($requiresActive && !($active[$id] ?? true)) {
                    $reason = 'recipient is not Active for ' . $previous;
                }
            }

            $reasons[$id] = $reason;
        }

        return $reasons;
    }

    /** The ledger note, e.g. "Repeat-purchase bonus — Coffee (level 1) — order EW-…". */
    private function note(array $draft, string $orderNo): string
    {
        $note = sprintf(
            'Repeat-purchase bonus — %s (level %d) — order %s',
            (string) ($draft['product_name'] ?? 'product'),
            (int) $draft['level'],
            $orderNo
        );

        return mb_substr($note, 0, 255);
    }

    /** Write the idempotency anchor: the order stamp and its total. */
    private function stamp(int $orderId, float $total): void
    {
        $this->pdo->prepare(
            'UPDATE shop_orders
                SET unilevel_processed_at = NOW(), unilevel_total = ?
              WHERE id = ?'
        )->execute([number_format(round($total, 2), 2, '.', ''), $orderId]);
    }

    /**
     * The pending awards of one order, with the product name and order
     * number the wallet note needs, locked for the release. Rows come
     * back in the wallet-lock order (ascending recipient, then id).
     *
     * @return list<array<string, mixed>>
     */
    private function lockPendingAwards(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, p.name AS product_name, o.order_no, o.delivered_at, o.completed_at
               FROM unilevel_awards a
               JOIN products p    ON p.id = a.product_id
               JOIN shop_orders o ON o.id = a.order_id
              WHERE a.order_id = ? AND a.status = 'pending'
              ORDER BY a.user_id ASC, a.id ASC
              FOR UPDATE"
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /**
     * The instant the return window starts for one order: delivery, or
     * completion when an order was completed without a delivery stamp
     * (plan §7.1, ESC-03).
     *
     * @param array<string, mixed> $order
     */
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

    /** The awarded total of one order: pending + credited (ESC-08). */
    private function awardedTotal(int $orderId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM unilevel_awards
              WHERE order_id = ? AND status IN ('pending','credited')"
        );
        $stmt->execute([$orderId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    private function skippedCount(int $orderId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM unilevel_awards WHERE order_id = ? AND status = 'skipped'"
        );
        $stmt->execute([$orderId]);

        return (int) $stmt->fetchColumn();
    }

    /** Refresh every surface that shows the new rows. */
    private function touch(int $orderId, int $buyerId, array $drafts): void
    {
        Live::touch('admin.commissions');

        $recipients = [];

        foreach ($drafts as $draft) {
            $recipients[(int) $draft['user_id']] = true;
        }

        foreach (array_keys($recipients) as $userId) {
            Live::touchEarnings((int) $userId);
            /* The pending total is shown on the dashboard, the membership
             * page and the wallet/payout reminder, so those surfaces
             * refresh too (plan §16.1, §21). */
            Live::touchMemberDashboard((int) $userId);
            Live::touchMembership((int) $userId);
            Live::touchMemberWallet((int) $userId);
        }

        Live::touchOrder($orderId, $buyerId);
    }

    /**
     * One transaction, or a savepoint when the caller already owns one
     * (the same pattern WalletService uses). releaseForOrder() is the
     * first money method here that may be called on its own — the job
     * calls it per order, a test calls it inside the harness
     * transaction (plan §16.2 step 2).
     */
    private function withTransaction(callable $operation): mixed
    {
        $owns      = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'unilevel_sp_' . (++self::$savepointSeq);
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
                    /* The outer transaction is already unusable; let the
                     * original exception surface. */
                }
            }

            throw $e;
        }
    }

    /**
     * The common return shape, filled with zeroes where a branch does
     * not use a key.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function result(int $orderId, array $values): array
    {
        return $values + [
            'order_id' => $orderId,
            'processed' => false,
            'replayed' => false,
            'awards'   => [],
            'credited' => 0.0,
            'awarded'  => 0.0,
            'pending'  => 0.0,
            'skipped'  => 0,
            'levels'   => 0,
        ];
    }

    private static function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['unilevel_awards']);

        return (int) $stmt->fetchColumn() > 0;
    }
}
