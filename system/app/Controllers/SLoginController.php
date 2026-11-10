<?php
declare(strict_types=1);

/* ==============================================================
 *  SLoginController — Super-Login: the superadmin console, the
 *  impersonation handoff and the audit trail (plan §3.4, §7 Phase 14;
 *  reference AuthController::showSlogin/doSlogin + views/auth/slogin.php)
 *
 *  Routes (Appendix A):
 *      GET  /slogin                console: member picker + active/recent
 *      POST /slogin                open a member session
 *      POST /slogin/exit           end the current member session
 *      GET  /admin/impersonations  the audit list
 *
 *  The router owns the guards (`superadmin`, `member`, `admin`) and the
 *  central CSRF check. This controller only moves between the
 *  superadmin's cookie session and the cookie-less URL-token session:
 *  it never copies the superadmin's session into the member one.
 * ============================================================== */

final class SLoginController
{
    private ImpersonationService $service;

    public function __construct()
    {
        $this->service = new ImpersonationService();
    }

    /* ----------------------------------------------------------
     *  Console
     * ---------------------------------------------------------- */

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        if (!is_superadmin($user)) {
            View::error(403);
        }

        /* A session whose TTL passed without another request would still
         * read `active`; sweep it so the console and the audit agree. */
        Impersonation::expireOverdue();

        $notice = Request::string('expired') !== ''
            ? 'expired'
            : (Request::string('exited') !== '' ? 'exited' : '');

        View::page('admin', 'auth/slogin', [
            'page_title'  => 'Super-login',
            'current_nav' => 'admin.slogin',
            'members'     => Member::activeOptions(200),
            'active'      => Impersonation::active(20),
            'recent'      => Impersonation::recent(8),
            'counts'      => Impersonation::statusCounts(),
            'notice'      => $notice,
            'audit_url'   => url('admin/impersonations'),
            'page_js'     => ['assets/js/app-slogin.js'],
        ]);
    }

    /* ----------------------------------------------------------
     *  Start / exit
     * ---------------------------------------------------------- */

    /**
     * POST /slogin — open a member session and hand the browser the
     * URL-token redirect.
     *
     * @param array<string, string> $params
     */
    public function start(array $params = []): void
    {
        $superadmin = auth_user();

        if ($superadmin === null) {
            redirect('login');
        }

        $username = Request::string('username');
        $input    = ['username' => $username];

        $errors = Validator::check($input, [
            'username' => 'required|string|max:50|regex:' . ImpersonationService::USERNAME_PATTERN,
        ], [
            'username' => 'Member username',
        ]);

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);
            flash_set('danger', 'Enter the username of the member to open.');
            redirect('slogin');
        }

        try {
            $issued = $this->service->start($username, $superadmin);
        } catch (ImpersonationException $e) {
            errors_set(['username' => $e->getMessage()]);
            old_set($input);
            flash_set('danger', $e->getMessage());
            redirect('slogin');
        } catch (Throwable $e) {
            error_log('[ecowealth] S-Login start failed: ' . $e->getMessage());
            flash_set('danger', 'The member session could not be opened. Please try again.');
            redirect('slogin');
        }

        old_clear();

        /* Close the superadmin's cookie session (its data and cookie stay
         * intact) and open the cookie-less session whose id is the nonce.
         * The browser receives no impersonation cookie — the URL token is
         * the only key, so the superadmin's own session is never touched. */
        session_write_close();

        try {
            auth_start_imp_session((string) $issued['nonce']);
            ImpersonationService::bind($issued);

            flash_set('success', sprintf('You are now viewing the member portal as @%s.', (string) $issued['target']['username']));
            csrf_token();

            session_write_close();
        } catch (Throwable $e) {
            error_log('[ecowealth] S-Login transport failed: ' . $e->getMessage());

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
                session_destroy();
            }

            try {
                Impersonation::mark((int) ($issued['log_id'] ?? 0), 'expired');
            } catch (Throwable $markError) {
                error_log('[ecowealth] S-Login cleanup failed: ' . $markError->getMessage());
            }

            session_name(SESSION_NAME);
            session_start();
            flash_set('danger', 'The member session could not be opened. Please try again.');
            redirect('slogin');
        }

        /* url() appends the token from the bound session, so this lands on
         * /dashboard?imp=<nonce> without the nonce ever being echoed here. */
        redirect('dashboard');
    }

    /** @param array<string, string> $params */
    public function stop(array $params = []): void
    {
        if (!auth_is_imp()) {
            flash_set('info', 'There is no member session to end.');

            redirect(is_superadmin() ? 'slogin' : 'dashboard');
        }

        try {
            $this->service->exit();
        } catch (ImpersonationException $e) {
            error_log('[ecowealth] S-Login exit refused: ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('[ecowealth] S-Login exit failed: ' . $e->getMessage());
        }

        auth_end_imp_session('logged_out');

        /* The superadmin's cookie session was never read on this request;
         * this redirect opens it again. */
        redirect('slogin?exited=1');
    }

    /* ----------------------------------------------------------
     *  Audit list
     * ---------------------------------------------------------- */

    /** @param array<string, string> $params */
    public function audit(array $params = []): void
    {
        $swept = Impersonation::expireOverdue();

        $filters = Impersonation::normaliseFilters([
            'status' => Request::string('status'),
            'q'      => Request::string('q'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
        ]);

        $pager = Paginator::fromTableRequest(Impersonation::queueCount($filters));

        View::page('admin', 'admin/impersonations/index', [
            'page_title'  => 'Super-login audit',
            'current_nav' => 'admin.slogin',
            'filters'     => $filters,
            'sessions'    => Impersonation::queue($filters, $pager),
            'pager'       => $pager,
            'counts'      => Impersonation::statusCounts(),
            'totals'      => Impersonation::totals(),
            'swept'       => $swept,
            'console_url' => url('slogin'),
            'audit_url'   => url('admin/impersonations'),
        ]);
    }
}
