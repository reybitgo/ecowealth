<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminCommissionController — the company-wide commission ledger
 *  (plan §7 Phase 10, Appendix A extension; Phase 17D adds the
 *  repeat-purchase tab of plan §8)
 *
 *  Route: GET /admin/commissions   guard `admin`
 *
 *  Two ledgers, one page, one URL:
 *
 *      /admin/commissions                package referral awards
 *                                        (`commissions`)
 *      /admin/commissions?ledger=repeat  repeat-purchase awards
 *                                        (`unilevel_awards`)
 *
 *  The package tab is the Phase 10–11 ledger, unchanged: filters by
 *  recipient/source/package (q), type, level, status and date, and the
 *  four counters finance needs. The repeat tab is read-only over the
 *  rows 17C writes: one ladder, levels 1–10, no direct/indirect column
 *  (D-17), with the periods card showing the closed months. Skipped
 *  rows are audit notes and never enter a credited total.
 *
 *  The ledger is read-only by design: an award is never edited. A
 *  correction would be a new `adjustment` wallet movement plus a
 *  `voided` row (Phase 15), never an UPDATE on money history.
 *
 *  The page is one live surface (`admin.commissions`): the card heading,
 *  counters, rows and pagination are regions; the filter form stays
 *  outside them. The tab is part of the URL, so an open repeat tab
 *  refreshes on the same surface an award bumps (P17D-T14).
 * ============================================================== */

final class AdminCommissionController
{
    /** Package referral ledger. */
    public const TAB_PACKAGE = 'package';

    /** Repeat-purchase ladder ledger. */
    public const TAB_REPEAT = 'repeat';

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

    /** The tab named by `?ledger=`; anything but `repeat` is the package tab. */
    public static function tab(): string
    {
        return strtolower(trim(Request::string('ledger'))) === self::TAB_REPEAT
            ? self::TAB_REPEAT
            : self::TAB_PACKAGE;
    }

    /**
     * The context for the tab named by the request.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return self::tab() === self::TAB_REPEAT ? $this->repeatContext() : $this->listContext();
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
     * @param array<string, string> $params
     */
    public function export(array $params = []): void
    {
        if (self::tab() === self::TAB_REPEAT) {
            $this->exportRepeat();
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
     * ETag scope for the ledger.
     *
     * `this_month` counts from the first of the month, so the day is part
     * of the scope: a tab left open across midnight revalidates on its next
     * poll instead of showing yesterday's month figure. `Live::scope()`
     * carries the query string, so the two tabs never share an ETag.
     */
    private function listScope(int $userId): string
    {
        return Live::scope($_GET, $userId) . '|day:' . date('Y-m-d');
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
