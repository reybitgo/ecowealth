<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminCodeController — issue, list and export registration codes
 *  (plan §5.10, §7 Phase 9; reference AdminController::codes /
 *  generateCodes / exportCodes and views/admin/codes.php)
 *
 *  Routes (Appendix A):
 *      GET  /admin/codes           stats + filters + issue form + list
 *      POST /admin/codes/generate  transactional batch issue
 *      GET  /admin/codes/export    CSV download of the filtered set
 *
 *  Decision D21: admins AND superadmins may issue codes, so the
 *  router's `admin` guard is exactly the right gate. Every batch is
 *  audited (`code.generate`) by CodeService.
 *
 *  The list is one live surface (`admin.codes`): the counters, rows and
 *  pagination are regions; the issue and filter forms stay outside them.
 * ============================================================== */

final class AdminCodeController
{
    private CodeService $codes;

    public function __construct()
    {
        $this->codes = new CodeService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        /* The same URL doubles as the JSON feed: X-Live: 1 returns the
         * stats, rows and pagination instead of the layout. */
        if (Live::isFeedRequest()) {
            $this->listFeed();
            return;
        }

        View::page('admin', 'admin/codes/index', $this->listContext() + [
            'page_title'  => 'Registration codes',
            'current_nav' => 'admin.codes',
            'page_css'    => ['assets/css/membership.css'],
            'page_js'     => ['assets/js/app-codes.js'],
            'live'        => $this->listLiveMeta(),
        ]);
    }

    /**
     * Everything the code list renders, built once for the page and feed.
     *
     * @return array<string, mixed>
     */
    public function listContext(): array
    {
        $filters = RegCode::normaliseFilters([
            'q'       => Request::string('q'),
            'status'  => Request::string('status'),
            'package' => Request::string('package'),
        ]);

        $pager = Paginator::fromTableRequest(RegCode::countAdmin($filters));

        return [
            'stats'    => RegCode::stats(),
            'filters'  => $filters,
            'pager'    => $pager,
            'codes'    => RegCode::adminList($filters, $pager),
            'packages' => Package::all(),
        ];
    }

    /** @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int} */
    private function listLiveMeta(): array
    {
        return Live::meta('admin.codes', Live::scope($_GET, (int) (auth_user()['id'] ?? 0)));
    }

    /**
     * JSON feed for the open code list.
     *
     * The 304 check runs before listContext(), so an unchanged list costs
     * one revision lookup and no queries.
     */
    private function listFeed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        session_write_close();

        $surface = 'admin.codes';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->listContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] registration code feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the registration codes. Please try again.', 500);
        }
    }

    /**
     * Render the code-list payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $pager = $data['pager'];

        return [
            'ok'        => true,
            'surface'   => 'admin.codes',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'code-stats' => View::capture('admin/codes/_stats', [
                    'stats' => $data['stats'],
                ]),
                'code-rows' => View::capture('admin/codes/_rows', [
                    'codes'   => $data['codes'],
                    'filters' => $data['filters'],
                ]),
                'code-pagination' => View::capture('admin/codes/_pagination', [
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

    /** The code-list URL with an out-of-range page clamped to a real page. */
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

        return url('admin/codes' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** POST /admin/codes/generate — issue a batch. */
    public function generate(array $params = []): void
    {
        $input = [
            'package_id' => Request::string('package_id'),
            'quantity'   => Request::string('quantity'),
            'price'      => Request::string('price'),
            'expires_at' => Request::string('expires_at'),
            'code_type'  => Request::string('code_type', 'registration'),
        ];

        $admin = auth_user();

        if ($admin === null) {
            redirect('login');
        }

        try {
            $result = $this->codes->generate($input, $admin);
        } catch (CodeException $e) {
            errors_set($e->fieldErrors);
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect('admin/codes#codes-issue');
        } catch (Throwable $e) {
            error_log('[ecowealth] code generation failed: ' . $e->getMessage());
            old_set($input);
            flash_set('danger', 'The codes could not be generated. Please try again.');
            redirect('admin/codes#codes-issue');
        }

        old_clear();

        /* Other open code lists revalidate on the next poll via the
         * revision bump; this broadcast refreshes them immediately after
         * the redirect lands. */
        Live::publish('admin.codes');

        $sample = $result['codes'] === []
            ? ''
            : sprintf(' First code: %s.', (string) $result['codes'][0]);

        flash_set('success', sprintf(
            '%d %s code%s issued at %s each.%s',
            (int) $result['batch'],
            (string) $result['package_name'],
            (int) $result['batch'] === 1 ? '' : 's',
            money((float) $result['price']),
            $sample
        ));

        redirect('admin/codes');
    }

    /** GET /admin/codes/export — CSV of the filtered codes. */
    public function export(array $params = []): void
    {
        $filters = RegCode::normaliseFilters([
            'q'       => Request::string('q'),
            'status'  => Request::string('status'),
            'package' => Request::string('package'),
        ]);

        $rows = RegCode::exportRows($filters);
        $admin = auth_user();

        AuditLog::record([
            'action'       => 'code.export',
            'actor_id'     => $admin === null ? null : (int) $admin['id'],
            'actor_role'   => $admin === null ? null : (string) $admin['role'],
            'subject_type' => 'reg_code',
            'subject_id'   => null,
            'meta'         => ['filters' => $filters, 'rows' => count($rows)],
        ]);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reg_codes_' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');

        if ($out === false) {
            exit;
        }

        fputcsv($out, [
            'Code', 'Package', 'Price', 'Status', 'Type', 'Created', 'Expires', 'Used by', 'Used at', 'Issued by',
        ]);

        foreach ($rows as $row) {
            fputcsv($out, [
                (string) $row['code'],
                (string) $row['package_name'],
                number_format((float) $row['price'], 2, '.', ''),
                (string) $row['status'],
                (string) $row['code_type'],
                (string) $row['created_at'],
                $row['expires_at'] === null ? '' : (string) $row['expires_at'],
                $row['used_by_username'] === null ? '' : (string) $row['used_by_username'],
                $row['used_at'] === null ? '' : (string) $row['used_at'],
                $row['created_by_username'] === null ? '' : (string) $row['created_by_username'],
            ]);
        }

        fclose($out);
        exit;
    }
}
