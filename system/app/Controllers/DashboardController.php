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
 *  (`member.dashboard:{id}`) with nine regions — the header status pill,
 *  the KPI row, the E-wallet card, the membership card, the recent-orders
 *  card, the earnings card, the Royalty tile, the Team Harvest tile
 *  (Phase 20F) and the General Harvest tile (Phase 21F). The greeting,
 *  the referral card and the static explanatory copy stay outside the
 *  regions.
 *
 *  Phase 18 adds the escrow read-out (`pending_repeat`): the repeat
 *  bonus the member has earned but that is not in the wallet yet. The
 *  KPI row and the E-wallet card state it beside the withdrawable
 *  figure, and the earnings card splits the repeat-purchase total into
 *  credited / pending release / total (plan §10.2). The existing
 *  balance and withdrawable figures never change meaning.
 *
 *  Phase 19E adds the Royalty tile (`royalty`). It is a read-only
 *  projection of the member's rank and this month's group sales, plus
 *  the Royalty they have already been paid, and it links to the Royalty
 *  statement. It is hidden only when the stream is off **and** the
 *  member has no history (plan §9 Rule 5).
 *
 *  Phase 20F adds the Team Harvest tile (`team_harvest`): the paid total,
 *  the harvest position, and the next level's progress against its
 *  amount. It reads the same `TeamHarvestService::statusFor()` the
 *  member's /earnings tab and the admin panel use, so the tile can never
 *  promise a level the engine would not pay (plan §10 Rule 9). It is a
 *  read-out with no form, and it is hidden only when the stream is off
 *  and the member holds neither a position nor history.
 *
 *  Phase 21F adds the General Harvest tile (`general_harvest`): the paid
 *  total, the board position, the queue size and the next block's exact
 *  progress and threshold. It reads `GeneralHarvestService::statusFor()`
 *  — the same read the member's /earnings tab and the admin member panel
 *  use — so the tile can never promise a block the engine would not pay,
 *  and it carries no form. The two hard facts (the whole-network board and
 *  the purchases/rank/Active exclusion) stay on the block card and are
 *  never repeated here (Rule 2, Rule 7, P21F-T13/T14).
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
            'unilevel'         => $this->unilevelSummary($userId),
            /* The escrow projection the KPI row and the E-wallet card
             * read (plan §10.2, §18.2). The model guards a missing
             * table, so this is zero on an install without Phase 17. */
            'pending_repeat'   => UnilevelAward::pendingSummaryForUser($userId),
            'royalty'          => $this->royaltySummary($userId),
            'team_harvest'     => $this->teamHarvestSummary($userId),
            'general_harvest'  => $this->generalHarvestSummary($userId),
            'binary'           => $this->binarySummary($userId),
            'referral_code'    => (string) ($member['referral_code'] ?? ''),
            'referral_link'    => absolute_url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
        ];
    }

    /**
     * The Team Harvest tile's figures (20F, plan §10, Appendix C.4).
     *
     * The read is `TeamHarvestService::statusFor()` — the same read the
     * member's Team Harvest statement and the admin member panel use — so
     * the tile can never show a level, an amount or a progress the engine
     * would not pay (Rule 9). It is wrapped because the dashboard must
     * render even when the projection is unavailable: a broken read hides
     * the tile, never the page.
     *
     * The tile is a read-out: it carries no form and no action beyond the
     * link to the statement (P20F-T10).
     *
     * @return array<string, mixed>|null
     */
    private function teamHarvestSummary(int $userId): ?array
    {
        try {
            return (new TeamHarvestService())->statusFor($userId);
        } catch (Throwable $e) {
            error_log('[ecowealth] team harvest dashboard tile unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The General Harvest tile's figures (21F, plan §10, Appendix C.4).
     *
     * The read is `GeneralHarvestService::statusFor()` — the same read the
     * member's General Harvest statement and the admin member panel use —
     * so the tile can never show a block, an amount or a progress the
     * engine would not pay (Rule 10). It is wrapped because the dashboard
     * must render even when the projection is unavailable: a broken read
     * hides the tile, never the page.
     *
     * The tile is a read-out: it carries no form and no action beyond the
     * link to the statement (P21F-T10).
     *
     * @return array<string, mixed>|null
     */
    private function generalHarvestSummary(int $userId): ?array
    {
        try {
            return (new GeneralHarvestService())->statusFor($userId);
        } catch (Throwable $e) {
            error_log('[ecowealth] general harvest dashboard tile unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The Product binary tile's figures (22F-S3, plan §14, Appendix C.5).
     *
     * The read is `BinaryService::totalsFor()` — the same read the member's
     * binary statement uses — so the tile can never show a balance the
     * ledger would not report. Wrapped so a missing table or a bad read
     * hides the tile, never the page.
     *
     * @return array<string, mixed>|null
     */
    private function binarySummary(int $userId): ?array
    {
        if (!BinaryService::enabled()) {
            return null;
        }

        try {
            return (new BinaryService())->totalsFor($userId);
        } catch (Throwable $e) {
            error_log('[ecowealth] binary dashboard tile unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The Royalty tile's figures (19E, plan §9, Appendix C.4).
     *
     * The read is `RoyaltyService::statusFor()` — the same read the
     * member's Royalty statement and the admin member panel use — so the
     * tile can never promise an amount the close would not pay. It is
     * wrapped because the dashboard must render even when the projection
     * is unavailable: a broken read hides the tile, never the page.
     *
     * `has_history` is read from the same status call (its recent-award
     * list), so a member who earned Royalty before the stream was
     * switched off keeps a way to their statement (Rule 5).
     *
     * @return array<string, mixed>|null
     */
    private function royaltySummary(int $userId): ?array
    {
        try {
            $status = (new RoyaltyService())->statusFor($userId);
        } catch (Throwable $e) {
            error_log('[ecowealth] royalty dashboard tile unavailable: ' . $e->getMessage());

            return null;
        }

        $history = is_array($status['history'] ?? null) ? $status['history'] : [];

        return [
            'enabled'          => (bool) ($status['enabled'] ?? false),
            'payments_enabled' => (bool) ($status['payments_enabled'] ?? true),
            'rank'             => (int) ($status['rank'] ?? RoyaltyRank::MEMBER),
            'rank_name'        => (string) ($status['rank_name'] ?? 'Member'),
            'personal'         => (float) ($status['personal'] ?? 0),
            'group'            => (float) ($status['group'] ?? 0),
            'quota'            => (float) ($status['quota'] ?? 0),
            'progress'         => max(0, min(100, (int) ($status['progress'] ?? 0))),
            'projected'        => (float) ($status['projected'] ?? 0),
            'eligible'         => (bool) ($status['eligible'] ?? false),
            'next'             => is_array($status['next'] ?? null) ? $status['next'] : null,
            'paid_all_time'    => (float) ($status['paid_all_time'] ?? 0),
            'last_paid'        => is_array($status['last_paid'] ?? null) ? $status['last_paid'] : null,
            'has_history'      => $history !== [],
        ];
    }

    /**
     * The repeat-purchase tile (17E, plan §9).
     *
     * `totals` is what the member earned as an upline; `status` is the
     * two-clock Active summary. The tile never shows an "as buyer"
     * figure — a member earns nothing on their own purchase (Rule 9) —
     * and it has no action: it is a read-out, not a form.
     *
     * `$enabled` lets the view hide the tile on an install where the
     * stream has never been switched on and the member has no history.
     * The earnings tab itself stays reachable either way, because a
     * disabled stream can still have history from before it was
     * switched off.
     *
     * @return array{totals:array<string, mixed>, status:array<string, mixed>, enabled:bool}
     */
    private function unilevelSummary(int $userId): array
    {
        return [
            'totals'  => UnilevelAward::totalsForUser($userId),
            'status'  => UnilevelCycle::memberStatus($userId),
            'enabled' => UnilevelService::enabled(),
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
                    'member'         => $member,
                    'commissions'    => $data['commissions'],
                    'team'           => $data['team'],
                    'balance'        => $data['balance'],
                    'withdrawable'   => $data['withdrawable'],
                    'pending_repeat' => $data['pending_repeat'] ?? [],
                ]),
                'dashboard-wallet' => View::capture('member/dashboard/_wallet_card', [
                    'balance'        => $data['balance'],
                    'withdrawable'   => $data['withdrawable'],
                    'pending_repeat' => $data['pending_repeat'] ?? [],
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
                    'unilevel'       => $data['unilevel'] ?? null,
                ]),
                'dashboard-royalty' => View::capture('member/dashboard/_royalty', [
                    'royalty' => $data['royalty'] ?? null,
                ]),
                'dashboard-team-harvest' => View::capture('member/dashboard/_team_harvest', [
                    'team_harvest' => $data['team_harvest'] ?? null,
                ]),
                'dashboard-general-harvest' => View::capture('member/dashboard/_general_harvest', [
                    'general_harvest' => $data['general_harvest'] ?? null,
                ]),
            ],
            'meta'      => [
                'canonical' => url('dashboard'),
            ],
        ];
    }
}
