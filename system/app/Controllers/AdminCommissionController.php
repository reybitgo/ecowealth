<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminCommissionController — the company-wide commission ledger
 *  (plan §7 Phase 10, Appendix A extension)
 *
 *  Route: GET /admin/commissions   guard `admin`
 *
 *  Every award the plan has ever produced, credited or skipped, with
 *  filters by recipient/source/package (q), type, level, status and
 *  date. The header carries the numbers finance needs: total
 *  credited, this month, awards, and how many were skipped.
 *
 *  The ledger is read-only by design: an award is never edited. A
 *  correction would be a new `adjustment` wallet movement plus a
 *  `voided` row (Phase 15), never an UPDATE on money history.
 *
 *  The page is one live surface (`admin.commissions`): the card heading,
 *  counters, rows and pagination are regions; the filter form stays
 *  outside them.
 * ============================================================== */

final class AdminCommissionController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        /* The same URL doubles as the JSON feed: X-Live: 1 returns the
         * counters, rows and pagination instead of the layout. */
        if (Live::isFeedRequest()) {
            $this->listFeed();
            return;
        }

        View::page('admin', 'admin/commissions/index', $this->listContext() + [
            'page_title'  => 'Commissions',
            'current_nav' => 'admin.commissions',
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
            'live'        => $this->listLiveMeta(),
        ]);
    }

    /**
     * Everything the ledger renders, built once for the page and its feed.
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
            'stats'       => Commission::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'commissions' => Commission::adminList($filters, $pager),
            'ledger_url'  => url('admin/commissions'),
            'export_url'  => url('admin/commissions/export' . self::queryString($filters)),
            'wallet_url'  => url('admin/wallets'),
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
     * @param array<string, string> $params
     */
    public function export(array $params = []): void
    {
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
     * ETag scope for the ledger.
     *
     * `this_month` counts from the first of the month, so the day is part
     * of the scope: a tab left open across midnight revalidates on its next
     * poll instead of showing yesterday's month figure.
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
     * The 304 check runs before listContext(), so an unchanged ledger costs
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

            Live::json($etag, $this->feedPayload($revision, $etag, $this->listContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] commission ledger feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the commission ledger. Please try again.', 500);
        }
    }

    /**
     * Render the ledger payload. Public so tests can assert the fragments
     * without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
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
