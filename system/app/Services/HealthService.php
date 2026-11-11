<?php
declare(strict_types=1);

/* ==============================================================
 *  HealthService — the admin "health" widget's data (plan §6.8,
 *  §7 Phase 15)
 *
 *  Aggregates the four service reconciliations (wallets,
 *  commissions, transfers, payouts) and adds the invariants the
 *  plan lists but no single service owns:
 *
 *    4. SUM(shop_orders.total) = SUM(shop_order_items.line_total)
 *    5. available_stock >= 0 for every product
 *    6. no commission row without its activation; no approved
 *       activation left unprocessed
 *
 *  Read-only: nothing here repairs anything. A mismatch means money
 *  or stock was written outside its service and needs a human.
 *
 *  Each section is independently guarded, so one failing query never
 *  hides the other checks from the operator.
 * ============================================================== */

final class HealthService
{
    /** Largest number of example rows a section lists. */
    public const DETAIL_LIMIT = 5;

    /**
     * Run every check.
     *
     * @return array{
     *   ok: bool,
     *   checked_at: string,
     *   totals: array{checks:int, failing:int},
     *   sections: list<array{
     *     key:string, label:string, ok:bool, summary:string,
     *     details:list<string>, href:string
     *   }>
     * }
     */
    public function checks(): array
    {
        $sections = [
            $this->wallets(),
            $this->commissions(),
            $this->awards(),
            $this->transfers(),
            $this->payouts(),
            $this->orders(),
            $this->stock(),
        ];

        $failing = 0;

        foreach ($sections as $section) {
            if (!$section['ok']) {
                $failing++;
            }
        }

        return [
            'ok'         => $failing === 0,
            'checked_at' => date('Y-m-d H:i:s'),
            'totals'     => ['checks' => count($sections), 'failing' => $failing],
            'sections'   => $sections,
        ];
    }

    /** Just the pass/fail flag, for a dashboard badge. */
    public function ok(): bool
    {
        try {
            return $this->checks()['ok'];
        } catch (Throwable $e) {
            error_log('[ecowealth] health check failed: ' . $e->getMessage());

            return false;
        }
    }

