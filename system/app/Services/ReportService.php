<?php
declare(strict_types=1);

/* ==============================================================
 *  ReportService — the admin reports screen and its CSV exports
 *  (plan §7 Phase 15: "sales by day, commissions by level, wallet
 *  statement export (CSV), payout register, registration-code
 *  revenue, reconciliation/health widget")
 *
 *  The SQL lives in the Report read model; this service owns the
 *  date window (validated once, used by the page and every export),
 *  the dashboard roll-up and the CSV shape.
 *
 *  A report window is **inclusive** of both dates and capped at one
 *  year, so a hand-typed URL can never ask for an unbounded scan.
 * ============================================================== */

final class ReportService
{
    /** Window used when no dates are supplied: the last 30 days. */
    public const DEFAULT_DAYS = 30;

    /** Longest window an export may cover. */
    public const MAX_DAYS = 366;

    /**
     * The exports the page offers. The key is the `?type=` value; the
     * label is the button and the file prefix.
     */
    public const EXPORTS = [
        'sales'       => 'Sales by day',
        'commissions' => 'Commissions by level',
        'unilevel'    => 'Repeat-purchase bonus',
        'wallet'      => 'Wallet statement',
        'payouts'     => 'Payout register',
        'codes'       => 'Registration codes',
    ];

