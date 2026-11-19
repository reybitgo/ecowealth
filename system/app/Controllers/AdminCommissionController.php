<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminCommissionController — the company-wide commission ledger
 *  (plan §7 Phase 10, Appendix A extension; Phase 17D adds the
 *  repeat-purchase tab of plan §8; Phase 19D adds the Royalty tab;
 *  Phase 20E adds the Team Harvest tab)
 *
 *  Route: GET /admin/commissions   guard `admin`
 *
 *  Four ledgers, one page, one URL:
 *
 *      /admin/commissions                      package referral awards
 *                                              (`commissions`)
 *      /admin/commissions?ledger=repeat        repeat-purchase awards
 *                                              (`unilevel_awards`)
 *      /admin/commissions?ledger=royalty       the monthly Royalty ledger
 *                                              (`royalty_awards`)
 *      /admin/commissions?ledger=team_harvest  the Team Harvest ledger
 *                                              (`team_harvest_awards`)
 *
 *  The package tab is the Phase 10–11 ledger, unchanged: filters by
 *  recipient/source/package (q), type, level, status and date, and the
 *  four counters finance needs. The repeat tab is read-only over the
 *  rows 17C writes: one ladder, levels 1–10, no direct/indirect column
 *  (D-17), with the periods card showing the closed months. Skipped
 *  rows are audit notes and never enter a credited total.
 *
 *  The Royalty tab (19D) is read-only over the rows 19C writes: one
 *  award per member per closed month, the period as the primary filter
 *  (latest final month by default), the closed-month cards, seven
 *  counters, a clearly labelled “if this month closed now” preview and
 *  a CSV with the plan Appendix C.5 columns. No admin page writes a
 *  period or an award; the close is the CLI job (plan §8 Rule 1).
 *
 *  The Team Harvest tab (20E) is read-only over the rows 20D writes: one
 *  row per (member, level) decision, the ten-row level distribution, six
 *  counters, filters from `TeamHarvestAward::normaliseFilters()` and a CSV
 *  with the plan Appendix C.5 columns. A `baseline` row is recorded money
 *  that was already complete when the plan started and is never paid
 *  (D-07), so it is counted in its own tile and never inside *Credited*
 *  (Rule 4 of §9). The open liability is `openLiability()`'s number and
 *  is labelled *not yet paid* (Rule 8).
 *
 *  The ledger is read-only by design: an award is never edited. A
 *  correction would be a new `adjustment` wallet movement plus a
 *  `voided` row (Phase 15), never an UPDATE on money history.
 *
 *  The page is one live surface (`admin.commissions`): the card heading,
 *  counters, rows and pagination are regions; the filter form stays
 *  outside them. The tab and the period are part of the URL and of the
 *  ETag scope, so an open Royalty tab refreshes on the surface a close
 *  bumps and never shares a 304 with another tab (P17D-T14, P19D-T16,
 *  P20E-T15).
 * ============================================================== */

final class AdminCommissionController
{
    /** Package referral ledger. */
    public const TAB_PACKAGE = 'package';

    /** Repeat-purchase ladder ledger. */
    public const TAB_REPEAT = 'repeat';

    /** Monthly Royalty ledger (Phase 19D). */
    public const TAB_ROYALTY = 'royalty';

    /** The Team Harvest ledger (Phase 20E). */
    public const TAB_TEAM_HARVEST = 'team_harvest';

    /** The General Harvest bonus ledger (Phase 21E). */
    public const TAB_GENERAL_HARVEST = 'general_harvest';

    /** The Product Binary award ledger (Phase 22E). */
    public const TAB_BINARY = 'binary';

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        /* The same URL doubles as the JSON feed: X-Live: 1 returns the
         * counters, rows and pagination instead of the layout. */
        if (Live::isFeedRequest()) {
            $this->listFeed();
            return;
        }