    /* ----------------------------------------------------------
     *  Money services
     * ---------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function wallets(): array
    {
        try {
            $result = (new WalletService())->reconcile();
            $problems = count($result['mismatches'] ?? []) + count($result['violations'] ?? []);
            $details  = [];

            foreach (array_slice($result['mismatches'] ?? [], 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf(
                    'Account #%d @%s: ledger %s vs wallet %s',
                    (int) ($row['user_id'] ?? 0),
                    (string) ($row['username'] ?? '?'),
                    self::money($row['expected'] ?? null),
                    self::money($row['actual'] ?? null)
                );
            }

            foreach (array_slice($result['violations'] ?? [], 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf(
                    'Account #%d @%s: %s (total %s / withdrawable %s)',
                    (int) ($row['user_id'] ?? 0),
                    (string) ($row['username'] ?? '?'),
                    (string) ($row['reason'] ?? 'invariant broken'),
                    self::money($row['balance'] ?? null),
                    self::money($row['withdrawable'] ?? null)
                );
            }

            return $this->section(
                'wallets',
                'Wallet balances',
                $problems === 0,
                $problems === 0
                    ? sprintf('%d accounts reconcile against the ledger on both buckets', (int) ($result['checked'] ?? 0))
                    : sprintf('%d wallet problem(s) across %d accounts', $problems, (int) ($result['checked'] ?? 0)),
                $details,
                'admin/wallets'
            );
        } catch (Throwable $e) {
            return $this->failed('wallets', 'Wallet balances', $e);
        }
    }

    /** @return array<string, mixed> */
    private function commissions(): array
    {
        try {
            $result  = (new CommissionService())->reconcile();
            $problems = count($result['mismatches'] ?? []);
            $details  = [];

            foreach (array_slice($result['mismatches'] ?? [], 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf('[%s] %s', (string) ($row['kind'] ?? 'mismatch'), (string) ($row['message'] ?? ''));
            }

            return $this->section(
                'commissions',
                'Commission ledger',
                $problems === 0,
                $problems === 0
                    ? sprintf(
                        '%d credited award(s) (%s) match the ledger; %d skipped',
                        (int) ($result['credited_rows'] ?? 0),
                        self::money($result['credited_total'] ?? null),
                        (int) ($result['skipped_rows'] ?? 0)
                    )
                    : sprintf('%d commission problem(s)', $problems),
                $details,
                'admin/commissions'
            );
        } catch (Throwable $e) {
            return $this->failed('commissions', 'Commission ledger', $e);
        }
    }

    /** @return array<string, mixed> */
    private function transfers(): array
    {
        try {
            $result   = (new TransferService())->reconcile();
            $problems = count($result['mismatches'] ?? []);
            $details  = [];

            foreach (array_slice($result['mismatches'] ?? [], 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf('Transfer #%d: %s', (int) ($row['transfer_id'] ?? 0), (string) ($row['message'] ?? ''));
            }

            if (($result['orphans'] ?? 0) > 0) {
                $details[] = sprintf('%d orphan transfer ledger leg(s)', (int) $result['orphans']);
            }

            return $this->section(
                'transfers',
                'Member transfers',
                $problems === 0,
                $problems === 0
                    ? sprintf('%d transfer(s) carry their sender, recipient and fee legs', (int) ($result['checked'] ?? 0))
                    : sprintf('%d transfer problem(s)', $problems),
                $details,
                'admin/wallets?tab=transfers'
            );
        } catch (Throwable $e) {
            return $this->failed('transfers', 'Member transfers', $e);
        }
    }

    /** @return array<string, mixed> */
    private function payouts(): array
    {
        try {
            $result   = (new PayoutService())->reconcile();
            $problems = count($result['mismatches'] ?? []);
            $details  = [];

            foreach (array_slice($result['mismatches'] ?? [], 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf('Payout #%d: %s', (int) ($row['payout_id'] ?? 0), (string) ($row['message'] ?? ''));
            }

            if (($result['orphans'] ?? 0) > 0) {
                $details[] = sprintf('%d orphan payout ledger leg(s)', (int) $result['orphans']);
            }

            return $this->section(
                'payouts',
                'Withdrawal holds',
                $problems === 0,
                $problems === 0
                    ? sprintf(
                        '%d request(s) checked; %s currently held by open requests',
                        (int) ($result['checked'] ?? 0),
                        self::money($result['held'] ?? null)
                    )
                    : sprintf('%d payout problem(s)', $problems),
                $details,
                'admin/payouts'
            );
        } catch (Throwable $e) {
            return $this->failed('payouts', 'Withdrawal holds', $e);
        }
    }

    /* ----------------------------------------------------------
     *  Invariants no single service owns
     * ---------------------------------------------------------- */

    /**
     * Every order's total equals the sum of its lines (plan §6.8 #4).
     *
     * @return array<string, mixed>
     */
    private function orders(): array
    {
        try {
            $stmt = db()->query(
                'SELECT o.id, o.order_no, o.total, COALESCE(SUM(i.line_total), 0) AS items_total
                   FROM shop_orders o
                   LEFT JOIN shop_order_items i ON i.order_id = o.id
                  GROUP BY o.id, o.order_no, o.total
                 HAVING ABS(o.total - COALESCE(SUM(i.line_total), 0)) > 0.005
                  ORDER BY o.id'
            );

            $rows    = $stmt->fetchAll();
            $details = [];

            foreach (array_slice($rows, 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf(
                    'Order %s: total %s vs lines %s',
                    (string) $row['order_no'],
                    self::money($row['total']),
                    self::money($row['items_total'])
                );
            }

            $orders = (int) db()->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn();

            return $this->section(
                'orders',
                'Order totals',
                $rows === [],
                $rows === []
                    ? sprintf('%d order(s) reconcile with their line items', $orders)
                    : sprintf('%d order(s) whose total differs from their lines', count($rows)),
                $details,
                'admin/orders'
            );
        } catch (Throwable $e) {
            return $this->failed('orders', 'Order totals', $e);
        }
    }

    /**
     * No product may be oversold: held quantity must not exceed stock
     * (plan §6.8 #5, §5.1).
     *
     * @return array<string, mixed>
     */
    private function stock(): array
    {
        try {
            $stmt = db()->query(
                "SELECT p.id, p.name, p.sku, p.stock,
                        COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'completed')
                                          THEN oi.quantity ELSE 0 END), 0) AS held
                   FROM products p
                   LEFT JOIN shop_order_items oi
                     ON oi.product_id = p.id AND oi.reservation_state IN ('reserved', 'committed')
                   LEFT JOIN shop_orders o ON o.id = oi.order_id
                  GROUP BY p.id, p.name, p.sku, p.stock
                 HAVING held > p.stock
                  ORDER BY p.id"
            );

            $rows    = $stmt->fetchAll();
            $details = [];

            foreach (array_slice($rows, 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf(
                    '%s: %d on hand, %d held',
                    (string) $row['name'],
                    (int) $row['stock'],
                    (int) $row['held']
                );
            }

            $products = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();

            return $this->section(
                'stock',
                'Stock availability',
                $rows === [],
                $rows === []
                    ? sprintf('%d product(s) hold no more stock than they have', $products)
                    : sprintf('%d product(s) committed beyond their stock', count($rows)),
                $details,
                'admin/products'
            );
        } catch (Throwable $e) {
            return $this->failed('stock', 'Stock availability', $e);
        }
    }

    /**
     * Commission award integrity (plan §6.8 #6): an award always
     * points at a real activation, a credited award always points at
     * its wallet movement, and an approved activation is always
     * stamped as processed.
     *
     * @return array<string, mixed>
     */
    private function awards(): array
    {
        try {
            $details = [];

            $orphans = db()->query(
                'SELECT c.id, c.user_id
                   FROM commissions c
                   LEFT JOIN activations a ON a.id = c.activation_id
                  WHERE a.id IS NULL
                  ORDER BY c.id'
            )->fetchAll();

            foreach (array_slice($orphans, 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf('Award #%d has no activation', (int) $row['id']);
            }

            $unlinked = db()->query(
                "SELECT c.id
                   FROM commissions c
                   LEFT JOIN ewallet_ledger l
                     ON l.id = c.ledger_id AND l.ref_type = 'commission'
                  WHERE c.status = 'credited' AND l.id IS NULL
                  ORDER BY c.id"
            )->fetchAll();

            foreach (array_slice($unlinked, 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf('Award #%d is credited but has no commission ledger row', (int) $row['id']);
            }

            $unprocessed = db()->query(
                "SELECT id, user_id
                   FROM activations
                  WHERE status = 'approved' AND commission_processed_at IS NULL
                  ORDER BY id"
            )->fetchAll();

            foreach (array_slice($unprocessed, 0, self::DETAIL_LIMIT) as $row) {
                $details[] = sprintf(
                    'Activation #%d is approved but its commissions were never processed',
                    (int) $row['id']
                );
            }

            $problems = count($orphans) + count($unlinked) + count($unprocessed);

            return $this->section(
                'awards',
                'Award integrity',
                $problems === 0,
                $problems === 0
                    ? 'Every award points at its activation and ledger row; every approved activation is processed'
                    : sprintf(
                        '%d problem(s): %d orphan award(s), %d unlinked credit(s), %d unprocessed activation(s)',
                        $problems,
                        count($orphans),
                        count($unlinked),
                        count($unprocessed)
                    ),
                $details,
                'admin/commissions'
            );
        } catch (Throwable $e) {
            return $this->failed('awards', 'Award integrity', $e);
        }
    }

    /* ----------------------------------------------------------
     *  Shape helpers
     * ---------------------------------------------------------- */

    /**
     * @param list<string> $details
     * @return array<string, mixed>
     */
    private function section(
        string $key,
        string $label,
        bool $ok,
        string $summary,
        array $details,
        string $href
    ): array {
        return [
            'key'     => $key,
            'label'   => $label,
            'ok'      => $ok,
            'summary' => $summary,
            'details' => $details,
            'href'    => $href,
        ];
    }

    /** @return array<string, mixed> */
    private function failed(string $key, string $label, Throwable $e): array
    {
        error_log(sprintf('[ecowealth] health check %s failed: %s', $key, $e->getMessage()));

        return [
            'key'     => $key,
            'label'   => $label,
            'ok'      => false,
            'summary' => 'The check could not run: ' . $e->getMessage(),
            'details' => [],
            'href'    => '',
        ];
    }

    private static function money(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 2, '.', ',');
    }
}