    /**
     * Normalise a `from`/`to` pair into a safe, inclusive window.
     *
     * Missing or unparseable values fall back to the default window;
     * a reversed range is swapped; a range longer than MAX_DAYS is
     * shortened to end at `to`.
     *
     * @return array{from:string, to:string, days:int, label:string}
     */
    public static function range(?string $from, ?string $to): array
    {
        $today = date('Y-m-d');
        $to    = self::date($to) ?? $today;
        $from  = self::date($from) ?? date('Y-m-d', strtotime('-' . (self::DEFAULT_DAYS - 1) . ' days'));

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $span = (int) floor((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400) + 1;

        if ($span > self::MAX_DAYS) {
            $from = date('Y-m-d', strtotime($to . ' -' . (self::MAX_DAYS - 1) . ' days'));
            $span = self::MAX_DAYS;
        }

        return [
            'from'  => $from,
            'to'    => $to,
            'days'  => max(1, $span),
            'label' => $from === $to
                ? date('M j, Y', strtotime($from . ' 12:00:00'))
                : date('M j, Y', strtotime($from . ' 12:00:00')) . ' – ' . date('M j, Y', strtotime($to . ' 12:00:00')),
        ];
    }

    /** The window of the current request (`?from=`/`?to=`). */
    public static function rangeFromRequest(): array
    {
        return self::range(Request::string('from'), Request::string('to'));
    }

    /**
     * The roll-up the admin dashboard renders.
     *
     * Two windows are always present whatever the reports page has
     * selected: today and the last 30 days, because those are the
     * dashboard's KPI definitions (plan §7 Phase 15).
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $last30 = [
            'from' => date('Y-m-d', strtotime('-29 days')),
            'to'   => date('Y-m-d'),
        ];

        return [
            'sales_today'      => Report::salesToday(),
            'sales_30d'        => Report::salesLast30(),
            'sales_all'        => Report::salesAllTime(),
            'open_orders'      => Report::openOrders(),
            'orders_by_status' => Report::ordersByStatus(),
            'top_products'     => Report::topProducts($last30['from'], $last30['to'], 5),
            'commissions'      => Report::commissionSummary($last30['from'], $last30['to']),
            'unilevel'         => Report::unilevelSummary($last30['from'], $last30['to']),
            'wallet'           => Report::walletFloat(),
            'payouts'          => Report::payoutSummary($last30['from'], $last30['to']),
            'members'          => Report::memberCounts(),
        ];
    }

    /**
     * Everything the reports page renders for one window.
     *
     * @param array{from:string, to:string, days:int, label:string} $range
     * @return array<string, mixed>
     */
    public function page(array $range): array
    {
        return [
            'range'         => $range,
            'sales'         => Report::paidBetween($range['from'], $range['to']),
            'sales_today'   => Report::salesToday(),
            'sales_30d'     => Report::salesLast30(),
            'sales_all'     => Report::salesAllTime(),
            'sales_by_day'  => Report::salesByDay($range['from'], $range['to']),
            'sales_by_method' => Report::salesByMethod($range['from'], $range['to']),
            'orders'        => Report::ordersByStatus(),
            'open_orders'   => Report::openOrders(),
            'top_products'  => Report::topProducts($range['from'], $range['to'], 6),
            'commissions'   => Report::commissionSummary($range['from'], $range['to']),
            'levels'        => Report::commissionsByLevel($range['from'], $range['to']),
            'unilevel'          => Report::unilevelSummary($range['from'], $range['to']),
            'unilevel_levels'   => Report::unilevelByLevel($range['from'], $range['to']),
            'unilevel_products' => Report::unilevelByProduct($range['from'], $range['to'], 6),
            'unilevel_periods'  => Report::unilevelByPeriod(6),
            'wallet'        => Report::walletFloat(),
            'payouts'       => Report::payoutSummary($range['from'], $range['to']),
            'codes'         => Report::codeRevenue(),
            'members'       => Report::memberCounts(),
            'health'        => (new HealthService())->checks(),
        ];
    }

    /**
     * Build one CSV export.
     *
     * The caller has already validated `$type` against EXPORTS.
     *
     * @return array{filename:string, header:list<string>, rows:list<list<string>>}
     */
    public function export(string $type, array $range): array
    {
        $from = $range['from'];
        $to   = $range['to'];

        return match ($type) {
            'sales'       => $this->salesExport($from, $to),
            'commissions' => $this->commissionsExport($from, $to),
            'unilevel'    => $this->unilevelExport($from, $to),
            'wallet'      => $this->walletExport($from, $to),
            'payouts'     => $this->payoutExport($from, $to),
            'codes'       => $this->codesExport($from, $to),
            default       => throw new InvalidArgumentException('Unknown report export: ' . $type),
        };
    }

    /** File name for one export, e.g. `ecowealth_sales_2026-10-01_2026-10-31.csv`. */
    public static function filename(string $type, array $range): string
    {
        return 'ecowealth_' . $type . '_' . $range['from'] . '_' . $range['to'] . '.csv';
    }

    /* ----------------------------------------------------------
     *  CSV builders
     * ---------------------------------------------------------- */

    /** @return array{filename:string, header:list<string>, rows:list<list<string>>} */
    private function salesExport(string $from, string $to): array
    {
        $rows = [];

        foreach (Report::salesByDay($from, $to) as $day) {
            $rows[] = [
                $day['day'],
                (string) $day['orders'],
                self::decimal($day['amount']),
                self::decimal($day['orders'] === 0 ? 0.0 : $day['amount'] / $day['orders']),
            ];
        }

        return [
            'filename' => self::filename('sales', ['from' => $from, 'to' => $to]),
            'header'   => ['Date', 'Orders', 'Sales', 'Average order'],
            'rows'     => $rows,
        ];
    }

    /** @return array{filename:string, header:list<string>, rows:list<list<string>>} */
    private function commissionsExport(string $from, string $to): array
    {
        $rows = [];

        foreach (Report::commissionsByLevel($from, $to) as $level) {
            $rows[] = [
                (string) $level['level'],
                (string) $level['type'],
                (string) $level['count'],
                self::decimal($level['amount']),
                (string) $level['skipped'],
            ];
        }

        return [
            'filename' => self::filename('commissions', ['from' => $from, 'to' => $to]),
            'header'   => ['Level', 'Type', 'Awards', 'Amount', 'Skipped'],
            'rows'     => $rows,
        ];
    }

    /**
     * Every repeat-purchase award in the window, exactly the columns the
     * admin ledger shows (plan §8 Rule 8: screen and CSV agree).
     *
     * @return array{filename:string, header:list<string>, rows:list<list<string>>}
     */
    private function unilevelExport(string $from, string $to): array
    {
        $filters = UnilevelAward::normaliseFilters(['from' => $from, 'to' => $to]);
        $rows    = UnilevelAward::exportRows(null, $filters);

        return [
            'filename' => self::filename('unilevel', ['from' => $from, 'to' => $to]),
            'header'   => UnilevelAward::csvHeader(),
            'rows'     => UnilevelAward::csvRows($rows),
        ];
    }

    /** @return array{filename:string, header:list<string>, rows:list<list<string>>} */
    private function walletExport(string $from, string $to): array
    {
        $rows = [];

        foreach (Report::walletRows($from, $to) as $row) {
            $rows[] = [
                (string) $row['id'],
                (string) $row['created_at'],
                (string) $row['username'],
                (string) $row['type'],
                self::decimal($row['amount']),
                self::decimal($row['balance_after']),
                self::decimal($row['withdrawable_after']),
                (string) $row['ref_type'],
                $row['ref_id'] === null ? '' : (string) $row['ref_id'],
                (string) ($row['note'] ?? ''),
            ];
        }

        return [
            'filename' => self::filename('wallet', ['from' => $from, 'to' => $to]),
            'header'   => [
                'Ledger', 'Date', 'Member', 'Type', 'Amount',
                'Balance after', 'Withdrawable after', 'Reference type', 'Reference', 'Note',
            ],
            'rows'     => $rows,
        ];
    }

    /** @return array{filename:string, header:list<string>, rows:list<list<string>>} */
    private function payoutExport(string $from, string $to): array
    {
        $rows = [];

        foreach (Report::payoutRows($from, $to) as $row) {
            $net = round((float) $row['amount'] - (float) $row['service_fee_amount'], 2);

            $rows[] = [
                (string) $row['id'],
                (string) $row['requested_at'],
                (string) $row['username'],
                (string) $row['payout_method'],
                (string) $row['payout_account'],
                self::decimal($row['amount']),
                self::decimal($row['service_fee_pct']) . '%',
                self::decimal($row['service_fee_amount']),
                self::decimal($net),
                self::decimal($row['usdt_rate']),
                self::decimal($row['usdt_gas_fee']),
                self::decimal($row['usdt_amount']),
                (string) $row['status'],
                (string) ($row['processed_by_username'] ?? ''),
                (string) ($row['processed_at'] ?? ''),
                (string) ($row['admin_note'] ?? ''),
            ];
        }

        return [
            'filename' => self::filename('payouts', ['from' => $from, 'to' => $to]),
            'header'   => [
                'Request', 'Requested at', 'Member', 'Method', 'Account', 'Amount',
                'Fee %', 'Fee', 'Net', 'USDT rate', 'Gas (USDT)', 'USDT amount',
                'Status', 'Processed by', 'Processed at', 'Admin note',
            ],
            'rows'     => $rows,
        ];
    }

    /** @return array{filename:string, header:list<string>, rows:list<list<string>>} */
    private function codesExport(string $from, string $to): array
    {
        $rows = [];

        foreach (Report::codeRows($from, $to) as $row) {
            $rows[] = [
                (string) $row['id'],
                (string) $row['code'],
                (string) $row['package'],
                self::decimal($row['price']),
                (string) $row['status'],
                (string) $row['created_by'],
                (string) $row['created_at'],
                (string) ($row['used_by'] ?? ''),
                (string) ($row['used_at'] ?? ''),
                (string) ($row['expires_at'] ?? ''),
            ];
        }

        return [
            'filename' => self::filename('codes', ['from' => $from, 'to' => $to]),
            'header'   => [
                'ID', 'Code', 'Package', 'Price', 'Status',
                'Issued by', 'Issued at', 'Used by', 'Used at', 'Expires',
            ],
            'rows'     => $rows,
        ];
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** Validate one Y-m-d date (null when invalid). */
    private static function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        $ts = strtotime($value . ' 12:00:00');

        return $ts !== false && date('Y-m-d', $ts) === $value ? $value : null;
    }

    /** Plain decimal string for CSV cells. */
    private static function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
