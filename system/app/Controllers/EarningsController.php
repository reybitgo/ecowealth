<?php
declare(strict_types=1);

/* ==============================================================
 *  EarningsController — the member's commission statement
 *  (plan §7 Phase 10; reference MemberController::earnings and
 *  views/member/earnings.php; Phase 17E adds the repeat-purchase tab
 *  of plan §9)
 *
 *  Route (Appendix A): GET /earnings   guard `member`
 *
 *  Two ledgers, one page, one URL:
 *
 *      /earnings                package referral awards (`commissions`)
 *      /earnings?ledger=repeat  repeat-purchase awards
 *                               (`unilevel_awards`)
 *
 *  The package tab is the Phase 10–11 statement, unchanged: three
 *  summary groups, the level breakdown, the skipped-award notice and
 *  one row per activation. The repeat tab is the member's upline
 *  statement: the six counters are replaced by four that never include
 *  an "as buyer" figure (Rule 9), and a two-clock panel explains why a
 *  bonus did or did not reach them.
 *
 *  Members only ever see their own rows (`WHERE user_id = ?`), the same
 *  ownership rule as orders and the wallet statement. The tab is a
 *  query parameter only (D-14); a `ledger` value other than `repeat`
 *  selects the package tab, so a hand-edited URL can never break the
 *  page.
 *
 *  The page is one per-member live surface (`member.earnings:{id}`).
 *  The two tabs share it: a repeat award bumps the same revision the
 *  package tab already listens to, so an open repeat tab refreshes
 *  without a second surface (Rule 10). The repeat tab also mixes the
 *  shared genealogy revision into its ETag: its level cards list the
 *  team, so a registration, activation or status change refreshes them
 *  too, exactly like the genealogy page.
 * ============================================================== */

final class EarningsController
{
    /** Package referral statement. */
    public const TAB_PACKAGE = 'package';

    /** Repeat-purchase (unilevel ladder) statement. */
    public const TAB_REPEAT = 'repeat';

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

    /** The tab named by `?ledger=`; anything but `repeat` is the package tab. */
    public static function tab(): string
    {
        return strtolower(trim(Request::string('ledger'))) === self::TAB_REPEAT
            ? self::TAB_REPEAT
            : self::TAB_PACKAGE;
    }

    /**
     * The context for the tab named by the request (or by `$tab`).
     *
     * @return array<string, mixed>
     */
    public function context(int $userId, ?string $tab = null): array
    {
        $tab ??= self::tab();

        return $tab === self::TAB_REPEAT ? $this->repeatContext($userId) : $this->earningsContext($userId);
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
     * The 1–10 repeat-purchase breakdown, enriched with the member's
     * team at each level (17E follow-up).
     *
     * The ledger total for a level is unchanged: credited awards at that
     * depth, from `UnilevelAward::levelBreakdown()`. What is new is the
     * roster beside it — level 1 is the members the session member
     * sponsored, level 2 their recruits, and so on, exactly the
     * genealogy "Team by level" shape. Each member row carries the
     * money that member's purchases credited at that level, so the two
     * halves of the card answer "who is at this level?" and "what did
     * they earn me?" from one screen.
     *
     * The team is read once, then paginated per level with namespaced
     * keys (`level1_page`, `level1_per_page`, …) so one crowded level
     * never moves the award table below. A member with no credited
     * purchase stays in the list at ₱0.00: the roster is the team, not
     * only the payers.
     *
     * @return array<int, array{
     *   amount:float, count:int, team_count:int, pager:Paginator,
     *   members:list<array{
     *     id:int, username:string, full_name:string, package_name:?string,
     *     member_status:string, amount:float, count:int
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
                $stat = $earned[$level][$id] ?? ['amount' => 0.0, 'count' => 0];

                $members[] = [
                    'id'            => $id,
                    'username'      => (string) $row['username'],
                    'full_name'     => (string) ($row['full_name'] ?? ''),
                    'package_name'  => $row['package_name'] === null ? null : (string) $row['package_name'],
                    'member_status' => (string) $row['member_status'],
                    'amount'        => round((float) $stat['amount'], 2),
                    'count'         => (int) $stat['count'],
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
     * and finance read one definition. Both are scoped to the caller's
     * own rows.
     *
     * @param array<string, string> $params
     */
    public function export(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
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
     * ETag scope for the statement.
     *
     * The "last award" label is relative and `this_month` counts from
     * CURDATE(), so the hour is part of the scope: a tab left open
     * revalidates without a write instead of showing a stale age.
     * `Live::scope()` carries the query string, so the two tabs never
     * share an ETag.
     *
     * The repeat tab also lists the member's team by level, so it mixes
     * in the shared `GENEALOGY_TEAM` revision: a registration, an
     * activation or a member status change moves a roster even though no
     * award row was written (the same revision the genealogy and
     * dashboard pages use).
     */
    private function earningsScope(int $userId): string
    {
        $scope = Live::scope($_GET, $userId) . '|hour:' . date('Y-m-d H');

        if (self::tab() === self::TAB_REPEAT) {
            $scope .= '|team:' . Live::revisionOrZero(Live::GENEALOGY_TEAM);
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
        if (($data['tab'] ?? self::TAB_PACKAGE) === self::TAB_REPEAT) {
            return $this->repeatFeedPayload($revision, $etag, $data);
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
