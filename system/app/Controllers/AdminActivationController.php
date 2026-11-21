<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminActivationController — the activation review queue
 *  (plan §4.4, §5.10, §7 Phase 9)
 *
 *  Routes:
 *      GET  /admin/activations                 queue + counters
 *      POST /admin/activations/{id}/approve    member becomes active
 *      POST /admin/activations/{id}/reject     reason required
 *      GET  /admin/activations/{id}/proof      guarded proof stream
 *
 *  The approval is the money/state decision, so it lives entirely in
 *  ActivationService::approve() — the controller only validates the
 *  input and picks the response. The proof file is never a public
 *  URL: it is streamed here, behind the admin guard, exactly like
 *  the Phase 7 payment proof (plan §3.8).
 *
 *  The queue is one live surface (`admin.activations`): the heading,
 *  counters, tabs, rows and pagination are regions.
 * ============================================================== */

final class AdminActivationController
{
    private ActivationService $activations;

    public function __construct()
    {
        $this->activations = new ActivationService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        /* The same URL doubles as the JSON feed: X-Live: 1 returns the
         * queue regions instead of the layout. */
        if (Live::isFeedRequest()) {
            $this->listFeed();
            return;
        }

        View::page('admin', 'admin/activations/index', $this->listContext() + [
            'page_title'  => 'Activations',
            'current_nav' => 'admin.activations',
            'page_css'    => [
                'assets/css/membership.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
            ],
            'live'        => $this->listLiveMeta(),
        ]);
    }

    /**
     * Everything the activation queue renders, built once for the page and
     * its feed.
     *
     * @return array<string, mixed>
     */
    public function listContext(): array
    {
        $filters = Activation::normaliseFilters([
            'status' => Request::string('status'),
            'method' => Request::string('method'),
            'q'      => Request::string('q'),
        ]);

        $pager = Paginator::fromTableRequest(Activation::countAdmin($filters));

        $tabCounts = [
            'review'   => Activation::countAdmin(['status' => 'pending_review', 'method' => '', 'q' => '']),
            'approved' => Activation::countAdmin(['status' => 'approved', 'method' => '', 'q' => '']),
            'rejected' => Activation::countAdmin(['status' => 'rejected', 'method' => '', 'q' => '']),
        ];

        return [
            'stats'       => Activation::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'activations' => Activation::adminList($filters, $pager),
            'tab_counts'  => $tabCounts,
        ];
    }

    /** @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int} */
    private function listLiveMeta(): array
    {
        return Live::meta('admin.activations', Live::scope($_GET, (int) (auth_user()['id'] ?? 0)));
    }

    /**
     * JSON feed for the open activation queue.
     *
     * The 304 check runs before listContext(), so an unchanged queue costs
     * one revision lookup and no list/aggregate queries. The rows carry the
     * approve/reject forms, so the CSRF token is primed before the session
     * lock is released.
     */
    private function listFeed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        csrf_token();
        session_write_close();

