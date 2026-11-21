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
 *      POST /admin/members/{id}/placement audited empty-subtree move (22C)
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
     * Phase 20E adds two things: the `?harvest=yes|no` filter (through
     * `Member::normaliseFilters()`, so an unknown value means "no filter")
     * and the Team Harvest queue card. The member's position arrives inside
     * `Member::adminList()`'s own query, so no row costs a second query.
     *
     * @return array<string, mixed>
     */
    public function listContext(): array
    {
        $filters = Member::normaliseFilters([
            'q'        => Request::string('q'),
            'status'   => Request::string('status'),
            'package'  => Request::string('package'),
            'harvest'  => Request::string('harvest'),
            'board'    => Request::string('board'),
            'placement'=> Request::string('placement'),
        ]);

        $pager = Paginator::fromTableRequest(Member::countAdmin($filters));

        /* Phase 19B: one rank pass for the whole directory. The map is
         * derived from the sponsor tree and writes nothing; the page only
         * needs the badge per row and the distribution for the counters. */
        $rankMap = RoyaltyRank::map();
        $members = Member::adminList($filters, $pager);

        foreach ($members as $index => $row) {
            $rank = $rankMap[(int) $row['id']] ?? null;

            $members[$index]['rank_level'] = $rank === null ? null : (int) $rank['rank'];
            $members[$index]['rank_name']  = $rank === null ? null : (string) $rank['rank_name'];
        }

        return [
            'stats'        => Member::stats(),
            'rank_counts'  => RoyaltyRank::counts($rankMap),
            'filters'      => $filters,
            'pager'        => $pager,
            'members'      => $members,
            'packages'     => Member::packageBreakdown(),
            'harvest'      => $this->harvestQueueCard(),
            'general_harvest' => $this->generalHarvestBoardCard(),
        ];
    }

    /**
     * The Team Harvest queue card's data (Phase 20E, plan §9 Rules 7–8).
     *
     * Read-only: the queue's shape from `Report::teamHarvestQueue()` (one
     * definition of the position sequence, `HarvestQueue::stats()`) plus the
     * engine's `openLiability()` — the number the card shows, never a
     * warning and never a stored figure.
     *
     * A failure hides the card, never the directory (the same stance the
     * Royalty panel takes).
     *
     * @return array<string, mixed>|null
     */
    private function harvestQueueCard(): ?array
    {
        try {
            $queue = Report::teamHarvestQueue(10);
            $queue['open'] = (new TeamHarvestService())->openLiability();

            return $queue;
        } catch (Throwable $e) {
            error_log('[ecowealth] team harvest queue card unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The General Harvest board card's data (Phase 21E, plan §9 Rules 7–9).
     *
     * Read-only over the board's state and the closed-form liability. The
     * frontier names what is about to pay and the open liability is the
     * amount a settlement would write now — a number, never a warning.
     *
     * A failure hides the card, never the directory.
     *
     * @return array<string, mixed>|null
     */
    private function generalHarvestBoardCard(): ?array
    {
        try {
            $service = new GeneralHarvestService();

            return [
                'state'   => $service->state(),
                'frontier'=> array_values(GeneralHarvestBoard::frontier()),
                'backlog' => $service->backlog(),
                'open'    => $service->openLiability(),
            ];
        } catch (Throwable $e) {
            error_log('[ecowealth] general harvest board card unavailable: ' . $e->getMessage());

            return null;
        }
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
                    'stats'       => $data['stats'],
                    'rank_counts' => $data['rank_counts'] ?? [],
                ]),
                'member-filters' => View::capture('admin/members/_filters', [
                    'filters'  => $data['filters'],
                    'packages' => $data['packages'],
                ]),
                /* Phase 20E: the queue card is its own region, so an open
                 * directory tab keeps it current when a qualification or an
                 * award bumps `admin.members` (P20E-T15). */
                'member-harvest' => View::capture('admin/members/_harvest', [
                    'harvest' => $data['harvest'] ?? null,
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
            /* Phase 18: earned but not in the wallet; the card shows it
             * as a separate line (plan §19.5). */
            'pending_repeat'    => UnilevelAward::pendingSummaryForUser($userId),
            /* Phase 19D: the read-only Royalty panel (rank, progress,
             * this month's PS/GS/quota, recent awards, all-time paid). */
            'royalty'           => $this->royaltyPanel($userId),
            /* Phase 20E: the read-only Team Harvest panel — position,
             * qualified at, teammates, the ten levels, the next level and
             * every stored award row. */
            'team_harvest'      => $this->teamHarvestPanel($userId),
            /* Phase 21E: the read-only General Harvest panel — position,
             * node, qualified at, the ten-cell grid, the next level and the
             * award rows. */
            'general_harvest'   => $this->generalHarvestPanel($userId),
            'team'              => (new SponsorService())->teamStats($userId),
            'upline'            => (new SponsorService())->chain($userId, SponsorService::MAX_LEVELS),
            'audits'            => AuditLog::forSubject('user', $userId, 8),
            'statement_url'     => url('admin/wallets/' . $userId),
            /* Phase 22C: the read-only binary placement panel — node facts
             * and a move form. Hidden when the member has no node. */
            'binary_placement'  => $this->binaryPlacementPanel($userId),
            /* Phase 22E: the read-only binary member panel — node facts,
             * balances, pairs, blocked-pair reasons, recent volume and
             * awards. Read-only: nothing here writes a node, an award or a
             * wallet row. */
            'binary'            => $this->binaryMemberPanel($userId),
        ];
    }

    /**
     * The member's Royalty panel data (plan §8 Phase 19D).
     *
     * `RoyaltyService::statusFor()` uses the same rank map, volumes and
     * award arithmetic as the close, so the panel cannot promise an
     * amount the engine would not pay. Read-only: the service's only
     * write is a payment, and this call never reaches it.
     *
     * @return array<string, mixed>|null null when the stream's tables
     *                                    are unavailable (never blanks
     *                                    the member page)
     */
    private function royaltyPanel(int $userId): ?array
    {
        try {
            return (new RoyaltyService())->statusFor($userId);
        } catch (Throwable $e) {
            error_log('[ecowealth] member royalty panel unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The member's Team Harvest panel data (plan §9 Phase 20E).
     *
     * `TeamHarvestService::statusFor()` is the same read 20F's member card
     * uses — the position, the ten levels with their state, the next level
     * and the money already paid — so the admin panel and the member's own
     * page can never promise different numbers. The stored award rows come
     * from `TeamHarvestAward::forMember()`, the ledger the panel lists.
     *
     * Read-only: the service's only write is an evaluation, and this call
     * never reaches it. A failure hides the panel, never the member page.
     *
     * @return array<string, mixed>|null
     */
    private function teamHarvestPanel(int $userId): ?array
    {
        try {
            $status = (new TeamHarvestService())->statusFor($userId);

            return $status + ['awards' => TeamHarvestAward::forMember($userId)];
        } catch (Throwable $e) {
            error_log('[ecowealth] member team harvest panel unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The member's General Harvest panel data (Phase 21E, plan §9 Rules 2,
     * 5, 9–12).
     *
     * `GeneralHarvestService::statusFor()` is the same read 21F's member
     * card uses — position, node, the ten levels with block ranges and fill,
     * paid levels, next level and the open amount — so the admin panel and
     * the member's own page can never promise different numbers. The stored
     * award rows come from `GeneralHarvestAward::forMember()`, the ledger
     * the panel lists.
     *
     * Read-only: nothing here writes an award, a wallet row or the board row.
     * A failure hides the panel, never the member page.
     *
     * @return array<string, mixed>|null
     */
    private function generalHarvestPanel(int $userId): ?array
    {
        try {
            return (new GeneralHarvestService())->statusFor($userId)
                + ['awards' => GeneralHarvestAward::forMember($userId)];
        } catch (Throwable $e) {
            error_log('[ecowealth] member general harvest panel unavailable: ' . $e->getMessage());

            return null;
        }
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
                    'member'         => $member,
                    'balance'        => $data['balance'],
                    'withdrawable'   => $data['withdrawable'],
                    'orders'         => $data['orders'],
                    'commissions'    => $data['commissions'],
                    'pending_repeat' => $data['pending_repeat'],
                    'activations'    => $data['activations'],
                    'statement_url'  => $data['statement_url'],
                ]),
                'member-royalty' => View::capture('admin/members/_royalty', [
                    'member'  => $member,
                    'royalty' => $data['royalty'] ?? null,
                ]),
                'member-team-harvest' => View::capture('admin/members/_team_harvest', [
                    'member'       => $member,
                    'team_harvest' => $data['team_harvest'] ?? null,
                ]),
                'member-general-harvest' => View::capture('admin/members/_general_harvest', [
                    'member'            => $member,
                    'general_harvest'   => $data['general_harvest'] ?? null,
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
                'member-binary' => View::capture('admin/members/_binary', [
                    'member'  => $member,
                    'member_id' => $data['member_id'],
                    'binary'  => $data['binary'] ?? [
                        'node'          => null,
                        'has_node'      => false,
                        'totals'        => [],
                        'awards'        => [],
                        'volume'        => [],
                        'blocked'       => [],
                        'pairs_today'   => 0,
                        'daily_cap'     => setting_int('binary_daily_pair_cap', 20),
                        'tree_link_disabled' => true,
                    ],
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

    /* ----------------------------------------------------------
     *  Phase 22C — binary placement
     * ---------------------------------------------------------- */

    /**
     * Read-only placement facts for one member's detail page.
     *
     * @return array{node:array<string,mixed>|null, has_node:bool, slots:list<array{side:string, free:bool}>}
     */
    private function binaryPlacementPanel(int $userId): array
    {
        $node = null;
        $hasNode = false;
        $slots = [];

        if (BinaryPlacementService::isEnabled()) {
            $node = BinaryNode::forUser($userId);
            $hasNode = $node !== null;

            if ($hasNode) {
                $uplineId = (int) ($node['upline_id'] ?? 0);
                if ($uplineId > 0) {
                    $slots = (new BinaryPlacementService())->slotsFor($uplineId);
                }
            }
        }

        return [
            'node'     => $node,
            'has_node' => $hasNode,
            'slots'    => $slots,
        ];
    }

    /**
     * The read-only Product Binary panel data for one member (Phase 22E).
     *
     * Reads node facts, award and volume rows, the daily-pair count and the
     * blocked nodes in the member's subtree. Nothing is written here — the
     * panel only displays what 22C and 22D already stored.
     *
     * @return array{
     *   node:array<string,mixed>|null,
     *   has_node:bool,
     *   totals:array<string,mixed>,
     *   awards:list<array<string,mixed>>,
     *   volume:list<array<string,mixed>>,
     *   blocked:list<array<string,mixed>>,
     *   pairs_today:int,
     *   daily_cap:int,
     *   tree_link_disabled:bool
     * }
     */
    private function binaryMemberPanel(int $userId): array
    {
        $hasNode = false;
        $node = null;
        $totals = [];
        $awards = [];
        $volume = [];
        $blocked = [];
        $pairsToday = 0;
        $dailyCap = (int) setting_int('binary_daily_pair_cap', 20);

        if (BinaryPlacementService::isEnabled()) {
            $node = BinaryNode::forUser($userId);
            $hasNode = $node !== null;

            if ($hasNode) {
                $totals = (new BinaryService())->totalsFor($userId);
                $awards = BinaryAward::forUser($userId);
                $volume = BinaryVolume::forNode($userId);
                $blocked = BinaryNode::blockedPairs($userId);

                /* Pairs today against the Asia/Manila day of "now". */
                $pairsToday = BinaryService::pairsToday($userId, date('Y-m-d H:i:s'));
            }
        }

        return [
            'node'             => $node,
            'has_node'         => $hasNode,
            'totals'           => $totals,
            'awards'           => $awards,
            'volume'           => $volume,
            'blocked'          => $blocked,
            'pairs_today'      => $pairsToday,
            'daily_cap'        => $dailyCap,
            'tree_link_disabled' => true,
        ];
    }

    /**
     * POST /admin/members/{id}/placement — audited empty-subtree move.
     *
     * Only an admin may call this (router guard). CSRF is verified by the
     * router before the handler runs. The move itself is delegated to
     * `BinaryPlacementService::move()`, which refuses non-empty subtrees,
     * cycles, taken slots and the company root, and audits before/after.
     *
     * @param array<string, string> $params
     */
    public function placement(array $params = []): void
    {
        $id     = (int) ($params['id'] ?? 0);
        $admin  = auth_user();

        if ($admin === null) {
            redirect('login');
        }

        if (!is_admin()) {
            View::error(403);
            return;
        }

        $member = Member::find($id);
        if ($member === null) {
            View::error(404);
            return;
        }

        $newUpline = strtolower(trim(Request::string('binary_upline')));
        $newSide   = strtolower(trim(Request::string('binary_position')));

        $errors = [];

        if ($newUpline === '') {
            $errors['binary_upline'] = 'Choose a binary upline.';
        }
        if (!in_array($newSide, BinaryNode::SIDES, true)) {
            $errors['binary_position'] = 'Choose left or right.';
        }

        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                flash_set('danger', $message);
            }
            redirect('admin/members/' . $id);
        }

        $resolved = (new BinaryPlacementService())->resolveUpline($newUpline);
        if ($resolved === null) {
            flash_set('danger', 'Unknown member: ' . e($newUpline) . '.');
            redirect('admin/members/' . $id);
        }

        try {
            $result = (new BinaryPlacementService())->move(
                $id,
                (int) $resolved['id'],
                $newSide,
                (int) $admin['id']
            );

            flash_set('success', sprintf(
                'Moved @%s under @%s on the %s side.',
                (string) $member['username'],
                (string) $resolved['username'],
                BinaryNode::SIDE_LABELS[$newSide] ?? $newSide
            ));
        } catch (BinaryException $e) {
            flash_set('danger', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[ecowealth] binary placement move failed: ' . $e->getMessage());
            flash_set('danger', 'The move could not be completed. Please try again.');
        }

        Live::publish([
            'admin.dashboard',
            'admin.members',
            Live::memberSurface($id),
        ]);

        redirect('admin/members/' . $id);
    }
}
