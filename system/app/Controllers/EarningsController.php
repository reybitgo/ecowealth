<?php
declare(strict_types=1);

/* ==============================================================
 *  EarningsController — the member's commission statement
 *  (plan §7 Phase 10; reference MemberController::earnings and
 *  views/member/earnings.php; Phase 17E adds the repeat-purchase tab
 *  of plan §9; Phase 19E adds the Royalty tab of plan §9; Phase 20F
 *  adds the Team Harvest tab of plan §10)
 *
 *  Route (Appendix A): GET /earnings   guard `member`
 *
 *  Four ledgers, one page, one URL:
 *
 *      /earnings                     package referral awards (`commissions`)
 *      /earnings?ledger=repeat       repeat-purchase awards
 *                                    (`unilevel_awards`)
 *      /earnings?ledger=royalty      monthly Royalty awards
 *                                    (`royalty_awards`)
 *      /earnings?ledger=team_harvest Team Harvest levels
 *                                    (`team_harvest_awards`)
 *
 *  The package tab is the Phase 10–11 statement, unchanged: three
 *  summary groups, the level breakdown, the skipped-award notice and
 *  one row per activation. The repeat tab is the member's upline
 *  statement: the six counters are replaced by five that never include
 *  an "as buyer" figure (Rule 9), and a two-clock panel explains why a
 *  bonus did or did not reach them.
 *
 *  Phase 18 adds the escrow states to that tab: the counters gain a
 *  **Pending release** tile, the level cards show pending beside
 *  credited, and the rows explain when an award becomes money — the
 *  release date once the order was delivered, or the snapshotted hold
 *  before that (plan §10.3). The reads are the model's own escrow
 *  fields, so the page and the live feed never disagree.
 *
 *  Phase 19E adds the Royalty tab: the rank card (current rank, the
 *  next-rank progress, this month's group and personal sales against the
 *  quota, the "if this month closed now" projection), the five counters
 *  and one row per closed month. The rank card reads
 *  `RoyaltyService::statusFor()` — the engine's own projection — so the
 *  page can never promise an amount the close would not pay (plan §14.11).
 *
 *  Phase 20F adds the Team Harvest tab: the position and the instant the
 *  gate opened, the next level's progress, the whole 3×10 matrix with
 *  every empty slot and its amount, the paginated roster of qualified
 *  teammates, and one row per level the member has been paid (or had
 *  recorded before go-live). Every number is `TeamHarvestService`'s —
 *  `statusFor()` for the position, the levels, the amounts and the
 *  progress, `matrixFor()` for the roster — so the page formats and never
 *  computes (plan §10 Rule 9). A member who has not qualified reads the
 *  gate, not an empty matrix (Appendix C.8).
 *
 *  Members only ever see their own rows (`WHERE user_id = ?`), the same
 *  ownership rule as orders and the wallet statement. The tab is a
 *  query parameter only (D-16); a `ledger` value other than `repeat`,
 *  `royalty` or `team_harvest` selects the package tab, so a hand-edited
 *  URL can never break the page. The Team Harvest filters are applied
 *  with the session member's id forced in, so `?user_id=` cannot widen
 *  the statement or its CSV (Rule 11).
 *
 *  The page is one per-member live surface (`member.earnings:{id}`).
 *  The four tabs share it: a repeat, Royalty or Team Harvest award bumps
 *  the same revision the package tab already listens to, so an open tab
 *  refreshes without a second surface (Rule 10). The repeat, Royalty and
 *  Team Harvest tabs also mix the shared genealogy revision into their
 *  ETag: their level cards, rank requirements and matrix roster list the
 *  team, so a registration, activation or status change refreshes them
 *  too, exactly like the genealogy page.
 * ============================================================== */

final class EarningsController
{
    /** Package referral statement. */
    public const TAB_PACKAGE = 'package';

    /** Repeat-purchase (unilevel ladder) statement. */
    public const TAB_REPEAT = 'repeat';

    /** Monthly Royalty statement (Phase 19E). */
    public const TAB_ROYALTY = 'royalty';

    /** Team Harvest levels and matrix (Phase 20F). */
    public const TAB_TEAM_HARVEST = 'team_harvest';

    /** General Harvest bonus levels and blocks (Phase 21F). */
    public const TAB_GENERAL_HARVEST = 'general_harvest';

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        $userId  = (int) $user['id'];
        $isAdmin = is_admin($user);
        $tab     = self::tab();

        /* The same URL doubles as the JSON feed. The surface is per member,
         * so one member's award never refreshes another member's page; the
         * query string is part of the ETag scope, so the two tabs never
         * share a cache entry. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        if (Member::find($userId) === null) {
            View::error(404);
        }

        View::page($isAdmin ? 'admin' : 'member', 'member/earnings', $this->context($userId, $tab) + [
            'tab'         => $tab,
            'page_title'  => 'Earnings',
            'current_nav' => $isAdmin ? 'admin.membership' : 'earnings',
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
            'live'        => Live::meta(
                Live::earningsSurface($userId),
                $this->earningsScope($userId)
            ),
        ]);
    }

    /** The tab named by `?ledger=`; anything but the three names is the package tab. */
    public static function tab(): string
    {
        return match (strtolower(trim(Request::string('ledger')))) {
            self::TAB_REPEAT       => self::TAB_REPEAT,
            self::TAB_ROYALTY      => self::TAB_ROYALTY,
            self::TAB_TEAM_HARVEST => self::TAB_TEAM_HARVEST,
            self::TAB_GENERAL_HARVEST => self::TAB_GENERAL_HARVEST,
            default                => self::TAB_PACKAGE,
        };
    }

