<?php
declare(strict_types=1);

/* ==============================================================
 *  DashboardController — the routed member dashboard
 *  (plan §7 Phase 9: "replace public/dashboard.php with a routed
 *  /dashboard page showing package status, wallet (both buckets),
 *  referral link, recent orders and commission totals")
 *
 *  Route (Appendix A): GET /dashboard   guard `member`
 *
 *  Admins keep the operations dashboard: /dashboard hands them to
 *  AdminDashboardController, so both entry points land on the same
 *  back-office screen (the Phase 4 decision, unchanged).
 *
 *  Commission totals read CommissionService::totalsFor(), which
 *  returns zeros until the `commissions` table arrives in Phase 10.
 * ============================================================== */

final class DashboardController
{
    private WalletService $wallet;
    private SponsorService $sponsors;
    private CommissionService $commissions;

    public function __construct()
    {
        $this->wallet      = new WalletService();
        $this->sponsors    = new SponsorService();
        $this->commissions = new CommissionService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        if (is_admin($user)) {
            (new AdminDashboardController())->index();

            return;
        }

        $userId = (int) $user['id'];
        $member = Member::find($userId);

        if ($member === null) {
            View::error(404);
        }

        $orders     = Order::listForUser($userId, [], new Paginator(5, 5, 1));
        $orderStats = Order::statsForUser($userId);
        $open       = Activation::latestOpen($userId);
        $last       = $open === null ? (Activation::forUser($userId)[0] ?? null) : $open;

        $history = Activation::forUser($userId);

        View::page('member', 'member/dashboard', [
            'page_title'      => 'Dashboard',
            'current_nav'     => 'dashboard',
            'page_css'        => ['assets/css/membership.css', 'assets/css/orders.css'],
            'member'          => $member,
            'balance'         => $this->wallet->balance($userId),
            'withdrawable'    => $this->wallet->withdrawable($userId),
            'orders'          => $orders,
            'order_stats'     => $orderStats,
            'commissions'     => $this->commissions->totalsFor($userId),
            'team'            => $this->sponsors->teamStats($userId),
            'open_activation' => $open,
            'last_activation' => $last === null ? null : $last,
            'activation_count' => count($history),
            'referral_code'   => (string) ($member['referral_code'] ?? ''),
            'referral_link'   => url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
            'recent_history'  => array_slice($history, 0, 3),
        ]);
    }
}
