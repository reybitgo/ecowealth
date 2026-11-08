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
 *
 *  Live updates: the page is one per-member surface
 *  (`member.dashboard:{id}`) with six regions — the header status pill,
 *  the KPI row, the E-wallet card, the membership card, the recent-orders
 *  card and the earnings card. The greeting, the referral card and the
 *  static explanatory copy stay outside the regions.
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

        /* The same URL doubles as the JSON feed. The feed never renders
         * the layout; admins were delegated above and keep the
         * admin.dashboard feed. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        $member = Member::find($userId);

        if ($member === null) {
            View::error(404);
        }

        View::page('member', 'member/dashboard', $this->dashboardContext($userId, $member) + [
            'page_title'  => 'Dashboard',
            'current_nav' => 'dashboard',
            'page_css'    => ['assets/css/membership.css', 'assets/css/orders.css'],
            'live'        => Live::meta(
                Live::memberDashboardSurface($userId),
                $this->dashboardScope($userId)
            ),
        ]);
    }

    /**
     * Everything the dashboard renders, built once for the page and its
     * feed so the two can never drift. Public so the view tests can build
     * the real screen data.
     *
     * @param array<string, mixed>|null $member  a row already read by the caller
     * @return array<string, mixed>
     */
    public function dashboardContext(int $userId, ?array $member = null): array
    {
        $member ??= Member::find($userId);

        if ($member === null) {
            throw new RuntimeException('The dashboard member no longer exists.');
        }

        $orders     = Order::listForUser($userId, [], new Paginator(5, 5, 1));
        $orderStats = Order::statsForUser($userId);
        $open       = Activation::latestOpen($userId);
        $history    = Activation::forUser($userId);
        $last       = $open === null ? ($history[0] ?? null) : $open;

        return [
            'member'           => $member,
            'balance'          => $this->wallet->balance($userId),
            'withdrawable'     => $this->wallet->withdrawable($userId),
            'orders'           => $orders,
            'order_stats'      => $orderStats,
            'commissions'      => $this->commissions->totalsFor($userId),
            'team'             => $this->sponsors->teamStats($userId),
            'open_activation'  => $open,
            'last_activation'  => $last === null ? null : $last,
            'activation_count' => count($history),
            'recent_history'   => array_slice($history, 0, 3),
            'referral_code'    => (string) ($member['referral_code'] ?? ''),
            'referral_link'    => absolute_url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
        ];
    }

    /**
     * ETag scope for the dashboard.
     *
     * "This month" counts from CURDATE(), so the day belongs in the scope:
     * a tab left open across midnight revalidates on its next poll.
     *
     * The team counters are shared. A registration moves every ancestor's
     * direct/team figures and a status/name change rewrites the upline
     * row, so the genealogy revision is mixed in instead of walking the
     * tree on every write. Package names come from the shared
     * configuration revision, which an admin package save also bumps.
     */
    private function dashboardScope(int $userId): string
    {
        return Live::scope($_GET, $userId)
            . '|day:' . date('Y-m-d')
            . '|team:' . Live::revisionOrZero(Live::GENEALOGY_TEAM)
            . '|config:' . Live::revisionOrZero(Live::CONFIG);
    }

    /**
     * JSON feed for the member dashboard.
     *
     * The 304 check runs before the member and wallet reads, so an
     * unchanged dashboard costs one revision lookup. No fragment renders
     * a form, so the session lock is released immediately.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = Live::memberDashboardSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->dashboardScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $member = Member::find($userId);

            if ($member === null) {
                Live::jsonError('Your account could not be found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->dashboardContext($userId, $member)));
        } catch (Throwable $e) {
            error_log('[ecowealth] member dashboard live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the dashboard. Please try again.', 500);
        }
    }

    /**
     * Render the member dashboard feed. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data  dashboardContext()
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $member = $data['member'];

        return [
            'ok'        => true,
            'surface'   => Live::memberDashboardSurface((int) $member['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'dashboard-header' => View::capture('member/dashboard/_header', [
                    'member' => $member,
                ]),
                'dashboard-kpis' => View::capture('member/dashboard/_kpis', [
                    'member'      => $member,
                    'commissions' => $data['commissions'],
                    'team'        => $data['team'],
                    'balance'     => $data['balance'],
                    'withdrawable' => $data['withdrawable'],
                ]),
                'dashboard-wallet' => View::capture('member/dashboard/_wallet_card', [
                    'balance'      => $data['balance'],
                    'withdrawable' => $data['withdrawable'],
                ]),
                'dashboard-membership' => View::capture('member/dashboard/_membership', [
                    'member'           => $member,
                    'open_activation'  => $data['open_activation'],
                    'activation_count' => $data['activation_count'],
                ]),
                'dashboard-orders' => View::capture('member/dashboard/_orders', [
                    'orders'      => $data['orders'],
                    'order_stats' => $data['order_stats'],
                ]),
                'dashboard-earnings' => View::capture('member/dashboard/_earnings', [
                    'commissions'    => $data['commissions'],
                    'team'           => $data['team'],
                    'recent_history' => $data['recent_history'],
                ]),
            ],
            'meta'      => [
                'canonical' => url('dashboard'),
            ],
        ];
    }
}