    /**
     * The context for the tab named by the request (or by `$tab`).
     *
     * @return array<string, mixed>
     */
    public function context(int $userId, ?string $tab = null): array
    {
        $tab ??= self::tab();

        return match ($tab) {
            self::TAB_REPEAT       => $this->repeatContext($userId),
            self::TAB_ROYALTY      => $this->royaltyContext($userId),
            self::TAB_TEAM_HARVEST => $this->teamHarvestContext($userId),
            self::TAB_GENERAL_HARVEST => $this->generalHarvestContext($userId),
            default                => $this->earningsContext($userId),
        };
    }

    /**
     * Everything the package statement renders, built once for the page
     * and its feed. Public so the view tests can assert the real screen
     * data.
     *
     * @return array<string, mixed>
     */
    public function earningsContext(int $userId): array
    {
        $filters = Commission::normaliseFilters([
            'type'   => Request::string('type'),
            'level'  => Request::string('level'),
            'status' => Request::string('status'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
            'q'      => Request::string('q'),
        ]);

        $pager = Paginator::fromTableRequest(Commission::countForUser($userId, $filters));
        $rows  = Commission::pageForUser($userId, $filters, $pager);

        return [
            'tab'           => self::TAB_PACKAGE,
            'user_id'       => $userId,
            'totals'        => Commission::totalsForUser($userId),
            'levels'        => Commission::levelBreakdown($userId),
            'filters'       => $filters,
            'pager'         => $pager,
            'commissions'   => $rows,
            'wallet_url'    => url('wallet'),
            'genealogy_url' => url('genealogy'),
            'package_url'   => url('earnings'),
            'repeat_url'    => url('earnings?ledger=repeat'),
            'statement_url' => url('earnings'),
            'export_url'    => url('earnings/export' . self::queryString($filters)),
        ];
    }

    /**
     * Everything the repeat-purchase statement renders (17E, plan §9).
     *
     * Every read is scoped to the session member (`$userId`), so a
     * `?user_id=` in the URL is ignored: there is no parameter that can
     * widen the query (Rule 11, P17E-T11).
     *
     * The tab has **no "as buyer" figure** (Rule 9). A member earns
     * nothing on their own purchase; their own purchases appear only in
     * the two-clock status panel, because they feed next month's Active
     * flag.
     *
     * @return array<string, mixed>
     */
    public function repeatContext(int $userId): array
    {
        $filters = UnilevelAward::normaliseFilters([
            'level'      => Request::string('level'),
            'status'     => Request::string('status'),
            'product_id' => Request::string('product_id'),
            'from'       => Request::string('from'),
            'to'         => Request::string('to'),
            'q'          => Request::string('q'),
        ]);

        $pager = Paginator::fromTableRequest(UnilevelAward::countForUser($userId, $filters));
        $rows  = UnilevelAward::pageForUser($userId, $filters, $pager);

        return [
            'tab'           => self::TAB_REPEAT,
            'user_id'       => $userId,
            'totals'        => UnilevelAward::totalsForUser($userId),
            'levels'        => $this->repeatLevels($userId),
            'filters'       => $filters,
            'pager'         => $pager,
            'awards'        => $rows,
            'products'      => Product::all(),
            'status'        => UnilevelCycle::memberStatus($userId),
            'wallet_url'    => url('wallet'),
            'genealogy_url' => url('genealogy'),
            'shop_url'      => url('shop'),
            'package_url'   => url('earnings'),
            'repeat_url'    => url('earnings?ledger=repeat'),
            'statement_url' => url('earnings?ledger=repeat'),
            'export_url'    => url('earnings/export' . self::repeatQueryString($filters)),
        ];
    }

    /**
     * Everything the Royalty statement renders (19E, plan §9).
     *
     * Every read is scoped to the session member (`$userId`), so a
     * `?user_id=` in the URL is ignored: there is no parameter that can
     * widen the query (Rule 1, P19E-T6).
     *
     * The rank card is `RoyaltyService::statusFor()`, the engine's own
     * projection for the **open** month: rank, the next-rank progress,
     * this month's PS/GS against the quota and the "if this month closed
     * now" amount (plan §14.11). The rows are one per closed month; the
     * latest of them is the stats card's "this period" figure.
     *
     * @return array<string, mixed>
     */
    public function royaltyContext(int $userId): array
    {
        $filters = self::royaltyFilters();

        $pager     = Paginator::fromTableRequest(RoyaltyAward::countForUser($userId, $filters));
        $statement = (new RoyaltyService())->statusFor($userId);
        $totals    = RoyaltyAward::totalsForUser($userId);

        return [
            'tab'           => self::TAB_ROYALTY,
            'user_id'       => $userId,
            'royalty'       => $statement,
            'totals'        => $totals,
            'stats'         => $this->royaltyStats($totals, $statement),
            'filters'       => $filters,
            'periods'       => RoyaltyAward::periodsForUser($userId),
            'pager'         => $pager,
            'awards'        => RoyaltyAward::pageForUser($userId, $filters, $pager),
            'wallet_url'    => url('wallet'),
            'genealogy_url' => url('genealogy'),
            'package_url'   => url('earnings'),
            'repeat_url'    => url('earnings?ledger=repeat'),
            'royalty_url'   => url('earnings?ledger=royalty'),
            'statement_url' => url('earnings?ledger=royalty'),
            'export_url'    => url('earnings/export' . self::royaltyQueryString($filters)),
        ];
    }

    /**
     * Everything the Team Harvest statement renders (20F, plan §10).
     *
     * Every read is scoped to the session member: the award filters carry
     * `user_id` from `$userId` and never from the request, and the CSV is
     * scoped in the model, so a hand-edited `?user_id=` can widen neither
     * (Rule 11, P20F-T8/T16).
     *
     * The card's numbers are `TeamHarvestService::statusFor()` — the same
     * read the admin member panel calls — and the roster page is
     * `matrixFor()` (`HarvestQueue::matrix()`), so the view formats what
     * the engine and the queue model computed and nothing else (Rule 9).
     *
     * The roster has its own pager (`roster_page` / `roster_per_page`), so
     * moving the awards table never moves the matrix's roster and a member
     * with 500 teammates still reads ten levels and one page of names
     * (P20F-T5/T19).
     *
     * @return array<string, mixed>
     */
    public function teamHarvestContext(int $userId): array
    {
        $filters = self::teamHarvestFilters($userId);
        $service = new TeamHarvestService();

        $pager  = Paginator::fromTableRequest(TeamHarvestAward::count($filters));
        $awards = $pager->total === 0 ? [] : TeamHarvestAward::select($filters, $pager);

        $status = $service->statusFor($userId);

        /* Clamped by the Paginator, so an out-of-range `roster_page` reads
         * the last real page instead of an empty card. */
        $rosterPager = Paginator::fromTableRequest(
            (int) ($status['teammates'] ?? 0),
            'roster_page',
            'roster_per_page'
        );

        $roster = (bool) ($status['queued'] ?? false)
            ? $service->matrixFor(
                $userId,
                $rosterPager->offset,
                $rosterPager->perPage,
                (int) ($status['teammates'] ?? 0),
                (int) ($status['position'] ?? 0)
            )
            : [];

        return [
            'tab'           => self::TAB_TEAM_HARVEST,
            'user_id'       => $userId,
            'team_harvest'  => $status,
            'filters'       => $filters,
            'pager'         => $pager,
            'awards'        => $awards,
            'roster'        => $roster,
            'roster_pager'  => $rosterPager,
            'wallet_url'    => url('wallet'),
            'genealogy_url' => url('genealogy'),
            'package_url'   => url('earnings'),
            'repeat_url'    => url('earnings?ledger=repeat'),
            'royalty_url'   => url('earnings?ledger=royalty'),
            'team_harvest_url' => url('earnings?ledger=team_harvest'),
            'statement_url' => url('earnings?ledger=team_harvest'),
            'export_url'    => url('earnings/export' . self::teamHarvestQueryString($filters)),
        ];
    }

    /**
     * Everything the General Harvest statement renders (21F, plan §10).
     *
     * Every read is scoped to the session member (`$userId`): the award
     * filters carry `user_id` from `$userId` and never from the request,
     * and the CSV is scoped in the model, so a hand-edited `?user_id=`
     * can widen neither (Rule 12).
     *
     * The board card is `GeneralHarvestService::statusFor()` — the same
     * read the admin member panel and the dashboard tile use — so the
     * page never promises an amount the engine would not pay (Rule 9).
     * The award rows are `GeneralHarvestAward::select()` with `user_id`
     * forced in, paginated like every other tab, and each row links to
     * its wallet movement by `ref_id` (= the award id).
     *
     * @return array<string, mixed>
     */
    public function generalHarvestContext(int $userId): array
    {
        $filters = self::generalHarvestFilters($userId);
        $service = new GeneralHarvestService();

        $pager  = Paginator::fromTableRequest(GeneralHarvestAward::count($filters));
        $awards = $pager->total === 0 ? [] : GeneralHarvestAward::select($filters, $pager);
        $status = $service->statusFor($userId);

        return [
            'tab'           => self::TAB_GENERAL_HARVEST,
            'user_id'       => $userId,
            'general_harvest'  => $status,
            'filters'       => $filters,
            'pager'         => $pager,
            'awards'        => $awards,
            'wallet_url'    => url('wallet'),
            'genealogy_url' => url('genealogy'),
            'package_url'   => url('earnings'),
            'repeat_url'    => url('earnings?ledger=repeat'),
            'royalty_url'   => url('earnings?ledger=royalty'),
            'team_harvest_url' => url('earnings?ledger=team_harvest'),
            'general_harvest_url' => url('earnings?ledger=general_harvest'),
            'statement_url' => url('earnings?ledger=general_harvest'),
            'export_url'    => url('earnings/export' . self::generalHarvestQueryString($filters)),
        ];
    }

    /**
     * The General Harvest statement's validated filters, with the session
     * member's id **forced** in (21F Rule 12).
     *
     * The level and status filters narrow the member's own rows;
     * `q`, `from` and `to` are kept for parity with the admin tab but a
     * member's search can only ever match their own rows, so they carry
     * no disclosure risk. `q` is not forced empty here because the
     * statement lists the member's own levels and a member may want to
     * find a level by its number — but it can never widen past their id.
     *
     * @return array{user_id:int, level:string, status:string, from:string, to:string, q:string}
     */
    private static function generalHarvestFilters(int $userId): array
    {
        $filters = GeneralHarvestAward::normaliseFilters([
            'user_id' => '',
            'level'   => Request::string('level'),
            'status'  => Request::string('status'),
            'from'    => Request::string('from'),
            'to'      => Request::string('to'),
            'q'       => '',
        ]);

        $filters['user_id'] = max(0, $userId);

        return $filters;
    }

    /**
     * The Team Harvest statement's validated filters, with the session
     * member's id **forced** in (20F Rule 11).
     *
     * The level, status and date range narrow the member's own rows
     * (QA20F-04); `q` is forced empty, because a search over one member's
     * own rows could only match their own name, and a hand-edited URL must
     * not turn the statement into a directory search.
     *
     * @return array{user_id:int, level:string, status:string, from:string, to:string, q:string}
     */
    private static function teamHarvestFilters(int $userId): array
    {
        $filters = TeamHarvestAward::normaliseFilters([
            'user_id' => '',
            'level'   => Request::string('level'),
            'status'  => Request::string('status'),
            'from'    => Request::string('from'),
            'to'      => Request::string('to'),
            'q'       => '',
        ]);

        $filters['user_id'] = max(0, $userId);

        return $filters;
    }

    /**
     * The five counters of the Royalty tab's stats card (plan §9,
     * Appendix C.3).
     *
     * `this_period` is the newest closed month the member has a row for:
     * a member has at most one row per month (D-08), so the newest
     * history row *is* the latest period. It is never the open month — an
     * open month has no stored row, which is why the projection lives on
     * the rank card and not here.
     *
     * @param array{rows:int, paid_rows:int, pending_rows:int, skipped_rows:int,
     *              paid_total:float, pending_total:float,
     *              last_paid:?array{period:string, amount:float}} $totals
     * @param array<string, mixed> $statement RoyaltyService::statusFor()
     * @return array<string, mixed>
     */
    private function royaltyStats(array $totals, array $statement): array
    {
        $history = is_array($statement['history'] ?? null) ? $statement['history'] : [];
        $latest  = $history[0] ?? null;

        return [
            'rows'             => (int) $totals['rows'],
            'paid_all_time'    => (float) $totals['paid_total'],
            'paid_rows'        => (int) $totals['paid_rows'],
            'pending_total'    => (float) $totals['pending_total'],
            'pending_rows'     => (int) $totals['pending_rows'],
            'skipped_rows'     => (int) $totals['skipped_rows'],
            'last_paid'        => $totals['last_paid'],
            'this_period'      => $latest === null ? null : [
                'period' => (string) $latest['period'],
                'rank'   => (int) $latest['rank_level'],
                'amount' => (float) $latest['amount'],
                'status' => (string) $latest['status'],
            ],
            'enabled'          => (bool) ($statement['enabled'] ?? false),
            'payments_enabled' => (bool) ($statement['payments_enabled'] ?? true),
        ];
    }

    /**
     * The 1–10 repeat-purchase breakdown, enriched with the member's
     * team at each level (17E follow-up; 18 adds pending release).
     *
     * The ledger total for a level is unchanged: credited awards at that
     * depth, from `UnilevelAward::levelBreakdown()`, plus the Phase 18
     * `pending_amount` / `pending_count` the same read now returns. What
     * is new is the roster beside it — level 1 is the members the session
     * member sponsored, level 2 their recruits, and so on, exactly the
     * genealogy "Team by level" shape. Each member row carries the
     * money that member's purchases earned at that level — credited and
     * pending release — so the two halves of the card answer "who is at
     * this level?" and "what did they earn me?" from one screen.
     *
     * The team is read once, then paginated per level with namespaced
     * keys (`level1_page`, `level1_per_page`, …) so one crowded level
     * never moves the award table below. A member with no purchase stays
     * in the list at ₱0.00: the roster is the team, not only the payers.
     *
     * @return array<int, array{
     *   amount:float, count:int, pending_amount:float, pending_count:int,
     *   team_count:int, pager:Paginator,
     *   members:list<array{
     *     id:int, username:string, full_name:string, package_name:?string,
     *     member_status:string, amount:float, count:int,
     *     pending_amount:float, pending_count:int
     *   }>
     * }>
     */
    private function repeatLevels(int $userId): array
    {
        $levels = UnilevelAward::levelBreakdown($userId);
        $earned = UnilevelAward::creditedByBuyer($userId);
        $team   = (new SponsorService())->downline($userId, UnilevelAward::LEVEL_MAX);

        $byLevel = [];

        foreach ($team as $row) {
            $byLevel[(int) $row['level']][] = $row;
        }

        for ($level = UnilevelAward::LEVEL_MIN; $level <= UnilevelAward::LEVEL_MAX; $level++) {
            $rows  = $byLevel[$level] ?? [];
            $pager = Paginator::fromTableRequest(
                count($rows),
                'level' . $level . '_page',
                'level' . $level . '_per_page'
            );
            $members = [];

            foreach (array_slice($rows, $pager->offset, $pager->perPage) as $row) {
                $id   = (int) $row['id'];
                $stat = $earned[$level][$id] ?? [
                    'amount'         => 0.0,
                    'count'          => 0,
                    'pending_amount' => 0.0,
                    'pending_count'  => 0,
                ];

                $members[] = [
                    'id'             => $id,
                    'username'       => (string) $row['username'],
                    'full_name'      => (string) ($row['full_name'] ?? ''),
                    'package_name'   => $row['package_name'] === null ? null : (string) $row['package_name'],
                    'member_status'  => (string) $row['member_status'],
                    'amount'         => round((float) $stat['amount'], 2),
                    'count'          => (int) $stat['count'],
                    'pending_amount' => round((float) ($stat['pending_amount'] ?? 0), 2),
                    'pending_count'  => (int) ($stat['pending_count'] ?? 0),
                ];
            }

            $levels[$level]['members']    = $members;
            $levels[$level]['pager']      = $pager;
            $levels[$level]['team_count'] = count($rows);
        }

        return $levels;
    }

    /**
     * CSV statement for the signed-in member.
     *
     * Route (Appendix A extension): GET /earnings/export   guard `member`
     *
     * The package tab keeps its Phase 10 columns and filename. The
     * repeat tab (17E) writes `repeat-purchase-statement_YYYY-MM-DD.csv`
     * with the documented `UnilevelAward` columns — the same 13 columns
     * the admin ledger and the report export use, so a member, an admin
     * and finance read one definition. The Royalty tab (19E) writes
     * `royalty-statement_YYYY-MM-DD.csv` with the Appendix C.6 columns
     * and only the caller's rows. The Team Harvest tab (20F) writes
     * `team-harvest-statement_YYYY-MM-DD.csv` with plan Appendix C.6's
     * six columns, scoped in the **model** (`exportRows(..., $userId)`) so
     * a hand-edited URL cannot widen it, and writing no audit row — a
     * member reading their own statement is the page itself (plan §15.1).
     *
     * @param array<string, string> $params
     */
    public function export(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        if (self::tab() === self::TAB_TEAM_HARVEST) {
            $this->exportTeamHarvest((int) $user['id']);

            return;
        }

        if (self::tab() === self::TAB_GENERAL_HARVEST) {
            $this->exportGeneralHarvest((int) $user['id']);

            return;
        }

        if (self::tab() === self::TAB_ROYALTY) {
            $this->exportRoyalty((int) $user['id']);

            return;
        }

        if (self::tab() === self::TAB_REPEAT) {
            $this->exportRepeat((int) $user['id']);

            return;
        }

        $userId  = (int) $user['id'];
        $filters = Commission::normaliseFilters([
            'type'   => Request::string('type'),
            'level'  => Request::string('level'),
            'status' => Request::string('status'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
            'q'      => Request::string('q'),
        ]);

        $rows = Commission::exportRows($userId, $filters);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="unilevel-statement_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, [
            'Award', 'Awarded at', 'Level', 'Type', 'Source', 'Source name',
            'Package', 'Amount', 'Status', 'Reason', 'Activation', 'Ledger',
        ]);

        foreach ($rows as $row) {
            fputcsv($out, [
                (int) $row['id'],
                (string) $row['created_at'],
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
     * GET /earnings/export?ledger=repeat — the member's own repeat rows.
     *
     * No audit row: a member reading their own statement is the page
     * itself, and the package export has never been audited either
     * (plan §15.1 lists no member-export audit rule).
     */
    private function exportRepeat(int $userId): void
    {
        $filters = UnilevelAward::normaliseFilters([
            'level'      => Request::string('level'),
            'status'     => Request::string('status'),
            'product_id' => Request::string('product_id'),
            'from'       => Request::string('from'),
            'to'         => Request::string('to'),
            'q'          => Request::string('q'),
        ]);

        $rows = UnilevelAward::exportRows($userId, $filters);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="repeat-purchase-statement_' . date('Y-m-d') . '.csv"');
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
     * GET /earnings/export?ledger=royalty — the member's own Royalty rows.
     *
     * The CSV reuses the screen's period/rank/status filters and writes
     * the Appendix C.6 columns. `$userId` scopes it, so a hand-edited
     * `?user_id=` cannot export another member's months (Rule 1). Like
     * the package and repeat statements, a member reading their own
     * statement is the page itself: no audit row (plan §15.1).
     */
    private function exportRoyalty(int $userId): void
    {
        $rows = RoyaltyAward::exportRows(self::royaltyFilters(), null, $userId);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="royalty-statement_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, self::royaltyCsvHeader());

        foreach (self::royaltyCsvRows($rows) as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * GET /earnings/export?ledger=team_harvest — the member's own levels.
     *
     * The screen's level/status/date filters narrow the file too (QA20F-04),
     * and the member is forced in at the model (`exportRows()` sets
     * `user_id` from `$userId`), so an appended `&user_id=` changes
     * nothing (Rule 11, P20F-T8/T16). No audit row: the package, repeat
     * and Royalty statements have never written one either (plan §15.1).
     */
    private function exportTeamHarvest(int $userId): void
    {
        $rows = TeamHarvestAward::exportRows(self::teamHarvestFilters($userId), null, $userId);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="team-harvest-statement_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, self::teamHarvestCsvHeader());

        foreach (self::teamHarvestCsvRows($rows) as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * GET /earnings/export?ledger=general_harvest — the member's own
     * General Harvest award rows.
     *
     * The columns are exactly plan Appendix C.6. The member is forced in
     * at the model (`exportRows(..., $userId)`), so a hand-edited
     * `?user_id=` cannot widen it (Rule 12). No audit row: the package,
     * repeat and Royalty statements have never written one either
     * (plan §15.1).
     */
    private function exportGeneralHarvest(int $userId): void
    {
        $rows = GeneralHarvestAward::exportRows(self::generalHarvestFilters($userId), null, $userId);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="general-harvest-statement_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, self::generalHarvestCsvHeader());

        foreach (self::generalHarvestCsvRows($rows) as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * The General Harvest statement CSV header — exactly plan Appendix C.6.
     *
     * @return list<string>
     */
    public static function generalHarvestCsvHeader(): array
    {
        return ['Date', 'Level', 'Block', 'Queue at award', 'Threshold', 'Amount', 'Status'];
    }

    /**
     * The General Harvest statement CSV rows, one per decided level.
     *
     * The `Status` column carries the **member** label (Appendix C.7:
     * `baseline` reads *Before go-live*). `Date` is the decision instant.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<string>>
     */
    public static function generalHarvestCsvRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $status = (string) $row['status'];

            $out[] = [
                (string) ($row['credited_at'] ?? ''),
                (string) (int) $row['level'],
                (string) (int) $row['n_at_award'],
                (string) (int) $row['threshold_n'],
                number_format((float) $row['amount'], 2, '.', ''),
                GeneralHarvestAward::MEMBER_STATUS_LABELS[$status] ?? ucfirst($status),
            ];
        }

        return $out;
    }

    /**
     * The Team Harvest statement CSV header — exactly plan Appendix C.6.
     *
     * @return list<string>
     */
    public static function teamHarvestCsvHeader(): array
    {
        return ['Date', 'Level', 'Teammates at award', 'Threshold', 'Amount', 'Status'];
    }

    /**
     * The Team Harvest statement CSV rows, one per level.
     *
     * The `Status` column carries the **member** label (Appendix C.7:
     * `baseline` reads *Before go-live*), because this file is the
     * member's own statement; the admin ledger CSV keeps the stored
     * value. `Date` is the decision instant — the credit time for a paid
     * level, the go-live instant for a level recorded before it.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<string>>
     */
    public static function teamHarvestCsvRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $status = (string) $row['status'];

            $out[] = [
                (string) ($row['credited_at'] ?? ''),
                (string) (int) $row['level'],
                (string) (int) $row['n_at_award'],
                (string) (int) $row['threshold_n'],
                number_format((float) $row['amount'], 2, '.', ''),
                TeamHarvestAward::MEMBER_STATUS_LABELS[$status] ?? ucfirst($status),
            ];
        }

        return $out;
    }

    /**
     * The Royalty statement CSV header — exactly plan Appendix C.6.
     *
     * @return list<string>
     */
    public static function royaltyCsvHeader(): array
    {
        return [
            'Period', 'Rank', 'Group sales', 'Personal sales', 'Quota',
            'Group %', 'Personal %', 'Amount', 'Status', 'Reason', 'Paid at',
        ];
    }

    /**
     * The Royalty statement CSV rows, one per closed month.
     *
     * The `Status` column carries the **member** label (Appendix C.8:
     * `skipped` reads *Not paid*), because this file is the member's own
     * statement; the admin ledger CSV keeps the stored value. `Paid at`
     * is the wallet credit time, empty for anything that never reached a
     * wallet.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<string>>
     */
    public static function royaltyCsvRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $paidAt = $row['credited_at'] ?? null;

            $out[] = [
                (string) $row['period'],
                RoyaltyRank::NAMES[(int) $row['rank_level']] ?? 'Rank ' . (int) $row['rank_level'],
                number_format((float) $row['group_volume'], 2, '.', ''),
                number_format((float) $row['personal_volume'], 2, '.', ''),
                number_format((float) $row['quota'], 2, '.', ''),
                number_format((float) $row['group_pct'], 3, '.', ''),
                number_format((float) $row['personal_pct'], 3, '.', ''),
                number_format((float) $row['amount'], 2, '.', ''),
                RoyaltyAward::MEMBER_STATUS_LABELS[$status] ?? ucfirst($status),
                trim((string) ($row['reason'] ?? '')),
                $paidAt === null || (string) $paidAt === '' ? '' : (string) $paidAt,
            ];
        }

        return $out;
    }

    /**
     * The Royalty statement's validated filters (period, rank, status).
     *
     * There is deliberately no free-text search: every row is the
     * session member's own, so a search box could only match their own
     * name. `q` is forced empty so a hand-edited URL cannot turn the
     * statement into a directory search.
     *
     * @return array{period:string, status:string, rank:string, q:string}
     */
    private static function royaltyFilters(): array
    {
        return RoyaltyAward::normaliseFilters([
            'period' => Request::string('period'),
            'status' => Request::string('status'),
            'rank'   => Request::string('rank'),
            'q'      => '',
        ]);
    }

    /**
     * The current package filters as an export query string ('' when none).
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
     * The repeat export query string: the tab first, then the filters.
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
     * The Team Harvest export query string: the tab first, then the
     * filters. `user_id` never travels: the model forces it (Rule 11).
     *
     * @param array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
     */
    private static function teamHarvestQueryString(array $filters): string
    {
        $query = ['ledger' => self::TAB_TEAM_HARVEST] + array_filter(
            [
                'level'  => (string) $filters['level'],
                'status' => (string) $filters['status'],
                'from'   => (string) $filters['from'],
                'to'     => (string) $filters['to'],
            ],
            static fn (string $value): bool => $value !== ''
        );

        return '?' . http_build_query($query);
    }

    /**
     * The General Harvest export query string: the tab first, then the
     * filters. `user_id` never travels: the model forces it (Rule 12).
     *
     * @param array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
     */
    private static function generalHarvestQueryString(array $filters): string
    {
        $query = ['ledger' => self::TAB_GENERAL_HARVEST] + array_filter(
            [
                'level'  => (string) $filters['level'],
                'status' => (string) $filters['status'],
                'from'   => (string) $filters['from'],
                'to'     => (string) $filters['to'],
            ],
            static fn (string $value): bool => $value !== ''
        );

        return '?' . http_build_query($query);
    }

    /**
     * The Royalty export query string: the tab first, then the filters.
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
     * ETag scope for the statement.
     *
     * The "last award" label is relative and `this_month` counts from
     * CURDATE(), so the hour is part of the scope: a tab left open
     * revalidates without a write instead of showing a stale age.
     * `Live::scope()` carries the query string, so the tabs never share
     * an ETag.
     *
     * The repeat tab also lists the member's team by level, so it mixes
     * in the shared `GENEALOGY_TEAM` revision: a registration, an
     * activation or a member status change moves a roster even though no
     * award row was written (the same revision the genealogy and
     * dashboard pages use).
     *
     * The Royalty tab (19E) reads the team twice — the rank requirements
     * and the next-rank progress — and its MTD figures belong to one
     * calendar month, so it mixes the team revision, the configuration
     * revision (a ladder or quota change re-renders the card) and the
     * open month. The award rows themselves move only with the surface
     * revision, which the close bumps.
     *
     * The Team Harvest tab (20F) lists the member's qualified teammates,
     * so the shared team revision is part of its scope: another member's
     * qualification moves a counter and a roster without writing an award.
     * The configuration revision is in it too, because a ladder change
     * re-renders every amount on the matrix (§12.6 Rule 5). An award
     * itself bumps the surface, which is what P20F-T17 watches.
     */
    private function earningsScope(int $userId): string
    {
        $scope = Live::scope($_GET, $userId) . '|hour:' . date('Y-m-d H');

        if (self::tab() === self::TAB_REPEAT) {
            $scope .= '|team:' . Live::revisionOrZero(Live::GENEALOGY_TEAM);
        }

        if (self::tab() === self::TAB_ROYALTY) {
            $scope .= '|month:' . RoyaltyPeriod::periodOf()
                . '|team:' . Live::revisionOrZero(Live::GENEALOGY_TEAM)
                . '|config:' . Live::revisionOrZero(Live::CONFIG);
        }

        if (self::tab() === self::TAB_TEAM_HARVEST) {
            $scope .= '|team:' . Live::revisionOrZero(Live::GENEALOGY_TEAM)
                . '|config:' . Live::revisionOrZero(Live::CONFIG);
        }

        return $scope;
    }

    /**
     * JSON feed for the open earnings statement.
     *
     * The 304 check runs before the context, so an unchanged page costs
     * one revision lookup and no list/aggregate queries. No fragment
     * renders a POST form, so the session lock is released immediately.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = Live::earningsSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->earningsScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            if (Member::find($userId) === null) {
                Live::jsonError('Member not found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->context($userId, self::tab())));
        } catch (Throwable $e) {
            error_log('[ecowealth] member earnings live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh your earnings. Please try again.', 500);
        }
    }

    /**
     * Render the earnings payload for the tab in `$data['tab']`.
     *
     * Public so tests can assert the fragments without going through
     * Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_TEAM_HARVEST) {
            return $this->teamHarvestFeedPayload($revision, $etag, $data);
        }

        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_GENERAL_HARVEST) {
            return $this->generalHarvestFeedPayload($revision, $etag, $data);
        }

        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_REPEAT) {
            return $this->repeatFeedPayload($revision, $etag, $data);
        }

        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_ROYALTY) {
            return $this->royaltyFeedPayload($revision, $etag, $data);
        }

        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => Live::earningsSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'earnings-header' => View::capture('member/earnings/_header', [
                    'pager' => $pager,
                ]),
                'earnings-stats' => View::capture('member/earnings/_stats', [
                    'totals' => $data['totals'],
                ]),
                'earnings-levels' => View::capture('member/earnings/_levels', [
                    'levels' => $data['levels'],
                    'totals' => $data['totals'],
                ]),
                'earnings-skipped' => View::capture('member/earnings/_skipped', [
                    'totals' => $data['totals'],
                ]),
                'earnings-rows' => View::capture('member/earnings/_rows', [
                    'commissions' => $data['commissions'],
                    'filters'     => $data['filters'],
                    'wallet_url'  => $data['wallet_url'],
                ]),
                'earnings-pagination' => View::capture('member/earnings/_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalEarningsUrl($pager),
            ],
        ];
    }

    /**
     * The repeat tab's fragments: heading, counters, the two-clock panel,
     * the 1–10 breakdown, the rows and the pagination. The filter form
     * and the tab pills never travel in a fragment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function repeatFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => Live::earningsSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'earnings-repeat-header' => View::capture('member/earnings/_repeat_header', [
                    'pager' => $pager,
                ]),
                'earnings-repeat-stats' => View::capture('member/earnings/_repeat_stats', [
                    'totals' => $data['totals'],
                ]),
                'earnings-repeat-status' => View::capture('member/earnings/_repeat_status', [
                    'status' => $data['status'],
                ]),
                'earnings-repeat-levels' => View::capture('member/earnings/_repeat_levels', [
                    'levels' => $data['levels'],
                    'totals' => $data['totals'],
                ]),
                'earnings-repeat-rows' => View::capture('member/earnings/_repeat_rows', [
                    'awards'        => $data['awards'],
                    'filters'       => $data['filters'],
                    'statement_url' => $data['statement_url'],
                    'wallet_url'    => $data['wallet_url'],
                    'shop_url'      => $data['shop_url'],
                ]),
                'earnings-repeat-pagination' => View::capture('member/earnings/_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalEarningsUrl($pager),
            ],
        ];
    }

    /**
     * The Royalty tab's fragments (19E): the card heading, the rank
     * card, the five counters, the rows and the pagination. The filter
     * form and the tab pills never travel in a fragment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function royaltyFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => Live::earningsSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'earnings-royalty-header' => View::capture('member/earnings/_royalty_header', [
                    'pager' => $pager,
                ]),
                'earnings-royalty-rank' => View::capture('member/earnings/_royalty_rank', [
                    'royalty' => $data['royalty'],
                ]),
                'earnings-royalty-stats' => View::capture('member/earnings/_royalty_stats', [
                    'stats' => $data['stats'],
                ]),
                'earnings-royalty-rows' => View::capture('member/earnings/_royalty_rows', [
                    'awards'           => $data['awards'],
                    'filters'          => $data['filters'],
                    'payments_enabled' => (bool) ($data['royalty']['payments_enabled'] ?? true),
                ]),
                'earnings-royalty-pagination' => View::capture('member/earnings/_royalty_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalEarningsUrl($pager),
            ],
        ];
    }

    /**
     * The Team Harvest tab's fragments (20F): the five counters, the
     * position/progress card, the 3×10 matrix with its roster, the awards
     * card heading, the award rows and the pagination. The filter form,
     * the tab pills and the FAQ block never travel in a fragment, so a
     * background refresh never discards an unsubmitted filter.
     *
     * The roster pager travels with the matrix fragment: a member moving
     * the roster page is a server round trip, and the fragment carries
     * the new page's rows and its pager together.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function teamHarvestFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager  = $data['pager'];
        $status = $data['team_harvest'];
        $queued = (bool) ($status['queued'] ?? false);

        /* A member who has not qualified reads the gate and nothing else
         * (T6): the page renders one region, so the feed carries one
         * fragment. The regions that appear once they qualify would have
         * no element on this page to patch. */
        if (!$queued) {
            return [
                'ok'        => true,
                'surface'   => Live::earningsSurface((int) $data['user_id']),
                'revision'  => $revision,
                'etag'      => $etag,
                'fragments' => [
                    'earnings-team-progress' => View::capture('member/earnings/_team_progress', [
                        'team_harvest' => $status,
                    ]),
                ],
                'meta'      => [
                    'page'      => $pager->page,
                    'pages'     => $pager->pages,
                    'total'     => $pager->total,
                    'per_page'  => $pager->perPage,
                    'canonical' => $this->canonicalEarningsUrl($pager),
                ],
            ];
        }

        return [
            'ok'        => true,
            'surface'   => Live::earningsSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'earnings-team-stats' => View::capture('member/earnings/_team_stats', [
                    'team_harvest' => $status,
                ]),
                'earnings-team-progress' => View::capture('member/earnings/_team_progress', [
                    'team_harvest' => $status,
                ]),
                'earnings-team-matrix' => View::capture('member/earnings/_team_matrix', [
                    'team_harvest' => $status,
                    'roster'       => $data['roster'],
                    'roster_pager' => $data['roster_pager'],
                    'user_id'      => (int) $data['user_id'],
                ]),
                'earnings-team-header' => View::capture('member/earnings/_team_header', [
                    'team_harvest' => $status,
                    'pager'        => $pager,
                ]),
                'earnings-team-rows' => View::capture('member/earnings/_team_rows', [
                    'awards'     => $data['awards'],
                    'filters'    => $data['filters'],
                    'wallet_url' => $data['wallet_url'],
                ]),
                'earnings-team-pagination' => View::capture('member/earnings/_team_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalEarningsUrl($pager),
            ],
        ];
    }

    /**
     * The General Harvest tab's fragments (21F): the block card header,
     * the stats card, the ten-block grid, the next-block progress, the
     * award rows and the pagination. The filter form and the tab pills
     * never travel in a fragment, so a background refresh never discards
     * an unsubmitted filter.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function generalHarvestFeedPayload(int $revision, string $etag, array $data): array
    {
        $pager      = $data['pager'];
        $status     = $data['general_harvest'];
        $queued     = (bool) ($status['queued'] ?? false);

        /* A member who has not qualified reads the gate and nothing else
         * (P21F-T6): the page renders one region, so the feed carries one
         * fragment. The regions that appear once they qualify would have
         * no element on this page to patch. */
        if (!$queued) {
            return [
                'ok'        => true,
                'surface'   => Live::earningsSurface((int) $data['user_id']),
                'revision'  => $revision,
                'etag'      => $etag,
                'fragments' => [
                    'earnings-general-progress' => View::capture('member/earnings/_general_progress', [
                        'general_harvest' => $status,
                    ]),
                ],
                'meta'      => [
                    'page'      => $pager->page,
                    'pages'     => $pager->pages,
                    'total'     => $pager->total,
                    'per_page'  => $pager->perPage,
                    'canonical' => $this->canonicalEarningsUrl($pager),
                ],
            ];
        }

        return [
            'ok'        => true,
            'surface'   => Live::earningsSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'earnings-general-header' => View::capture('member/earnings/_general_header', [
                    'general_harvest' => $status,
                ]),
                'earnings-general-stats' => View::capture('member/earnings/_general_stats', [
                    'general_harvest' => $status,
                ]),
                'earnings-general-blocks' => View::capture('member/earnings/_general_blocks', [
                    'general_harvest' => $status,
                ]),
                'earnings-general-progress' => View::capture('member/earnings/_general_progress', [
                    'general_harvest' => $status,
                ]),
                'earnings-general-rows' => View::capture('member/earnings/_general_rows', [
                    'awards'     => $data['awards'],
                    'filters'    => $data['filters'],
                    'wallet_url' => $data['wallet_url'],
                ]),
                'earnings-general-pagination' => View::capture('member/earnings/_general_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalEarningsUrl($pager),
            ],
        ];
    }

    /** The earnings URL with an out-of-range page clamped to a real page. */
    private function canonicalEarningsUrl(Paginator $pager): string
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

        return url('earnings' . ($qs !== '' ? '?' . $qs : ''));
    }
}
