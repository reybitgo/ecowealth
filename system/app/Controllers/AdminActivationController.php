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
        $filters = Activation::normaliseFilters([
            'status' => Request::string('status'),
            'method' => Request::string('method'),
            'q'      => Request::string('q'),
        ]);

        $pager = Paginator::fromRequest(Activation::countAdmin($filters), 20);
        $rows  = Activation::adminList($filters, $pager);

        $tabCounts = [
            'review'   => Activation::countAdmin(['status' => 'pending_review', 'method' => '', 'q' => '']),
            'approved' => Activation::countAdmin(['status' => 'approved', 'method' => '', 'q' => '']),
            'rejected' => Activation::countAdmin(['status' => 'rejected', 'method' => '', 'q' => '']),
        ];

        View::page('admin', 'admin/activations/index', [
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
            'stats'       => Activation::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'activations' => $rows,
            'tab_counts'  => $tabCounts,
        ]);
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