        View::page('admin', 'admin/commissions/index', $this->context() + [
            'page_title'  => 'Commissions',
            'current_nav' => 'admin.commissions',
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
            'live'        => $this->listLiveMeta(),
        ]);
    }

    /** The tab named by `?ledger=`; anything but the three known tabs is the package tab. */
    public static function tab(): string
    {
        return match (strtolower(trim(Request::string('ledger')))) {
            self::TAB_REPEAT       => self::TAB_REPEAT,
            self::TAB_ROYALTY      => self::TAB_ROYALTY,
            self::TAB_TEAM_HARVEST => self::TAB_TEAM_HARVEST,
            self::TAB_GENERAL_HARVEST => self::TAB_GENERAL_HARVEST,
            self::TAB_BINARY       => self::TAB_BINARY,
            default                => self::TAB_PACKAGE,
        };
    }

    /**
     * The context for the tab named by the request.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return match (self::tab()) {
            self::TAB_REPEAT       => $this->repeatContext(),
            self::TAB_ROYALTY      => $this->royaltyContext(),
            self::TAB_TEAM_HARVEST => $this->teamHarvestContext(),
            self::TAB_GENERAL_HARVEST => $this->generalHarvestContext(),
            self::TAB_BINARY       => $this->binaryContext(),
            default                => $this->listContext(),
        };
    }

    /**
     * Everything the package ledger renders, built once for the page and
     * its feed.
     *
     * @return array<string, mixed>
     */
    public function listContext(): array
    {
        $filters = Commission::normaliseFilters([
            'type'   => Request::string('type'),
            'level'  => Request::string('level'),
            'status' => Request::string('status'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
            'q'      => Request::string('q'),
        ]);

        $pager = Paginator::fromTableRequest(Commission::countAdmin($filters));

        return [
            'tab'         => self::TAB_PACKAGE,
            'ledger'      => self::TAB_PACKAGE,
            'stats'       => Commission::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'commissions' => Commission::adminList($filters, $pager),
            'ledger_url'  => url('admin/commissions'),
            'export_url'  => url('admin/commissions/export' . self::queryString($filters)),
            'wallet_url'  => url('admin/wallets'),
            'package_url' => url('admin/commissions'),
            'repeat_url'  => url('admin/commissions?ledger=repeat'),
        ];
    }

    /**
     * Everything the repeat-purchase tab renders, built once for the page
     * and its feed.
     *
     * Filters go through `UnilevelAward::normaliseFilters()`, so an
     * unknown level/status/product/date falls back to "no filter"
     * (plan §8 Rule 7).
     *
     * @return array<string, mixed>
     */
    public function repeatContext(): array
    {
        $filters = UnilevelAward::normaliseFilters([
            'level'      => Request::string('level'),
            'status'     => Request::string('status'),
            'product_id' => Request::string('product_id'),
            'from'       => Request::string('from'),
            'to'         => Request::string('to'),
            'q'          => Request::string('q'),
        ]);

        $pager = Paginator::fromTableRequest(UnilevelAward::countAdmin($filters));

        return [
            'tab'         => self::TAB_REPEAT,
            'ledger'      => self::TAB_REPEAT,
            'stats'       => UnilevelAward::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'awards'      => UnilevelAward::adminList($filters, $pager),
            'periods'     => UnilevelCycle::periods(6),
            'products'    => Product::all(),
            'ledger_url'  => url('admin/commissions?ledger=repeat'),
            'export_url'  => url('admin/commissions/export' . self::repeatQueryString($filters)),
            'wallet_url'  => url('admin/wallets'),
            'package_url' => url('admin/commissions'),
            'repeat_url'  => url('admin/commissions?ledger=repeat'),
        ];
    }

    /**
     * Everything the Royalty tab renders, built once for the page and
     * its feed (plan §8 Phase 19D).
     *
     * The period is the primary filter (Rule 3): an explicit
     * `?period=YYYY-MM` or `?period=current` wins, otherwise the latest
     * final month, then the latest header, then the open month. The open
     * month is a labelled preview computed by `RoyaltyService::preview()`
     * and is never a period row or part of a ledger total (Rule 4).
     *
     * @return array<string, mixed>
     */
    public function royaltyContext(): array
    {
        /* `period=current` is URL state, not a stored month, so the raw
         * value is resolved before the award filters validate it. */
        $requested = trim(Request::string('period'));

        $filters = RoyaltyAward::normaliseFilters([
            'period' => $requested,
            'status' => Request::string('status'),
            'rank'   => Request::string('rank'),
            'q'      => Request::string('q'),
        ]);

        $open    = RoyaltyPeriod::periodOf();
        $periods = RoyaltyPeriod::periods(24);
        $period  = self::royaltyPeriod($requested, $periods, $open);

        /* The screen is always scoped to one period, so the export, the
         * filters and the header all speak about the same month. */
        $filters['period'] = $period;

        $previewMode = $period === $open;

        if ($previewMode) {
            /* No stored rows can exist for an open month; the preview
             * panel above the ledger carries the projection instead. */
            $pager     = new Paginator(0, Paginator::perPageFromRequest());
            $awards    = [];
            $totals    = [
                'rows' => 0, 'paid_rows' => 0, 'pending_rows' => 0, 'skipped_rows' => 0,
                'paid_total' => 0.0, 'pending_total' => 0.0, 'award_total' => 0.0,
            ];
            $breakdown = RoyaltyAward::rankBreakdown($period);
        } else {
            $pager     = Paginator::fromTableRequest(RoyaltyAward::countForPeriod($period, $filters));
            $awards    = RoyaltyAward::pageForPeriod($period, $filters, $pager);
            $totals    = RoyaltyAward::totalsForPeriod($period);
            $breakdown = RoyaltyAward::rankBreakdown($period);
        }

        $preview = null;

        try {
            $preview = (new RoyaltyService())->preview($open);
        } catch (Throwable $e) {
            /* A broken preview panel must never blank the ledger. */
            error_log('[ecowealth] royalty preview unavailable: ' . $e->getMessage());
        }

        /* Phase 19F: the operations panel reads one snapshot of the
         * period pipeline (open month, last final, pending, evaluated,
         * switches, last close). Read-only; a failure hides the card,
         * never the ledger. */
        $ops = null;

        try {
            $ops = RoyaltyOps::snapshot();
        } catch (Throwable $e) {
            error_log('[ecowealth] royalty operations snapshot unavailable: ' . $e->getMessage());
        }

        $lifetimePaid = 0.0;

        foreach (RoyaltyPeriod::periods(120) as $row) {
            $lifetimePaid += (float) $row['paid_total'];
        }

        $earning = $totals['paid_rows'] + $totals['pending_rows'];

        $stats = [
            'period'        => $period,
            'period_status' => RoyaltyPeriod::status($period),
            'rows'          => $totals['rows'],
            'paid_rows'     => $totals['paid_rows'],
            'pending_rows'  => $totals['pending_rows'],
            'skipped_rows'  => $totals['skipped_rows'],
            'recipients'    => $earning,
            'paid_total'    => $totals['paid_total'],
            'pending_total' => $totals['pending_total'],
            'award_total'   => $totals['award_total'],
            'average'       => $earning === 0 ? 0.0 : round($totals['award_total'] / $earning, 2),
            'top_rank'      => self::topRoyaltyRank($breakdown),
            'lifetime_paid' => round($lifetimePaid, 2),
        ];

        return [
            'tab'            => self::TAB_ROYALTY,
            'ledger'         => self::TAB_ROYALTY,
            'stats'          => $stats,
            'filters'        => $filters,
            'pager'          => $pager,
            'awards'         => $awards,
            'periods'        => $periods,
            'rank_breakdown' => $breakdown,
            'preview'        => $preview,
            'preview_mode'   => $previewMode,
            'royalty_ops'    => $ops,
            'open_period'    => $open,
            'ledger_url'     => url('admin/commissions?ledger=royalty'),
            'export_url'     => url('admin/commissions/export' . self::royaltyQueryString($filters)),
            'wallet_url'     => url('admin/wallets'),
            'package_url'    => url('admin/commissions'),
            'repeat_url'     => url('admin/commissions?ledger=repeat'),
            'royalty_url'    => url('admin/commissions?ledger=royalty'),
        ];
    }

    /**
     * Everything the Team Harvest tab renders, built once for the page and
     * its feed (plan §9 Phase 20E, Appendix C.2).
     *
     * Read-only over the rows 20D writes. The filters go through
     * `TeamHarvestAward::normaliseFilters()`, so an unknown level, status,
     * date or search falls back to "no filter" exactly like the other
     * three tabs (Rule 9). The six tiles describe the **whole ledger**
     * (the same convention the package and repeat tabs use) and are read
     * in one query by `Report::teamHarvestTotals()`; the rows below them
     * are the filtered page.
     *
     * Two numbers are deliberately kept apart (Rule 4): *Credited* counts
     * `status = 'credited'` only, and a `baseline` row — money that was
     * already complete when the plan started — appears in its own tile and
     * its own row badge, never inside a paid total.
     *
     * @return array<string, mixed>
     */
    public function teamHarvestContext(): array
    {
        $filters = TeamHarvestAward::normaliseFilters([
            'user_id' => Request::string('user_id'),
            'level'   => Request::string('level'),
            'status'  => Request::string('status'),
            'from'    => Request::string('from'),
            'to'      => Request::string('to'),
            'q'       => Request::string('q'),
        ]);

        $pager  = Paginator::fromTableRequest(TeamHarvestAward::count($filters));
        $totals = Report::teamHarvestTotals();
        $open   = (new TeamHarvestService())->openLiability();
        $queue  = new HarvestQueue();

        $stats = [
            'credited_total' => $totals['credited_total'],
            'this_month'     => $totals['this_month'],
            'credited_rows'  => $totals['credited_rows'],
            'recipients'     => $totals['recipients'],
            'baseline_total' => $totals['baseline_total'],
            'baseline_rows'  => $totals['baseline_rows'],
            'awards'         => $totals['awards'],
            'open_levels'    => $open['levels'],
            'open_members'   => $open['members'],
            'open_amount'    => $open['amount'],
        ];

        return [
            'tab'         => self::TAB_TEAM_HARVEST,
            'ledger'      => self::TAB_TEAM_HARVEST,
            'stats'       => $stats,
            'filters'     => $filters,
            'pager'       => $pager,
            'awards'      => TeamHarvestAward::select($filters, $pager),
            'levels'      => TeamHarvestAward::byLevel(),
            'max_levels'  => TeamHarvestLevel::maxLevels(),
            'seeded'      => $queue->seeded(),
            'baseline'    => $queue->baseline(),
            'enabled'     => TeamHarvestLevel::enabled(),
            'ledger_url'  => url('admin/commissions?ledger=team_harvest'),
            'export_url'  => url('admin/commissions/export' . self::teamHarvestQueryString($filters)),
            'wallet_url'  => url('admin/wallets'),
            'package_url' => url('admin/commissions'),
            'repeat_url'  => url('admin/commissions?ledger=repeat'),
            'royalty_url' => url('admin/commissions?ledger=royalty'),
            'general_harvest_url' => url('admin/commissions?ledger=general_harvest'),
        ];
    }

    /**
     * Everything the General Harvest tab renders, built once for the page
     * and its feed (plan §9 Phase 21E-S1, Appendix C.2).
     *
     * Read-only over the rows 21D writes. The filters go through
     * `GeneralHarvestAward::normaliseFilters()`, so an unknown level,
     * status, date or search falls back to "no filter" exactly like the
     * other four tabs (Rule 10).
     *
     * Read-only (Rule 1): nothing here writes an award, a wallet row or
     * the board row. The `credited` total counts `status = 'credited'`
     * only; baseline rows appear in their own tile and never inside a paid
     * total (Rule 4).
     *
     * @return array<string, mixed>
     */
    public function generalHarvestContext(): array
    {
        $filters = GeneralHarvestAward::normaliseFilters([
            'user_id' => Request::string('user_id'),
            'level'   => Request::string('level'),
            'status'  => Request::string('status'),
            'from'    => Request::string('from'),
            'to'      => Request::string('to'),
            'q'       => Request::string('q'),
        ]);

        $pager  = Paginator::fromTableRequest(GeneralHarvestAward::count($filters));
        $totals = GeneralHarvestAward::totals($filters);
        $open   = (new GeneralHarvestService())->openLiability();
        $board  = GeneralHarvestBoard::state();
        $frontier = GeneralHarvestBoard::frontier();

        $stats = [
            'credited_total' => $totals['credited_total'],
            'credited_rows'  => $totals['credited_rows'],
            'recipients'     => $totals['recipients'],
            'baseline_total' => $totals['baseline_total'],
            'baseline_rows'  => $totals['baseline_rows'],
            'awards'         => $totals['rows'],
            'open_amount'    => $open['amount'],
            'open_levels'    => count($open['pairs']),
            'settled_n'      => (int) $board['settled_n'],
            'queue_n'        => GeneralHarvestBoard::queueN()['n'],
            'armed'          => (bool) $board['armed'],
            'enabled'        => (new GeneralHarvestService())->enabled(),
        ];

        return [
            'tab'         => self::TAB_GENERAL_HARVEST,
            'ledger'      => self::TAB_GENERAL_HARVEST,
            'stats'       => $stats,
            'filters'     => $filters,
            'pager'       => $pager,
            'awards'      => GeneralHarvestAward::select($filters, $pager),
            'levels'      => GeneralHarvestAward::byLevel(),
            'max_levels'  => GeneralHarvestLevel::maxLevels(),
            'frontier'    => array_values($frontier),
            'ledger_url'  => url('admin/commissions?ledger=general_harvest'),
            'export_url'  => url('admin/commissions/export' . self::generalHarvestQueryString($filters)),
            'wallet_url'  => url('admin/wallets'),
            'package_url' => url('admin/commissions'),
            'repeat_url'  => url('admin/commissions?ledger=repeat'),
            'royalty_url' => url('admin/commissions?ledger=royalty'),
            'team_harvest_url' => url('admin/commissions?ledger=team_harvest'),
            'general_harvest_url' => url('admin/commissions?ledger=general_harvest'),
        ];
    }

    /**
     * Everything the Product Binary tab renders, built once for the page
     * and its feed (plan §9 Phase 22E-S1).
     *
     * Read-only over the rows 22D writes. The filters go through
     * `BinaryAward::normaliseFilters()`, so an unknown kind, status, side,
     * product id, date or search falls back to "no filter" exactly like the
     * other tabs (Rule 7). Points and money are shown side by side and
     * never added together (Rule 4).
     *
     * @return array<string, mixed>
     */
    public function binaryContext(): array
    {
        $filters = BinaryAward::normaliseFilters([
            'kind'       => Request::string('kind'),
            'status'     => Request::string('status'),
            'side'       => Request::string('side'),
            'product_id' => Request::string('product_id'),
            'from'       => Request::string('from'),
            'to'         => Request::string('to'),
            'q'          => Request::string('q'),
        ]);

        $pager  = Paginator::fromTableRequest(BinaryAward::countAdmin($filters));
        $awards = BinaryAward::adminList($filters, $pager);
        $stats  = BinaryAward::stats();

        $hasFilters = (string) $filters['kind'] !== ''
            || (string) $filters['status'] !== ''
            || (string) $filters['side'] !== ''
            || (string) $filters['product_id'] !== ''
            || (string) $filters['from'] !== ''
            || (string) $filters['to'] !== ''
            || (string) $filters['q'] !== '';

        $kindOptions = [['value' => '', 'label' => 'All kinds']];
        foreach (BinaryAward::KINDS as $kind) {
            $kindOptions[] = [
                'value' => $kind,
                'label' => ucfirst($kind),
            ];
        }

        $statusOptions = ['' => 'All statuses'];
        foreach (BinaryAward::STATUSES as $status) {
            $statusOptions[$status] = BinaryAward::STATUS_LABELS[$status] ?? ucfirst($status);
        }

        $sideOptions = [['value' => '', 'label' => 'All sides']];
        foreach (['left', 'right'] as $side) {
            $sideOptions[] = ['value' => $side, 'label' => ucfirst($side)];
        }

        return [
            'tab'         => self::TAB_BINARY,
            'ledger'      => self::TAB_BINARY,
            'stats'       => $stats,
            'filters'     => $filters,
            'pager'       => $pager,
            'awards'      => $awards,
            'kind_options'   => $kindOptions,
            'status_options' => $statusOptions,
            'side_options'   => $sideOptions,
            'has_filters'  => $hasFilters,
            'ledger_url'  => url('admin/commissions?ledger=binary'),
            'export_url'  => url('admin/commissions/export' . self::binaryQueryString($filters)),
            'wallet_url'  => url('admin/wallets'),
            'package_url' => url('admin/commissions'),
            'repeat_url'  => url('admin/commissions?ledger=repeat'),
            'royalty_url' => url('admin/commissions?ledger=royalty'),
            'binary_url'  => url('admin/commissions?ledger=binary'),
        ];
    }

    /**
     * The period the Royalty tab shows (plan §8 Rule 3): an explicit
     * `current` or `YYYY-MM` wins; otherwise the latest final period,
     * then the latest header of any status, then the open month.
     *
     * @param list<array<string, mixed>> $periods newest first
     */
    private static function royaltyPeriod(string $requested, array $periods, string $open): string
    {
        $requested = trim($requested);

        if (strtolower($requested) === 'current') {
            return $open;
        }

        if (RoyaltyPeriod::isPeriod($requested)) {
            return $requested;
        }

        foreach ($periods as $row) {
            if (in_array((string) $row['status'], RoyaltyPeriod::FINAL, true)) {
                return (string) $row['period'];
            }
        }

        if ($periods !== []) {
            return (string) $periods[0]['period'];
        }

        return $open;
    }

    /**
     * The highest paying rank that has a row in the period, or null.
     *
     * @param array<int, array<string, mixed>> $breakdown
     */
    private static function topRoyaltyRank(array $breakdown): ?string
    {
        foreach ([RoyaltyRank::DIRECTOR, RoyaltyRank::MANAGER, RoyaltyRank::SUPERVISOR] as $rank) {
            if ((int) ($breakdown[$rank]['rows'] ?? 0) > 0) {
                return (string) $breakdown[$rank]['name'];
            }
        }

        return null;
    }

    /**
     * CSV of the filtered commission ledger (plan §7 Phase 11:
     * "Unilevel statement" export).
     *
     * Route (Appendix A extension): GET /admin/commissions/export
     * guard `admin`. Exports every matching award — credited and skipped
     * — with the recipient, source, package, level and ledger link, so
     * finance can reconcile offline. The download is audited.
     *
     * Phase 17D: `?ledger=repeat` exports the repeat-purchase ledger
     * instead, with the columns the repeat tab shows, under its own
     * `unilevel.export` audit action (plan §15.4).
     *
     * Phase 19D: `?ledger=royalty` exports the filtered Royalty awards
     * with the plan Appendix C.5 columns, under `royalty.export`.
     *
     * Phase 20E: `?ledger=team_harvest` exports the filtered Team Harvest
     * ledger under `team_harvest.export`, with the same columns as the
     * screen plus the wallet reference (plan Appendix C.5).
     *
     * @param array<string, string> $params
     */
    public function export(array $params = []): void
    {
        if (self::tab() === self::TAB_REPEAT) {
            $this->exportRepeat();
            return;
        }

        if (self::tab() === self::TAB_ROYALTY) {
            $this->exportRoyalty();
            return;
        }

        if (self::tab() === self::TAB_TEAM_HARVEST) {
            $this->exportTeamHarvest();
            return;
        }

        if (self::tab() === self::TAB_GENERAL_HARVEST) {
            $this->exportGeneralHarvest();
            return;
        }

        if (self::tab() === self::TAB_BINARY) {
            $this->exportBinary();
            return;
        }

        $filters = Commission::normaliseFilters([
            'type'   => Request::string('type'),
            'level'  => Request::string('level'),
            'status' => Request::string('status'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
            'q'      => Request::string('q'),
        ]);

        $rows = Commission::exportRows(null, $filters);
        $admin = auth_user();

        AuditLog::record([
            'action'       => 'commission.export',
            'actor_id'     => $admin === null ? null : (int) $admin['id'],
            'actor_role'   => $admin === null ? null : (string) $admin['role'],
            'subject_type' => 'commission',
            'subject_id'   => null,
            'meta'         => ['filters' => $filters, 'rows' => count($rows)],
        ]);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="commissions_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, [
            'Award', 'Awarded at', 'Recipient', 'Recipient name', 'Level', 'Type',
            'Source', 'Source name', 'Package', 'Amount', 'Status', 'Reason',
            'Activation', 'Ledger',
        ]);

        foreach ($rows as $row) {
            fputcsv($out, [
                (int) $row['id'],
                (string) $row['created_at'],
                (string) $row['recipient_username'],
                (string) ($row['recipient_name'] ?? ''),
                (int) $row['level'],
                (string) $row['type'],
                (string) $row['source_username'],
                (string) ($row['source_name'] ?? ''),
                (string) $row['package_name'],
                number_format((float) $row['amount'], 2, '.', ''),
                (string) $row['status'],
                (string) ($row['reason'] ?? ''),
                (int) $row['activation_id'],
                $row['ledger_id'] === null ? '' : (int) $row['ledger_id'],
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * GET /admin/commissions/export?ledger=repeat — the repeat-purchase
     * ledger as CSV. Same columns as the screen (award, date, order,
     * buyer, recipient, product, level, base, rate, amount, status,
     * reason, recovered).
     */
    private function exportRepeat(): void
    {
        $filters = UnilevelAward::normaliseFilters([
            'level'      => Request::string('level'),
            'status'     => Request::string('status'),
            'product_id' => Request::string('product_id'),
            'from'       => Request::string('from'),
            'to'         => Request::string('to'),
            'q'          => Request::string('q'),
        ]);

        $rows  = UnilevelAward::exportRows(null, $filters);
        $admin = auth_user();

        AuditLog::record([
            'action'       => 'unilevel.export',
            'actor_id'     => $admin === null ? null : (int) $admin['id'],
            'actor_role'   => $admin === null ? null : (string) $admin['role'],
            'subject_type' => 'unilevel_award',
            'subject_id'   => null,
            'meta'         => ['filters' => $filters, 'rows' => count($rows)],
        ]);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="repeat-purchase-awards_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, UnilevelAward::csvHeader());

        foreach (UnilevelAward::csvRows($rows) as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * GET /admin/commissions/export?ledger=royalty — the filtered
     * Royalty awards as CSV, with the columns of plan Appendix C.5.
     *
     * The download reuses the screen's filters (Rule 7) and is audited
     * once as `royalty.export` with the tab, the filters and the row
     * count (plan §15.4). It writes no period and no award.
     */
    private function exportRoyalty(): void
    {
        $export = $this->royaltyExportPayload();

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="royalty-awards_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, self::royaltyCsvHeader());

        foreach (self::royaltyCsvRows($export['rows']) as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * The filtered Royalty export rows and the one audit row they write.
     *
     * Public so the gate can assert the `royalty.export` row and the
     * exact CSV payload without streaming a file out of the harness;
     * the routed `export()` still exits after the CSV (P19D-T10/T11).
     *
     * @return array{filters:array{period:string, status:string, rank:string, q:string}, rows:list<array<string, mixed>>}
     */
    public function royaltyExportPayload(): array
    {
        $requested = trim(Request::string('period'));

        $filters = RoyaltyAward::normaliseFilters([
            'period' => $requested,
            'status' => Request::string('status'),
            'rank'   => Request::string('rank'),
            'q'      => Request::string('q'),
        ]);

        /* The screen always shows one period; the CSV mirrors it. */
        $filters['period'] = self::royaltyPeriod(
            $requested,
            RoyaltyPeriod::periods(24),
            RoyaltyPeriod::periodOf()
        );

        $rows  = RoyaltyAward::exportRows($filters);
        $admin = auth_user();

        AuditLog::record([
            'action'       => 'royalty.export',
            'actor_id'     => $admin === null ? null : (int) $admin['id'],
            'actor_role'   => $admin === null ? null : (string) $admin['role'],
            'subject_type' => 'royalty_award',
            'subject_id'   => null,
            'meta'         => [
                'tab'     => self::TAB_ROYALTY,
                'filters' => $filters,
                'rows'    => count($rows),
            ],
        ]);

        return ['filters' => $filters, 'rows' => $rows];
    }

    /**
     * The Royalty ledger CSV header — exactly plan Appendix C.5.
     *
     * @return list<string>
     */
    public static function royaltyCsvHeader(): array
    {
        return [
            'Period', 'Member', 'Full name', 'Rank', 'Group sales', 'Personal sales',
            'Quota', 'Group %', 'Personal %', 'Amount', 'Status', 'Reason',
            'Evaluated at', 'Paid at', 'Ledger id',
        ];
    }

    /**
     * The Royalty ledger CSV rows, one per award.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<string>>
     */
    public static function royaltyCsvRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                (string) $row['period'],
                (string) ($row['username'] ?? ''),
                (string) ($row['full_name'] ?? ''),
                RoyaltyRank::NAMES[(int) $row['rank_level']] ?? 'Rank ' . (int) $row['rank_level'],
                number_format((float) $row['group_volume'], 2, '.', ''),
                number_format((float) $row['personal_volume'], 2, '.', ''),
                number_format((float) $row['quota'], 2, '.', ''),
                number_format((float) $row['group_pct'], 3, '.', ''),
                number_format((float) $row['personal_pct'], 3, '.', ''),
                number_format((float) $row['amount'], 2, '.', ''),
                (string) $row['status'],
                (string) ($row['reason'] ?? ''),
                (string) $row['created_at'],
                (string) ($row['credited_at'] ?? ''),
                $row['ledger_id'] === null ? '' : (string) $row['ledger_id'],
            ];
        }

        return $out;
    }

    /**
     * GET /admin/commissions/export?ledger=team_harvest — the filtered Team
     * Harvest ledger as CSV.
     *
     * Same columns as the screen plus the wallet reference (plan Appendix
     * C.5, P20E-T12). The download reuses the tab's filters and writes one
     * `team_harvest.export` audit row with them.
     */
    private function exportTeamHarvest(): void
    {
        $export = $this->teamHarvestExportPayload();

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="team-harvest-ledger_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, ReportService::teamHarvestCsvHeader());

        foreach ($export['rows'] as $row) {
            fputcsv($out, ReportService::teamHarvestColumns($row));
        }

        fclose($out);
        exit;
    }

    /**
     * The filtered Team Harvest export rows and the one audit row they
     * write.
     *
     * Public so the gate can assert the `team_harvest.export` row and the
     * exact CSV payload without streaming a file out of the harness; the
     * routed `export()` still exits after the CSV (P20E-T12/T23).
     *
     * @return array{filters:array<string, string|int>, rows:list<array<string, mixed>>}
     */
    public function teamHarvestExportPayload(): array
    {
        $filters = TeamHarvestAward::normaliseFilters([
            'user_id' => Request::string('user_id'),
            'level'   => Request::string('level'),
            'status'  => Request::string('status'),
            'from'    => Request::string('from'),
            'to'      => Request::string('to'),
            'q'       => Request::string('q'),
        ]);

        $rows  = TeamHarvestAward::exportRows($filters);
        $admin = auth_user();

        AuditLog::record([
            'action'       => 'team_harvest.export',
            'actor_id'     => $admin === null ? null : (int) $admin['id'],
            'actor_role'   => $admin === null ? null : (string) $admin['role'],
            'subject_type' => 'team_harvest_award',
            'subject_id'   => null,
            'meta'         => [
                'tab'     => self::TAB_TEAM_HARVEST,
                'filters' => $filters,
                'rows'    => count($rows),
            ],
        ]);

        return ['filters' => $filters, 'rows' => $rows];
    }

    /**
     * The current filters as an export query string ('' when none).
     *
     * @param array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
     */
    private static function queryString(array $filters): string
    {
        $query = array_filter(
            $filters,
            static fn (string $value): bool => $value !== ''
        );

        return $query === [] ? '' : '?' . http_build_query($query);
    }

    /**
     * The repeat export query string: the tab first, then the present
     * filters.
     *
     * @param array{level:string, status:string, product_id:string, from:string, to:string, q:string} $filters
     */
    private static function repeatQueryString(array $filters): string
    {
        $query = ['ledger' => self::TAB_REPEAT] + array_filter(
            $filters,
            static fn (string $value): bool => $value !== ''
        );

        return '?' . http_build_query($query);
    }

    /**
     * The General Harvest export query string: the tab first, then the
     * present filters.
     *
     * @param array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
     */
    private static function generalHarvestQueryString(array $filters): string
    {
        $present = array_filter(
            $filters,
            static fn (string|int $value): bool => $value !== '' && $value !== 0
        );

        return '?' . http_build_query(['ledger' => self::TAB_GENERAL_HARVEST] + $present);
    }

    /**
     * The Binary export query string: the tab first, then the present
     * filters.
     *
     * @param array{kind:string, status:string, side:string, product_id:string, from:string, to:string, q:string} $filters
     */
    private static function binaryQueryString(array $filters): string
    {
        $present = array_filter(
            $filters,
            static fn (string $value): bool => $value !== ''
        );

        return '?' . http_build_query(['ledger' => self::TAB_BINARY] + $present);
    }

    /**
     * The Royalty export query string: the tab first, then the present
     * filters. The period is always present (the screen shows one).
     *
     * @param array{period:string, status:string, rank:string, q:string} $filters
     */
    private static function royaltyQueryString(array $filters): string
    {
        $query = ['ledger' => self::TAB_ROYALTY] + array_filter(
            $filters,
            static fn (string $value): bool => $value !== ''
        );

        return '?' . http_build_query($query);
    }

    /**
     * The Team Harvest export query string: the tab first, then the present
     * filters. A numeric `user_id` filter is kept too, but only when it is
     * a real id, so the CSV mirrors exactly what the screen shows.
     *
     * @param array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
     */
    private static function teamHarvestQueryString(array $filters): string
    {
        $present = array_filter(
            $filters,
            static fn (string|int $value): bool => $value !== '' && $value !== 0
        );

        return '?' . http_build_query(['ledger' => self::TAB_TEAM_HARVEST] + $present);
    }

    /**
     * GET /admin/commissions/export?ledger=general_harvest — the filtered
     * General Harvest ledger as CSV.
     *
     * Same columns as the screen plus the wallet reference (plan Appendix
     * C.5). The download reuses the tab's filters and writes one
     * `general_harvest.export` audit row with them.
     */
    private function exportGeneralHarvest(): void
    {
        $export = $this->generalHarvestExportPayload();

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="general-harvest-ledger_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, self::generalHarvestCsvHeader());

        foreach ($export['rows'] as $row) {
            fputcsv($out, self::generalHarvestCsvRow($row));
        }

        fclose($out);
        exit;
    }

    /**
     * The filtered General Harvest export rows and the one audit row they
     * write.
     *
     * Public so the gate can assert the `general_harvest.export` row and
     * the exact CSV payload without streaming a file out of the harness;
     * the routed `export()` still exits after the CSV (P21E-T12).
     *
     * @return array{filters:array<string, string|int>, rows:list<array<string, mixed>>}
     */
    public function generalHarvestExportPayload(): array
    {
        $filters = GeneralHarvestAward::normaliseFilters([
            'user_id' => Request::string('user_id'),
            'level'   => Request::string('level'),
            'status'  => Request::string('status'),
            'from'    => Request::string('from'),
            'to'      => Request::string('to'),
            'q'       => Request::string('q'),
        ]);

        $rows  = array_map(
            [self::class, 'generalHarvestCsvRow'],
            GeneralHarvestAward::exportRows($filters)
        );
        $admin = auth_user();

        AuditLog::record([
            'action'       => 'general_harvest.export',
            'actor_id'     => $admin === null ? null : (int) $admin['id'],
            'actor_role'   => $admin === null ? null : (string) $admin['role'],
            'subject_type' => 'general_harvest_award',
            'subject_id'   => null,
            'meta'         => [
                'tab'     => self::TAB_GENERAL_HARVEST,
                'filters' => $filters,
                'rows'    => count($rows),
            ],
        ]);

        return ['filters' => $filters, 'rows' => $rows];
    }

    /**
     * GET /admin/commissions/export?ledger=binary — the filtered Product
     * Binary awards as CSV. Audited once as `binary.export` with the tab,
     * the filters and the row count (plan §15.4).
     */
    private function exportBinary(): void
    {
        $filters = BinaryAward::normaliseFilters([
            'kind'       => Request::string('kind'),
            'status'     => Request::string('status'),
            'side'       => Request::string('side'),
            'product_id' => Request::string('product_id'),
            'from'       => Request::string('from'),
            'to'         => Request::string('to'),
            'q'          => Request::string('q'),
        ]);

        $rows  = BinaryAward::exportRows(null, $filters);
        $admin = auth_user();

        AuditLog::record([
            'action'       => 'binary.export',
            'actor_id'     => $admin === null ? null : (int) $admin['id'],
            'actor_role'   => $admin === null ? null : (string) $admin['role'],
            'subject_type' => 'binary_award',
            'subject_id'   => null,
            'meta'         => [
                'tab'     => self::TAB_BINARY,
                'filters' => $filters,
                'rows'    => count($rows),
            ],
        ]);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="binary-awards_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, BinaryAward::csvHeader());

        foreach (BinaryAward::csvRows($rows) as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * The General Harvest ledger CSV header — exactly plan Appendix C.5.
     *
     * @return list<string>
     */
    public static function generalHarvestCsvHeader(): array
    {
        return [
            'Award ID', 'Credited at', 'Member', 'Position', 'Node (seq)', 'Level',
            'Block', 'Queue (N)', 'Threshold', 'Amount', 'Status', 'Wallet reference',
        ];
    }

    /**
     * One General Harvest ledger CSV row.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function generalHarvestCsvRow(array $row): array
    {
        $level = (int) $row['level'];
        $seq   = (int) $row['seq'];
        $slots = GeneralHarvestLevel::slots($level);
        $block = (int) intdiv($seq, $slots);
        $from  = $block * $slots + 1;
        $to    = $from + $slots - 1;

        return [
            (string) $row['id'],
            (string) $row['credited_at'],
            (string) $row['username'],
            $row['position'] === null ? '' : (string) (int) $row['position'],
            (string) $seq,
            (string) $level,
            '#' . (string) $from . '–#' . (string) $to,
            (string) (int) $row['n_at_award'],
            (string) (int) $row['threshold_n'],
            number_format((float) $row['amount'], 2, '.', ''),
            (string) $row['status'],
            $row['ledger_id'] === null ? '' : (string) (int) $row['ledger_id'],
        ];
    }

    /**
     * ETag scope for the ledger.
     *
     * `this_month` counts from the first of the month, so the day is part
     * of the scope: a tab left open across midnight revalidates on its next
     * poll instead of showing yesterday's month figure. `Live::scope()`
     * carries the query string, so the tabs never share an ETag; the tab
     * and the selected Royalty period are also named explicitly so a
     * stale page can never answer for another tab's data (P19D-T16).
     */
    private function listScope(int $userId): string
    {
        return Live::scope($_GET, $userId)
            . '|day:' . date('Y-m-d')
            . '|tab:' . self::tab()
            . '|period:' . trim(Request::string('period'));
    }

    /** @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int} */
    private function listLiveMeta(): array
    {
        return Live::meta('admin.commissions', $this->listScope((int) (auth_user()['id'] ?? 0)));
    }

    /**
     * JSON feed for the open commission ledger.
     *
     * The 304 check runs before context(), so an unchanged ledger costs
     * one revision lookup and no list/aggregate queries.
     */
    private function listFeed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        session_write_close();

        $surface = 'admin.commissions';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->listScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->context()));
        } catch (Throwable $e) {
            error_log('[ecowealth] commission ledger feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the commission ledger. Please try again.', 500);
        }
    }

    /**
     * Render the ledger payload for the tab in `$data['tab']`.
     *
     * Public so tests can assert the fragments without going through
     * Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_REPEAT) {
            return $this->repeatFeedPayload($revision, $etag, $data);
        }

        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_ROYALTY) {
            return $this->royaltyFeedPayload($revision, $etag, $data);
        }

        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_TEAM_HARVEST) {
            return $this->teamHarvestFeedPayload($revision, $etag, $data);
        }

        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_GENERAL_HARVEST) {
            return $this->generalHarvestFeedPayload($revision, $etag, $data);
        }

        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_BINARY) {
            return $this->binaryFeedPayload($revision, $etag, $data);
        }

        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => 'admin.commissions',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'commission-header' => View::capture('admin/commissions/_header', [
                    'pager' => $pager,
                ]),
                'commission-stats' => View::capture('admin/commissions/_stats', [
                    'stats' => $data['stats'],
                ]),
                'commission-rows' => View::capture('admin/commissions/_rows', [
                    'commissions' => $data['commissions'],
                    'filters'     => $data['filters'],
                ]),
                'commission-pagination' => View::capture('admin/commissions/_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalListUrl($pager),
            ],
        ];
    }

    /**
     * The repeat tab's fragments: the heading, counters, closed months,
     * rows and pagination. The filter form never travels in a fragment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function repeatFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => 'admin.commissions',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'repeat-header' => View::capture('admin/commissions/_repeat_header', [
                    'pager' => $pager,
                ]),
                'repeat-stats' => View::capture('admin/commissions/_repeat_stats', [
                    'stats' => $data['stats'],
                ]),
                'repeat-periods' => View::capture('admin/commissions/_repeat_periods', [
                    'periods' => $data['periods'],
                ]),
                'repeat-rows' => View::capture('admin/commissions/_repeat_rows', [
                    'awards'  => $data['awards'],
                    'filters' => $data['filters'],
                ]),
                'repeat-pagination' => View::capture('admin/commissions/_repeat_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalListUrl($pager),
            ],
        ];
    }

    /**
     * The General Harvest tab's fragments: the heading, counters, the ten
     * levels, rows and pagination, under their own
     * `general-harvest-*` names so an open General Harvest tab never
     * patch a package, repeat, Royalty or Team Harvest region. The filter
     * form never travels in a fragment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function generalHarvestFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => 'admin.commissions',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'general-harvest-header' => View::capture('admin/commissions/_general_harvest_header', [
                    'stats'   => $data['stats'],
                    'pager'   => $pager,
                    'armed'   => $data['armed'],
                    'enabled' => $data['enabled'],
                ]),
                'general-harvest-stats' => View::capture('admin/commissions/_general_harvest_stats', [
                    'stats' => $data['stats'],
                ]),
                'general-harvest-levels' => View::capture('admin/commissions/_general_harvest_levels', [
                    'levels'     => $data['levels'],
                    'max_levels' => $data['max_levels'],
                ]),
                'general-harvest-rows' => View::capture('admin/commissions/_general_harvest_rows', [
                    'awards'  => $data['awards'],
                    'filters' => $data['filters'],
                ]),
                'general-harvest-pagination' => View::capture('admin/commissions/_general_harvest_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalListUrl($pager),
            ],
        ];
    }

    /**
     * The Binary tab's fragments: the heading, counters, rows and
     * pagination, under their own `binary-*` names so an open Binary tab
     * never patches a package, repeat, Royalty, Team Harvest or General
     * Harvest region. The filter form never travels in a fragment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function binaryFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => 'admin.commissions',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'binary-header' => View::capture('admin/commissions/_binary_header', [
                    'pager' => $pager,
                ]),
                'binary-stats' => View::capture('admin/commissions/_binary_stats', [
                    'stats' => $data['stats'],
                ]),
                'binary-rows' => View::capture('admin/commissions/_binary_rows', [
                    'awards'  => $data['awards'],
                    'filters' => $data['filters'],
                ]),
                'binary-pagination' => View::capture('admin/commissions/_binary_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalListUrl($pager),
            ],
        ];
    }

    /**
     * The Royalty tab's fragments: the heading, counters, closed months,
     * rows and pagination, under their own `commission-royalty-*` names
     * so an open Royalty tab never patches a package or repeat region.
     * The filter form never travels in a fragment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function royaltyFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => 'admin.commissions',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'commission-royalty-ops' => View::capture('admin/commissions/_royalty_ops', [
                    'ops'         => $data['royalty_ops'] ?? null,
                    'preview'     => $data['preview'] ?? null,
                    'open_period' => $data['open_period'] ?? '',
                ]),
                'commission-royalty-header' => View::capture('admin/commissions/_royalty_header', [
                    'pager'         => $pager,
                    'period'        => $data['filters']['period'],
                    'period_status' => $data['stats']['period_status'],
                    'preview_mode'  => $data['preview_mode'],
                ]),
                'commission-royalty-stats' => View::capture('admin/commissions/_royalty_stats', [
                    'stats'        => $data['stats'],
                    'preview'      => $data['preview'],
                    'preview_mode' => $data['preview_mode'],
                    'open_period'  => $data['open_period'],
                ]),
                'commission-royalty-periods' => View::capture('admin/commissions/_royalty_periods', [
                    'periods'  => $data['periods'],
                    'selected' => $data['filters']['period'],
                    'open'     => $data['open_period'],
                ]),
                'commission-royalty-rows' => View::capture('admin/commissions/_royalty_rows', [
                    'awards'       => $data['awards'],
                    'filters'      => $data['filters'],
                    'preview_mode' => $data['preview_mode'],
                    'open_period'  => $data['open_period'],
                ]),
                'commission-royalty-pagination' => View::capture('admin/commissions/_royalty_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalListUrl($pager),
            ],
        ];
    }

    /**
     * The Team Harvest tab's fragments: the heading, counters, the ten-row
     * level distribution, rows and pagination, under their own
     * `team-harvest-*` names so an open Team Harvest tab never patches a
     * package, repeat or Royalty region. The filter form never travels in a
     * fragment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function teamHarvestFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => 'admin.commissions',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'team-harvest-header' => View::capture('admin/commissions/_team_harvest_header', [
                    'stats'    => $data['stats'],
                    'pager'    => $pager,
                    'seeded'   => $data['seeded'],
                    'baseline' => $data['baseline'],
                    'enabled'  => $data['enabled'],
                ]),
                'team-harvest-stats' => View::capture('admin/commissions/_team_harvest_stats', [
                    'stats' => $data['stats'],
                ]),
                'team-harvest-levels' => View::capture('admin/commissions/_team_harvest_levels', [
                    'levels'     => $data['levels'],
                    'max_levels' => $data['max_levels'],
                ]),
                'team-harvest-rows' => View::capture('admin/commissions/_team_harvest_rows', [
                    'awards'  => $data['awards'],
                    'filters' => $data['filters'],
                ]),
                'team-harvest-pagination' => View::capture('admin/commissions/_team_harvest_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalListUrl($pager),
            ],
        ];
    }

    /** The ledger URL with an out-of-range page clamped to a real page. */
    private function canonicalListUrl(Paginator $pager): string
    {
        $query = [];

        foreach ($_GET as $key => $value) {
            if (is_scalar($value) && $key !== 'XTransformPort') {
                $query[(string) $key] = (string) $value;
            }
        }

        unset($query['page']);

        if ($pager->page > 1) {
            $query['page'] = (string) $pager->page;
        }

        $qs = http_build_query($query);

        return url('admin/commissions' . ($qs !== '' ? '?' . $qs : ''));
    }
}
