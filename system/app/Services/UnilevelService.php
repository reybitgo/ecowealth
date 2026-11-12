<?php
declare(strict_types=1);

/* ==============================================================
 *  UnilevelService — the repeat-purchase money engine (17C)
 *  (plan §12, §14; the ONLY phase that moves this stream's money)
 *
 *  One hook, two payment paths:
 *
 *      e-wallet   OrderService::placeFromCart()  → onOrderPaid()
 *      manual     PaymentService::verify()        → onOrderPaid()
 *
 *  The hook runs inside the caller's transaction (Rule 2): awards,
 *  wallet credits, the order stamp and the payment status commit or
 *  roll back together. It never opens its own transaction.
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
 *      a `skipped` row
 *    • reversal is best effort: only the withdrawable balance is
 *      clawed back; the shortfall is recorded (D-13)
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
     * Pay the ladder for one paid order. Idempotent, transaction-safe.
     *
     * @return array{
     *   order_id:int, processed:bool, replayed:bool, reason?:string,
     *   awards:list<array<string, mixed>>, credited:float, skipped:int, levels:int
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
            return $this->result($orderId, [
                'processed' => false,
                'replayed'  => true,
                'credited'  => round((float) ($order['unilevel_total'] ?? 0), 2),
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

        $period  = UnilevelCycle::periodOf((string) ($order['paid_at'] ?? ''));
        $buyerId = (int) $order['user_id'];

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
                    'reason'        => $blocked[$recipientId] ?? 'recipient not found',
                    'paid_at'       => (string) $order['paid_at'],
                ];
            }
        }

        /* Deterministic wallet lock order: ascending recipient, then
         * item, then level (plan §14.6). */
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

            $awardId = UnilevelAward::create($draft + ['status' => 'credited'], $this->pdo);

            /* Layer 4: the wallet replays on (user, 'unilevel', award
             * id) if this ever runs twice. A failure propagates: the
             * caller's rollback removes the award and the stamp. */
            $movement = $this->wallet->credit(
                (int) $draft['user_id'],
                (float) $draft['amount'],
                'unilevel',
                $awardId,
                $this->note($draft, (string) $order['order_no']),
                true
            );

            UnilevelAward::linkLedger($awardId, (int) $movement['ledger_id'], $this->pdo);
        }

        /* The headline figure is read back from the ledger, so a
         * partially retried call can never under-report the order. */
        $credited = $this->creditedTotal($orderId);
        $skipped  = $this->skippedCount($orderId);

        /* The stamp runs last: if anything above throws, the caller's
         * rollback removes the awards and the stamp, leaving the order
         * retryable (plan §14.3). */
        $this->stamp($orderId, $credited);

        $this->touch($orderId, $buyerId, $drafts);

        return $this->result($orderId, [
            'processed' => true,
            'replayed'  => false,
            'awards'    => UnilevelAward::forOrder($orderId, $this->pdo),
            'credited'  => $credited,
            'skipped'   => $skipped,
            'levels'    => count($chain),
        ]);
    }

    /* ----------------------------------------------------------
     *  Reversal
     * ---------------------------------------------------------- */

    /**
     * Best-effort clawback for a paid order that is being cancelled.
     *
     * Called inside PaymentService::cancelByAdmin()'s transaction,
     * after the wallet refund and before the status update. Void the
     * award, debit only the withdrawable balance, record the shortfall
     * — never a negative balance, never a blocked cancellation
     * (plan §14.9, D-13).
     *
     * @return array{order_id:int, disabled:bool, voided:int, recovered:float, shortfall:float}
     */
    public function reverseForOrder(int $orderId, string $reason, ?PDO $pdo = null): array
    {
        $this->bind($pdo);

        $order = Order::find($orderId, $this->pdo);

        if ($order === null) {
            throw UnilevelException::notFound();
        }

        $result = [
            'order_id'  => $orderId,
            'disabled'  => false,
            'voided'    => 0,
            'recovered' => 0.0,
            'shortfall' => 0.0,
        ];

        if (!setting_bool('unilevel_reversal_enabled', true)) {
            return ['disabled' => true] + $result;
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM unilevel_awards
              WHERE order_id = ? AND status = 'credited'
              ORDER BY user_id ASC, id ASC
              FOR UPDATE"
        );
        $stmt->execute([$orderId]);
        $awards = $stmt->fetchAll();

        if ($awards === []) {
            return $result;
        }

        $orderNo    = (string) ($order['order_no'] ?? ('#' . $orderId));
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

        /* Keep the order's headline figure in step with the ledger
         * (plan §13.7 #4, §14.9). */
        UnilevelAward::recomputeOrderTotal($orderId, $this->pdo);

        AuditLog::record([
            'action'       => 'unilevel.reversal',
            'subject_type' => 'order',
            'subject_id'   => $orderId,
            'meta'         => [
                'order'     => $orderNo,
                'awards'    => array_map(static fn (array $a): int => (int) $a['id'], $awards),
                'voided'    => $voided,
                'recovered' => round($recovered, 2),
                'shortfall' => round($shortfall, 2),
                'reason'    => $reason,
            ],
        ], $this->pdo);

        foreach (array_keys($recipients) as $userId) {
            Live::touchEarnings((int) $userId);
        }

        Live::touch(['admin.commissions', 'admin.dashboard']);
        Live::touchOrder($orderId, (int) $order['user_id']);

        return [
            'order_id'  => $orderId,
            'disabled'  => false,
            'voided'    => $voided,
            'recovered' => round($recovered, 2),
            'shortfall' => round($shortfall, 2),
        ];
    }

    /* ----------------------------------------------------------
     *  Previews (17E)
     * ---------------------------------------------------------- */

    /**
     * The ladder as it could pay on the product's current rates. No
     * writes and no chain/Active evaluation — the copy says "up to",
     * never "your sponsor will receive" (plan §14.10).
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
                'recipient' => $level === 1 ? 'Your sponsor' : 'Upline ' . $level,
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
     *   orphan ledger  a `unilevel`/`unilevel_reversal` row with no award
     *   drift          a movement whose amount differs from its award
     *   order total    `shop_orders.unilevel_total` against the order's
     *                  still-credited awards
     *   stamp          a paid order after the baseline with a `paid`
     *                  event and no `unilevel_processed_at`
     *   legacy         movements before `unilevel_ledger_since` are
     *                  reported, not failed
     *
     * @return array{
     *   ok:bool, credited_rows:int, skipped_rows:int, voided_rows:int,
     *   credited_total:float, ever_credited_total:float, ledger_total:float,
     *   reversal_total:float, legacy_total:float, legacy_rows:int,
     *   mismatches:list<array<string, mixed>>
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
            'credited_total'      => 0.0,
            'ever_credited_total' => 0.0,
            'ledger_total'        => 0.0,
            'reversal_total'      => 0.0,
            'legacy_total'        => 0.0,
            'legacy_rows'         => 0,
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
            "SELECT COUNT(CASE WHEN status = 'credited' THEN 1 END) AS credited_rows,
                    COUNT(CASE WHEN status = 'skipped'  THEN 1 END) AS skipped_rows,
                    COUNT(CASE WHEN status = 'voided'   THEN 1 END) AS voided_rows,
                    COALESCE(SUM(CASE WHEN status = 'credited' THEN amount ELSE 0 END), 0) AS credited_total,
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
                    COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0) AS credited
               FROM shop_orders o
               LEFT JOIN unilevel_awards a ON a.order_id = o.id
              GROUP BY o.id, o.order_no, o.unilevel_total
             HAVING o.unilevel_total <> COALESCE(SUM(CASE WHEN a.status = 'credited' THEN a.amount ELSE 0 END), 0)
              ORDER BY o.id"
        )->fetchAll();

        foreach ($orderTotals as $row) {
            $mismatches[] = [
                'kind'     => 'order_total',
                'order'    => (int) $row['id'],
                'order_no' => (string) $row['order_no'],
                'expected' => round((float) $row['credited'], 2),
                'actual'   => round((float) $row['unilevel_total'], 2),
                'message'  => 'the order headline total differs from its credited awards',
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
            'credited_total'      => $creditedTotal,
            'ever_credited_total' => $everCreditedTotal,
            'ledger_total'        => $ledgerTotal,
            'reversal_total'      => $reversalTotal,
            'legacy_total'        => round((float) ($legacy['legacy_total'] ?? 0), 2),
            'legacy_rows'         => (int) ($legacy['legacy_rows'] ?? 0),
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

    private function creditedTotal(int $orderId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM unilevel_awards
              WHERE order_id = ? AND status = 'credited'"
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
        }

        Live::touchOrder($orderId, $buyerId);
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