        $surface = 'admin.activations';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->listContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] activation queue feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the activation queue. Please try again.', 500);
        }
    }

    /**
     * Render the queue payload. Public so the tests can assert the fragments
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
            'surface'   => 'admin.activations',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'activation-header' => View::capture('admin/activations/_header', [
                    'stats' => $data['stats'],
                ]),
                'activation-stats' => View::capture('admin/activations/_stats', [
                    'stats' => $data['stats'],
                ]),
                'activation-tabs' => View::capture('admin/activations/_tabs', [
                    'filters'    => $data['filters'],
                    'stats'      => $data['stats'],
                    'tab_counts' => $data['tab_counts'],
                ]),
                'activation-rows' => View::capture('admin/activations/_rows', [
                    'activations' => $data['activations'],
                    'filters'     => $data['filters'],
                ]),
                'activation-pagination' => View::capture('admin/activations/_pagination', [
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

    /** The queue URL with an out-of-range page clamped to a real page. */
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

        return url('admin/activations' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** POST /admin/activations/{id}/approve */
    public function approve(array $params = []): void
    {
        $id    = (int) ($params['id'] ?? 0);
        $admin = auth_user();

        if ($admin === null) {
            redirect('login');
        }

        try {
            $result = $this->activations->approve($id, $admin);
        } catch (ActivationException $e) {
            flash_set('danger', $e->getMessage());
            redirect('admin/activations');
        } catch (Throwable $e) {
            error_log('[ecowealth] activation approval failed: ' . $e->getMessage());
            flash_set('danger', 'The activation could not be approved. Please try again.');
            redirect('admin/activations');
        }

        $publish = [
            'admin.dashboard',
            'admin.wallets',
            'admin.members',
            'admin.activations',
            'admin.commissions',
            Live::GENEALOGY_TEAM,
        ];

        if ((int) ($result['user_id'] ?? 0) > 0) {
            $publish[] = Live::walletSurface((int) $result['user_id']);
            $publish[] = Live::memberSurface((int) $result['user_id']);
            $publish[] = Live::membershipSurface((int) $result['user_id']);
        }

        Live::publish($publish);

        flash_set('success', sprintf(
            'Activation #%d approved — @%s is now active with %s (%s).',
            (int) $result['activation_id'],
            (string) ($result['username'] ?? ''),
            (string) ($result['package_name'] ?? 'the package'),
            money((float) $result['amount'])
        ));

        redirect('admin/activations');
    }

    /** POST /admin/activations/{id}/reject */
    public function reject(array $params = []): void
    {
        $id     = (int) ($params['id'] ?? 0);
        $reason = Request::string('reason');
        $admin  = auth_user();

        if ($admin === null) {
            redirect('login');
        }

        try {
            $result = $this->activations->reject($id, $admin, $reason);
        } catch (ActivationException $e) {
            errors_set($e->fieldErrors);
            old_set(['reason' => $reason]);
            flash_set('danger', $e->getMessage());
            redirect('admin/activations');
        } catch (Throwable $e) {
            error_log('[ecowealth] activation rejection failed: ' . $e->getMessage());
            flash_set('danger', 'The activation could not be rejected. Please try again.');
            redirect('admin/activations');
        }

        old_clear();

        $publish = ['admin.activations'];

        if ((int) ($result['user_id'] ?? 0) > 0) {
            $publish[] = Live::memberSurface((int) $result['user_id']);
            $publish[] = Live::membershipSurface((int) $result['user_id']);
        }

        Live::publish($publish);

        flash_set('success', sprintf(
            'Activation #%d rejected. The member is back to pending and can try again.',
            (int) $result['activation_id']
        ));

        redirect('admin/activations');
    }

    /** GET /admin/activations/{id}/proof — stream the uploaded proof. */
    public function proof(array $params = []): void
    {
        $activation = Activation::find((int) ($params['id'] ?? 0));

        if ($activation === null || (string) ($activation['proof_path'] ?? '') === '') {
            View::error(404);
        }

        $relative = str_replace('\\', '/', ltrim((string) $activation['proof_path'], '/'));

        if (!str_starts_with($relative, 'uploads/') || str_contains($relative, '..')) {
            View::error(404);
        }

        $absolute = Upload::root() . substr($relative, strlen('uploads/'));

        if (!is_file($absolute)) {
            View::error(404);
        }

        $mime = 'application/octet-stream';

        if (class_exists('finfo')) {
            $sniffed = (new finfo(FILEINFO_MIME_TYPE))->file($absolute);

            if (is_string($sniffed) && $sniffed !== '') {
                $mime = $sniffed;
            }
        }

        $size = filesize($absolute);

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) ($size === false ? 0 : $size));
            header('Content-Disposition: inline; filename="activation-proof-' . (int) $activation['id'] . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
        }

        readfile($absolute);
        exit;
    }
}
