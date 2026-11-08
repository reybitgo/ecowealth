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
 *
 *  Both pages are live surfaces: `admin.members` for the directory and
 *  `admin.members:{id}` for one member. The detail surface is per member,
 *  so a background refresh on one page never touches another.
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
        /* The same URL doubles as the JSON feed: X-Live: 1 returns the
         * regions instead of the layout, so pagination links and the filter
         * form keep pointing at /admin/members. */
        if (Live::isFeedRequest()) {
            $this->listFeed();
            return;
        }

        View::page('admin', 'admin/members/index', $this->listContext() + [
            'page_title'  => 'Members',
            'current_nav' => 'admin.members',
            'page_css'    => [
                'assets/css/membership.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
                'assets/js/admin-live.js',
            ],
            'live'        => $this->listLiveMeta(),
        ]);
    }

    /**
     * Everything the directory renders, built once for the page and feed.
     *
     * @return array<string, mixed>
     */
    public function listContext(): array
    {
        $filters = Member::normaliseFilters([
            'q'       => Request::string('q'),
            'status'  => Request::string('status'),
            'package' => Request::string('package'),
        ]);

        $pager = Paginator::fromTableRequest(Member::countAdmin($filters));

        return [
            'stats'    => Member::stats(),
            'filters'  => $filters,
            'pager'    => $pager,
            'members'  => Member::adminList($filters, $pager),
            'packages' => Member::packageBreakdown(),
        ];
    }

    /**
     * ETag scope for the directory.
     *
     * `new_week` counts from CURDATE(), so the day is part of the scope: a
     * tab left open across midnight revalidates on its next poll.
     */
    private function listScope(int $userId): string
    {
        return Live::scope($_GET, $userId) . '|day:' . date('Y-m-d');
    }

    /** @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int} */
    private function listLiveMeta(): array
    {
        return Live::meta('admin.members', $this->listScope((int) (auth_user()['id'] ?? 0)));
    }

    /**
     * JSON feed for the open directory.
     *
     * The 304 check runs before listContext(), so an unchanged surface
     * costs one revision lookup and no list/aggregate queries. The rows
     * carry the suspend/restore form, so the CSRF token is primed before
     * the session lock is released.
     */
    private function listFeed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        csrf_token();
        session_write_close();

        $surface = 'admin.members';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->listScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->listContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] member directory feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the member directory. Please try again.', 500);
        }
    }

    /**
     * Render the directory payload. Public so the tests can assert the
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
            'surface'   => 'admin.members',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'member-header' => View::capture('admin/members/_header', [
                    'stats' => $data['stats'],
                ]),
                'member-stats' => View::capture('admin/members/_stats', [
                    'stats' => $data['stats'],
                ]),
                'member-filters' => View::capture('admin/members/_filters', [
                    'filters'  => $data['filters'],
                    'packages' => $data['packages'],
                ]),
                'member-rows' => View::capture('admin/members/_rows', [
                    'members' => $data['members'],
                    'filters' => $data['filters'],
                ]),
                'member-pagination' => View::capture('admin/members/_pagination', [
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

    /** The directory URL with an out-of-range page clamped to a real page. */
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

        return url('admin/members' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): void
    {
        $userId = (int) ($params['id'] ?? 0);

        if (Live::isFeedRequest()) {
            $this->detailFeed($userId);
            return;
        }

        $member = Member::find($userId);

        if ($member === null) {
            View::error(404);
        }

        View::page('admin', 'admin/members/show', $this->memberContext($member) + [
            'page_title'  => 'Member — ' . (string) $member['username'],
            'current_nav' => 'admin.members',
            'page_css'    => [
                'assets/css/membership.css',
                'assets/vendor/libs/sweetalert2/sweetalert2.css',
            ],
            'page_js'     => [
                'assets/vendor/libs/sweetalert2/sweetalert2.js',
                'assets/js/app-confirm.js',
            ],
            'live'        => Live::meta(
                Live::memberSurface($userId),
                Live::scope($_GET, (int) (auth_user()['id'] ?? 0))
            ),
        ]);
    }

    /**
     * Everything one member page renders, built once for the page and its
     * feed. Public so the view tests can assert the real screen data.
     *
     * @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    public function memberContext(array $member): array
    {
        $userId = (int) $member['id'];
        $status = (string) $member['member_status'];

        $statusLabel = match (true) {
            $status === 'active' && $member['package_id'] !== null => 'Active associate',
            $status === 'active'                                  => 'Standard member',
            $status === 'pending'                                 => 'Pending activation',
            $status === 'suspended'                               => 'Suspended',
            default                                               => ucfirst($status),
        };

        $displayName = trim((string) ($member['full_name'] ?? '')) !== ''
            ? (string) $member['full_name']
            : (string) $member['username'];

        $referralLink = $member['referral_code'] === null
            ? ''
            : absolute_url('register?ref=' . rawurlencode((string) $member['referral_code']));

        $activationsPager = Paginator::fromTableRequest(Activation::countForUser($userId));

        return [
            'member'            => $member,
            'member_id'         => $userId,
            'display_name'      => $displayName,
            'status_label'      => $statusLabel,
            'is_suspended'      => $status === 'suspended',
            'referral_link'     => $referralLink,
            'balance'           => $this->wallet->balance($userId),
            'withdrawable'      => $this->wallet->withdrawable($userId),
            'activations'       => Activation::pageForUser($userId, $activationsPager),
            'activations_pager' => $activationsPager,
            'orders'            => Order::statsForUser($userId),
            'commissions'       => (new CommissionService())->totalsFor($userId),
            'team'              => (new SponsorService())->teamStats($userId),
            'upline'            => (new SponsorService())->chain($userId, SponsorService::MAX_LEVELS),
            'audits'            => AuditLog::forSubject('user', $userId, 8),
            'statement_url'     => url('admin/wallets/' . $userId),
        ];
    }

    /**
     * JSON feed for one open member page.
     *
     * The 304 check runs before the member read, so an unchanged member
     * costs one revision lookup. The heading carries the suspend/restore
     * form, so the CSRF token is primed before the session lock is
     * released.
     */
    private function detailFeed(int $userId): void
    {
        $adminId = (int) (auth_user()['id'] ?? 0);

        csrf_token();
        session_write_close();

        $surface = Live::memberSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $adminId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $member = Member::find($userId);

            if ($member === null) {
                Live::jsonError('Member not found.', 404);
            }

            Live::json($etag, $this->detailFeedPayload($revision, $etag, $this->memberContext($member)));
        } catch (Throwable $e) {
            error_log('[ecowealth] member detail live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh this member. Please try again.', 500);
        }
    }

    /**
     * Render the member detail payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function detailFeedPayload(int $revision, string $etag, array $data): array
    {
        $member = $data['member'];

        return [
            'ok'        => true,
            'surface'   => Live::memberSurface((int) $member['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'member-heading' => View::capture('admin/members/_heading', [
                    'member'        => $member,
                    'member_id'     => $data['member_id'],
                    'display_name'  => $data['display_name'],
                    'status_label'  => $data['status_label'],
                    'is_suspended'  => $data['is_suspended'],
                    'statement_url' => $data['statement_url'],
                ]),
                'member-account' => View::capture('admin/members/_account', [
                    'member'        => $member,
                    'display_name'  => $data['display_name'],
                    'referral_link' => $data['referral_link'],
                ]),
                'member-membership' => View::capture('admin/members/_membership', [
                    'member'       => $member,
                    'status_label' => $data['status_label'],
                    'team'         => $data['team'],
                ]),
                'member-wallet' => View::capture('admin/members/_wallet', [
                    'member'        => $member,
                    'balance'       => $data['balance'],
                    'withdrawable'  => $data['withdrawable'],
                    'orders'        => $data['orders'],
                    'commissions'   => $data['commissions'],
                    'activations'   => $data['activations'],
                    'statement_url' => $data['statement_url'],
                ]),
                'member-activations' => View::capture('admin/members/_activations', [
                    'activations'       => $data['activations'],
                    'activations_pager' => $data['activations_pager'],
                ]),
                'member-upline' => View::capture('admin/members/_upline', [
                    'upline' => $data['upline'],
                ]),
                'member-audit' => View::capture('admin/members/_audit', [
                    'audits' => $data['audits'],
                ]),
            ],
            'meta'      => [
                'canonical' => $this->canonicalDetailUrl((int) $member['id'], $data['activations_pager']),
            ],
        ];
    }

    /** The member URL with the activation-history page clamped. */
    private function canonicalDetailUrl(int $userId, Paginator $pager): string
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

        return url('admin/members/' . $userId . ($qs !== '' ? '?' . $qs : ''));
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

        Live::publish([
            'admin.dashboard',
            'admin.wallets',
            'admin.members',
            Live::walletSurface($id),
            Live::memberSurface($id),
            Live::membershipSurface($id),
            Live::GENEALOGY_TEAM,
        ]);

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

        Live::publish([
            'admin.dashboard',
            'admin.wallets',
            'admin.members',
            Live::walletSurface($id),
            Live::memberSurface($id),
            Live::membershipSurface($id),
            Live::GENEALOGY_TEAM,
        ]);

        flash_set('success', sprintf(
            '@%s is now %s.',
            (string) $member['username'],
            (string) $member['member_status']
        ));

        redirect('admin/members/' . $id);
    }
}
