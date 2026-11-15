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
        'sales'        => 'Sales by day',
        'commissions'  => 'Commissions by level',
        'unilevel'     => 'Repeat-purchase bonus',
        'royalty'      => 'Royalty',
        'team_harvest' => 'Team Harvest bonus',
        'wallet'       => 'Wallet statement',
        'payouts'      => 'Payout register',
        'codes'        => 'Registration codes',
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
            'royalty'          => $this->royaltyDashboard(),
            'team_harvest'     => $this->teamHarvestDashboard(),
            'wallet'           => Report::walletFloat(),
            'payouts'          => Report::payoutSummary($last30['from'], $last30['to']),
            'members'          => Report::memberCounts(),
        ];
    }

    /**
     * The Team Harvest dashboard tile figures (Phase 20E).
     *
     * The KPI is the **last 30 days** of award decisions, the same window
     * every other dashboard tile uses; `open` is what the engine would
     * still pay, so the tile can never look like the stream has stopped
     * when it is merely between awards. It is derived on read and never
     * stored (Rule 8 of §9).
     *
     * @return array{credited:float, count:int, recipients:int, this_month:float,
     *               open:float, open_levels:int, queued:int, seeded:bool}
     */
    private function teamHarvestDashboard(): array
    {
        $last30 = Report::teamHarvestSummary(
            date('Y-m-d', strtotime('-29 days')),
            date('Y-m-d')
        );

        $open = (new TeamHarvestService())->openLiability();
        $queue = Report::teamHarvestQueue(1);

        return [
            'credited'    => $last30['credited'],
            'count'       => $last30['count'],
            'recipients'  => $last30['recipients'],
            'this_month'  => $last30['this_month'],
            'open'        => $open['amount'],
            'open_levels' => $open['levels'],
            'queued'      => $queue['queued'],
            'seeded'      => $queue['seeded'],
        ];
    }

    /**
     * The Royalty dashboard tile figures.
     *
     * `paid` / `pending` are all-time; `this_month` is the money
     * credited during the current calendar month (the ledger's `paid`
     * time, not the award period, because a month is paid after it
     * closes). It is never the next close's preview, so the tile
     * cannot disagree with the ledger after a month closes (plan §8
     * Rule 10).
     *
     * @return array{paid:float, pending:float, this_month:float, periods:int}
     */
    private function royaltyDashboard(): array
    {
        $allTime = Report::royaltySummary('1970-01-01', date('Y-m-d'));

        return [
            'paid'       => $allTime['paid'],
            'pending'    => $allTime['pending'],
            'this_month' => $allTime['paid_this_month'],
            'periods'    => $allTime['periods'],
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
            'royalty'           => Report::royaltySummary($range['from'], $range['to']),
            'royalty_ranks'     => Report::royaltyByRank($range['from'], $range['to']),
            'royalty_periods'   => Report::royaltyByPeriod(6),
            'royalty_ratio'     => Report::royaltyPayoutRatio($range['from'], $range['to']),
            /* Phase 20E: the four Team Harvest cards' data — the window's
             * counters, the ten ladder levels, the months and the queue —
             * plus the open liability, which is derived by the engine and
             * never stored (Rule 8). */
            'team_harvest'          => Report::teamHarvestSummary($range['from'], $range['to']),
            'team_harvest_levels'   => Report::teamHarvestByLevel($range['from'], $range['to']),
            'team_harvest_months'   => Report::teamHarvestByMonth($range['from'], $range['to']),
            'team_harvest_queue'    => Report::teamHarvestQueue(5),
            'team_harvest_open'     => (new TeamHarvestService())->openLiability(),
            'team_harvest_max'      => TeamHarvestLevel::maxLevels(),
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
            'sales'        => $this->salesExport($from, $to),
            'commissions'  => $this->commissionsExport($from, $to),
            'unilevel'     => $this->unilevelExport($from, $to),
            'royalty'      => $this->royaltyExport($from, $to),
            'team_harvest' => $this->teamHarvestExport($from, $to),
            'wallet'       => $this->walletExport($from, $to),
            'payouts'      => $this->payoutExport($from, $to),
            'codes'        => $this->codesExport($from, $to),
            default        => throw new InvalidArgumentException('Unknown report export: ' . $type),
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
     * Phase 18: the header and rows come from `UnilevelAward::csvHeader()`
     * / `csvRows()`, so the export inherits the escrow columns (`Hold
     * days`, `Released at`) and the `pending` / `cancelled` statuses
     * without a second definition here (plan §19.6). Adding a column to
     * the ledger therefore adds it to both CSVs at once.
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

    /**
     * The Royalty report CSV: the window's closed months and its rank
     * breakdown in one table, told apart by a `Scope` column, so
     * finance can read both dimensions from one file (P19D-T13).
     *
     * The period rows come from `Report::royaltyByPeriod()` (headers
     * only the close writes) and the rank rows from the awards of the
     * window; the two are the same numbers the report cards render.
     *
     * @return array{filename:string, header:list<string>, rows:list<list<string>>}
     */
    private function royaltyExport(string $from, string $to): array
    {
        $start = substr($from, 0, 7);
        $end   = substr($to, 0, 7);

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $rows = [];

        foreach (Report::royaltyByPeriod(120) as $period) {
            if ($period['period'] < $start || $period['period'] > $end) {
                continue;
            }

            $average = $period['paid_count'] === 0
                ? 0.0
                : $period['paid_total'] / $period['paid_count'];

            $rows[] = [
                'Period',
                (string) $period['period'],
                (string) $period['status'],
                (string) max(0, $period['award_count'] - $period['skipped_count']),
                self::decimal($period['paid_total']),
                self::decimal(round($period['award_total'] - $period['paid_total'], 2)),
                (string) $period['skipped_count'],
                self::decimal($period['award_total']),
                self::decimal($average),
            ];
        }

        foreach (Report::royaltyByRank($from, $to) as $rank) {
            $awarded = round($rank['paid'] + $rank['pending'], 2);

            $rows[] = [
                'Rank',
                (string) $rank['name'],
                '',
                (string) $rank['recipients'],
                self::decimal($rank['paid']),
                self::decimal($rank['pending']),
                (string) $rank['skipped'],
                self::decimal($awarded),
                self::decimal($rank['average']),
            ];
        }

        return [
            'filename' => self::filename('royalty', ['from' => $from, 'to' => $to]),
            'header'   => [
                'Scope', 'Period / rank', 'Status', 'Recipients', 'Paid',
                'Pending', 'Skipped', 'Award total', 'Average award',
            ],
            'rows'     => $rows,
        ];
    }

    /**
     * The Team Harvest report CSV: the window's award rows and the queue's
     * state in one file, told apart by a `Scope` column — `Award` for a row
     * of the ledger, `Queue` for the summary block — so finance can read
     * both dimensions at once (P20E-T14).
     *
     * The award columns are the plan's Appendix C.5 columns verbatim, so
     * this file and the ledger export carry the same names; the queue row
     * fills the columns that mean something for a queue (the go-live
     * instant, the next position, the deepest counter, the open liability
     * and whether the stream is seeded).
     *
     * @return array{filename:string, header:list<string>, rows:list<list<string>>}
     */
    private function teamHarvestExport(string $from, string $to): array
    {
        $filters = TeamHarvestAward::normaliseFilters(['from' => $from, 'to' => $to]);
        $rows    = TeamHarvestAward::exportRows($filters);
        $queue   = Report::teamHarvestQueue(1);
        $open    = (new TeamHarvestService())->openLiability();

        $out = [];

        foreach ($rows as $row) {
            $out[] = array_merge(['Award'], self::teamHarvestColumns($row));
        }

        /* The queue block: one row that carries the position sequence's
         * next number, the deepest counter and what the engine would pay
         * if every outstanding level were evaluated now. */
        $out[] = [
            'Queue',
            '',
            $queue['baseline'] === null ? '' : (string) $queue['baseline'],
            (string) $queue['queued'] . ' queued',
            (string) $queue['next'],
            (string) $open['levels'],
            (string) $queue['deepest'],
            '',
            self::decimal($open['amount']),
            $queue['seeded'] ? 'Seeded' : 'Not seeded',
            '',
        ];

        return [
            'filename' => self::filename('team_harvest', ['from' => $from, 'to' => $to]),
            'header'   => array_merge(['Scope'], self::teamHarvestCsvHeader()),
            'rows'     => $out,
        ];
    }

    /**
     * The Team Harvest ledger CSV header — the screen's columns plus the
     * wallet reference (plan Appendix C.5, P20E-T12).
     *
     * @return list<string>
     */
    public static function teamHarvestCsvHeader(): array
    {
        return [
            'Award id', 'Credited at', 'Member', 'Position', 'Level',
            'Teammates (n)', 'Threshold', 'Amount', 'Status', 'Wallet reference',
        ];
    }

    /**
     * One award as the CSV columns above. `$scope` is not prepended here:
     * the caller owns the discriminator (the ledger export emits the ten
     * columns alone; the report export prepends `Scope`).
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function teamHarvestColumns(array $row): array
    {
        $status = (string) ($row['status'] ?? '');

        return [
            (string) (int) ($row['id'] ?? 0),
            (string) ($row['credited_at'] ?? ''),
            (string) ($row['username'] ?? ''),
            ($row['position'] ?? null) === null ? '' : (string) (int) $row['position'],
            (string) (int) ($row['level'] ?? 0),
            (string) (int) ($row['n_at_award'] ?? 0),
            (string) (int) ($row['threshold_n'] ?? 0),
            self::decimal($row['amount'] ?? 0),
            TeamHarvestAward::STATUS_LABELS[$status] ?? ucfirst($status),
            ($row['ledger_id'] ?? null) === null ? '' : (string) (int) $row['ledger_id'],
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
