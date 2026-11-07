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

        /* The same URL doubles as the JSON feed. The surface only covers
         * the E-wallet KPI and card, so the feed never re-renders the rest
         * of the dashboard. Admins were delegated above and keep the
         * admin.dashboard feed. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

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
            'referral_link'   => absolute_url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
            'recent_history'  => array_slice($history, 0, 3),
            'live'            => Live::meta(
                Live::memberDashboardSurface($userId),
                Live::scope($_GET, $userId)
            ),
        ]);
    }

    /**
     * JSON feed for the member dashboard's wallet widgets.
     *
     * The 304 check runs before the account read, so an unchanged balance
     * costs one revision lookup. No fragment renders a form, so the session
     * lock is released immediately.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = Live::memberDashboardSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $account = Wallet::account($userId);

            if ($account === null) {
                Live::jsonError('Account not found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $account));
        } catch (Throwable $e) {
            error_log('[ecowealth] member dashboard live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the dashboard. Please try again.', 500);
        }
    }

    /**
     * Render the member dashboard feed. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $account): array
    {
        $userId       = (int) $account['id'];
        $balance      = round((float) $account['ewallet_balance'], 2);
        $withdrawable = round((float) $account['withdrawable_balance'], 2);

        return [
            'ok'        => true,
            'surface'   => Live::memberDashboardSurface($userId),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'dashboard-wallet-kpi' => View::capture('member/dashboard/_wallet_kpi', [
                    'balance'      => $balance,
                    'withdrawable' => $withdrawable,
                ]),
                'dashboard-wallet' => View::capture('member/dashboard/_wallet_card', [
                    'balance'      => $balance,
                    'withdrawable' => $withdrawable,
                ]),
            ],
            'meta'      => [
                'canonical' => url('dashboard'),
            ],
        ];
    }
}
