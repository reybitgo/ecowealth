<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminMemberController — the member directory and one member
 *  (plan §2.5, §7 Phase 9; reference AdminController::users /
 *  userView / toggleUser / deactivateUser and views/admin/users.php)
 *
 *  Routes (Appendix A):
 *      GET  /admin/members               directory (filters + stats)
 *      GET  /admin/members/{id}          sponsor, package, wallet, history
 *      POST /admin/members/{id}/status   suspend / restore (audited)
 *      POST /admin/members/{id}/toggle   reference admin_toggle_user
 *
 *  Suspending clears `is_active` as well as `member_status`, so the
 *  member is signed out on the next request and cannot log in again
 *  until restored (Member::setStatus owns both changes).
 * ============================================================== */

final class AdminMemberController
{
    private WalletService $wallet;

    public function __construct()
    {
        $this->wallet = new WalletService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $filters = Member::normaliseFilters([
            'q'       => Request::string('q'),
            'status'  => Request::string('status'),
            'package' => Request::string('package'),
        ]);

        $pager = Paginator::fromTableRequest(Member::countAdmin($filters));
        $rows  = Member::adminList($filters, $pager);

        View::page('admin', 'admin/members/index', [
            'page_title'  => 'Members',
            'current_nav' => 'admin.members',
            'page_css'    => [
                'assets/css/membership.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
            ],
            'stats'       => Member::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'members'     => $rows,
            'packages'    => Member::packageBreakdown(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $member = Member::find((int) ($params['id'] ?? 0));

        if ($member === null) {
            View::error(404);
        }

        $userId = (int) $member['id'];

        $activationsPager = Paginator::fromTableRequest(Activation::countForUser($userId));

        View::page('admin', 'admin/members/show', [
            'page_title'   => 'Member — ' . (string) $member['username'],
            'current_nav'  => 'admin.members',
            'page_css'     => [
                'assets/css/membership.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'      => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
            ],
            'member'       => $member,
            'balance'      => $this->wallet->balance($userId),
            'withdrawable' => $this->wallet->withdrawable($userId),
            'activations'  => Activation::pageForUser($userId, $activationsPager),
            'activations_pager' => $activationsPager,
            'orders'       => Order::statsForUser($userId),
            'commissions'  => (new CommissionService())->totalsFor($userId),
            'team'         => (new SponsorService())->teamStats($userId),
            'upline'       => (new SponsorService())->chain($userId, SponsorService::MAX_LEVELS),
            'audits'       => AuditLog::forSubject('user', $userId, 8),
            'statement_url' => url('admin/wallets/' . $userId),
        ]);
    }

    /** POST /admin/members/{id}/status — suspend or restore. */
    public function status(array $params = []): void
    {
        $id     = (int) ($params['id'] ?? 0);
        $action = strtolower(Request::string('action'));
        $reason = Request::string('reason');
        $admin  = auth_user();

        if ($admin === null) {
            redirect('login');
        }

        $next = match ($action) {
            'suspend' => 'suspended',
            'restore' => 'active',
            'pending' => 'pending',
            'inactive' => 'inactive',
            default   => '',
        };

        if ($next === '') {
            flash_set('danger', 'Choose suspend or restore.');
            redirect('admin/members/' . $id);
        }

        try {
            $member = Member::setStatus($id, $next, $admin, $reason);
        } catch (InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
            redirect('admin/members/' . $id);
        } catch (Throwable $e) {
            error_log('[ecowealth] member status change failed: ' . $e->getMessage());
            flash_set('danger', 'The member status could not be changed. Please try again.');
            redirect('admin/members/' . $id);
        }

        old_clear();

        Live::publish('admin.dashboard');

        flash_set('success', sprintf(
            '@%s is now %s%s.',
            (string) $member['username'],
            (string) $member['member_status'],
            (string) $member['member_status'] === 'suspended' ? ' — the account cannot sign in' : ''
        ));

        redirect('admin/members/' . $id);
    }

    /** POST /admin/members/{id}/toggle — reference admin_toggle_user. */
    public function toggle(array $params = []): void
    {
        $id    = (int) ($params['id'] ?? 0);
        $admin = auth_user();

        if ($admin === null) {
            redirect('login');
        }

        try {
            $member = Member::toggleStatus($id, $admin);
        } catch (InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
            redirect('admin/members');
        } catch (Throwable $e) {
            error_log('[ecowealth] member toggle failed: ' . $e->getMessage());
            flash_set('danger', 'The member status could not be changed. Please try again.');
            redirect('admin/members');
        }

        Live::publish('admin.dashboard');

        flash_set('success', sprintf(
            '@%s is now %s.',
            (string) $member['username'],
            (string) $member['member_status']
        ));

        redirect('admin/members/' . $id);
    }
}
