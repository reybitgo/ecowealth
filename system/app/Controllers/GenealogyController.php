<?php
declare(strict_types=1);

/* ==============================================================
 *  GenealogyController — the sponsor chain, up and down
 *  (plan §2.5, §7 Phase 9; reference MemberController::genealogy)
 *
 *  Route (Appendix A): GET /genealogy   guard `member`
 *
 *  Shows:
 *    • the member's own row first, then the team chain (levels 1–10)
 *    • direct recruits (level 1 of the downline)
 *    • the deeper team, grouped by level (max 10)
 *    • the member's own referral link
 *
 *  There is no binary tree anywhere: every row here comes from
 *  users.sponsor_id (plan §1.3). P9-T17 asserts the pages never
 *  mention a binary column.
 *
 *  The page is one live surface per member (`member.genealogy:{id}`).
 *  Header badges, team-chain rows, direct-recruit count/rows/pagination
 *  and the team-by-level card are regions; the referral block stays
 *  static so a background refresh never disturbs the copy control.
 *
 *  Phase 20F adds the **Harvest position badge**: the reader's own
 *  position in the header and each downline member's stored position
 *  beside their name (`—` when they are not in the queue). One
 *  `HarvestQueue::map()` read serves the whole page, so the badge adds one
 *  select and no N+1 however deep the team is; a position is displayed,
 *  never derived (plan §10 Rule 9).
 *
 *  Phase 21F adds the **board node** to the reader's own badge
 *  (`Harvest #13 · node 12`). A General Harvest node is the position minus
 *  one (§12.1), so the same queue read serves it and the badge costs no
 *  extra select. A downline member's node is never shown: the badge states
 *  a position, and the node is the reader's own board fact.
 *
 *  Every genealogy page also includes the shared `member.genealogy`
 *  revision in its ETag: a team change moves the ancestors' counters and
 *  lists and the descendants' upline chain, so one shared bump keeps the
 *  whole tree correct — and a qualification appends a queue row inside
 *  the same registration that bumps it.
 * ============================================================== */

final class GenealogyController
{
    private SponsorService $sponsors;

    public function __construct()
    {
        $this->sponsors = new SponsorService();
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        $userId  = (int) $user['id'];
        $isAdmin = is_admin($user);

        /* Phase 22G-S5: live feed for the binary tree — an open tree
         * refreshes its points and placement without a reload. The
         * ?format=json branch (above) is the direct endpoint; this
         * branch serves X-Live: 1 polling from app-live.js. */
        if (Live::isFeedRequest()) {
            if (Request::string('view') === 'binary') {
                try {
                    $payload = $this->binaryJson($userId, $isAdmin);
                    Live::json($payload['etag'], $payload);
                } catch (BinaryException $e) {
                    Live::jsonError($e->getMessage(), 403);
                } catch (Throwable $e) {
                    error_log('[ecowealth] binary live feed failed: ' . $e->getMessage());
                    Live::jsonError('Could not load the binary tree. Please try again.', 500);
                }
                return;
            }

            $this->feed($userId);
            return;
        }

        /* Phase 22G: binary tree JSON feed — same route, ?view=binary&format=json.
         * The sponsor view is never affected by this branch. */
        if (Request::string('view') === 'binary' && Request::string('format') === 'json') {
            try {
                $payload = $this->binaryJson($userId, $isAdmin);
                Live::json($payload['etag'], $payload);
            } catch (BinaryException $e) {
                Live::jsonError($e->getMessage(), 403);
            } catch (Throwable $e) {
                error_log('[ecowealth] binary tree feed failed: ' . $e->getMessage());
                Live::jsonError('Could not load the binary tree. Please try again.', 500);
            }
            return;
        }

        /* Phase 22G-S2: binary tree page — server-rendered first paint + no-JS list.
         * The sponsor view is never affected by this branch. */
        if (Request::string('view') === 'binary') {
            $requestedRoot = Request::query('root', null);
            $rootId        = $requestedRoot === null ? $userId : (int) $requestedRoot;
            $depth         = max(1, min(BinaryTreeService::MAX_DEPTH, (int) Request::query('depth', BinaryTreeService::DEFAULT_DEPTH)));

            /* The admin selector's "Company tree" is value 0: resolve it to
             * the company root node. Members never see the selector. */
            $companyRoot   = BinaryNode::root();
            $companyRootId = $companyRoot === null ? 0 : (int) ($companyRoot['user_id'] ?? 0);

            if ($isAdmin && $rootId === 0) {
                $rootId = $companyRootId > 0 ? $companyRootId : $userId;
            }

            $payload            = null;
            $listRows           = [];
            $unavailable        = '';
            $unavailableMessage = '';

            try {
                $payload  = (new BinaryTreeService())->subtree($rootId, $depth, $userId, $isAdmin);
                $listRows = (new BinaryTreeService())->listRows($rootId, $depth, $userId);
            } catch (BinaryException $e) {
                /* No redirect here. A member with no binary node (or one who
                 * asks for somebody else's tree) used to be sent back to this
                 * same URL, so the browser gave up with
                 * ERR_TOO_MANY_REDIRECTS. The page renders the reason and the
                 * next step instead, and says nothing about the requested
                 * root — the privacy answer P22G-T33 wanted, without the loop. */
                $unavailable        = $e->reason;
                $unavailableMessage = $e->getMessage();
            }

            /* Admin root selector: company tree plus every member with a node (P22G-T21). */
            $adminRoots = [];
            if ($isAdmin) {
                $stmt = db()->prepare(
                    'SELECT u.id AS id, u.username AS username
                       FROM binary_nodes n
                       JOIN users u ON u.id = n.user_id
                      WHERE u.role = \'member\'
                      ORDER BY n.path, u.username'
                );
                $stmt->execute();
                $adminRoots = $stmt->fetchAll();
            }

            $member = Member::find($userId);

            if ($member === null) {
                View::error(404);
            }

            View::page($isAdmin ? 'admin' : 'member', 'member/genealogy', array_merge(
                $this->genealogyContext($member),
                [
                    'page_title'  => 'Binary Tree',
                    'current_nav' => $isAdmin ? 'admin.genealogy' : 'genealogy',
                    'page_css'    => ['assets/css/membership.css', 'assets/css/binary-tree.css'],
                    'page_js'     => ['assets/vendor/libs/d3/d3.v7.min.js', 'assets/js/app-binary-tree.js', 'assets/js/app-binary-register.js'],
                    'view'        => 'binary',
                    'binary_root' => $payload,
                    'binary_list' => $listRows,
                    'binary_depth'=> $depth,
                    'binary_root_id' => $rootId,
                    'binary_company_root' => $companyRootId,
                    'binary_unavailable' => $unavailable,
                    'binary_unavailable_message' => $unavailableMessage,
                    'admin_roots' => $adminRoots,
                    /* An unplaced member has no tree to poll: the feed would
                     * answer 403 on every tick. */
                    'live'        => $unavailable !== ''
                        ? [
                            'surface'  => Live::binarySurface($userId),
                            'enabled'  => false,
                            'revision' => 0,
                            'etag'     => '',
                            'interval' => 30000,
                        ]
                        : Live::meta(
                            Live::binarySurface($userId),
                            $this->binaryScope($rootId, $depth, $userId)
                        ),
                ]
            ));
            return;
        }

        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        $member = Member::find($userId);

        if ($member === null) {
            View::error(404);
        }

        View::page($isAdmin ? 'admin' : 'member', 'member/genealogy', $this->genealogyContext($member) + [
            'page_title'  => 'Genealogy',
            'current_nav' => $isAdmin ? 'admin.genealogy' : 'genealogy',
            'page_css'    => ['assets/css/membership.css'],
            'page_js'     => ['assets/js/app-table-scroll.js'],
            'live'        => Live::meta(
                Live::genealogySurface($userId),
                $this->genealogyScope($userId)
            ),
        ]);
    }

    /**
     * JSON feed payload for the binary genealogy tree.
     *
     * Privacy: a member may request only their own subtree; an admin any
     * node. ETag + If-None-Match gives 304 for an unchanged subtree.
     *
     * @return array<string, mixed>
     * @throws BinaryException when the viewer is not allowed to see the root
     */
    public function binaryJson(int $userId, bool $isAdmin): array
    {
        session_write_close();

        $rootId  = (int) Request::query('root', $userId);
        $depth   = (int) Request::query('depth', BinaryTreeService::DEFAULT_DEPTH);

        $service = new BinaryTreeService();
        $scope   = $service->etagScope($rootId, $depth, $userId, $isAdmin);
        $etag    = Live::etag('member.binary:' . $rootId, 0, $scope);

        if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
            Live::notModified($etag);
        }

        $payload = $service->subtree($rootId, $depth, $userId, $isAdmin);
        $payload['etag'] = $etag;

        return $payload;
    }

    /**
     * Everything the page renders, built once for the page and its feed.
     * Public so the view tests can assert the real screen data.
     *
     * @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    public function genealogyContext(array $member): array
    {
        $userId = (int) $member['id'];

        $team = $this->sponsors->downline($userId, SponsorService::MAX_LEVELS);

        /* Group the downline by level for the team chain and its detail
         * card. Level 1 is the direct recruits; level 2 their recruits,
         * and so on. */
        $byLevel = [];

        foreach ($team as $row) {
            $byLevel[(int) $row['level']][] = $row;
        }

        ksort($byLevel);

        /* Direct recruits are their own table, so they paginate. */
        $recruitsPager = Paginator::fromTableRequest($this->sponsors->countDirectRecruits($userId));
        $direct        = $this->sponsors->directRecruits($userId, null, $recruitsPager);

        $deeper       = [];
        $levelPagers  = [];

        foreach ($byLevel as $level => $rows) {
            if ($level === 1) {
                continue;
            }

            /* Every level card paginates on its own, so each level gets
             * its own query keys (?level3_page=2&level3_per_page=5). A
             * shared `page` would move all the cards at once and clash
             * with the direct-recruits table. */
            $pager = Paginator::fromTableRequest(
                count($rows),
                'level' . $level . '_page',
                'level' . $level . '_per_page'
            );

            $deeper[$level]      = array_slice($rows, $pager->offset, $pager->perPage);
            $levelPagers[$level] = $pager;
        }

        $displayName = trim((string) ($member['full_name'] ?? '')) !== ''
            ? (string) $member['full_name']
            : (string) $member['username'];

        /* One read for every badge on the page (20F): the reader's own
         * position and each downline member's, keyed by user id. */
        $positions = (new HarvestQueue())->map();
        $position  = $positions[$userId] ?? null;

        return [
            'member'         => $member,
            'display_name'   => $displayName,
            'team_levels'    => $byLevel,
            'direct'         => $direct,
            'recruits_pager' => $recruitsPager,
            'deeper'         => $deeper,
            'level_pagers'   => $levelPagers,
            'team_count'     => count($team),
            'levels_used'    => $byLevel === [] ? 0 : max(array_keys($byLevel)),
            'harvest_positions' => $positions,
            'harvest_position'  => $position,
            /* seq = position − 1 (§12.1): the node the reader holds on the
             * shared Harvest board. Null for a member who is not queued. */
            'harvest_node'      => $position === null ? null : max(0, (int) $position - 1),
            'referral_code'  => (string) ($member['referral_code'] ?? ''),
            'referral_link'  => absolute_url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
            /* Phase 22C: the member's binary node facts for the page header.
             * Null when the member has not been placed in the tree yet. */
            'binary_node'    => BinaryPlacementService::isEnabled()
                ? BinaryNode::forUser($userId)
                : null,
        ];
    }

    /**
     * ETag scope for a genealogy page.
     *
     * The query string keeps the recruits page and size; the user keeps
     * two members' pages apart; the shared team revision makes every
     * ancestor and descendant revalidate on a team change. A deploy that
     * lands before migration 013 treats the revision as zero (Live::meta
     * then switches updates off); it must never fail the page.
     */
    private function genealogyScope(int $userId): string
    {
        return Live::scope($_GET, $userId)
            . '|team:' . Live::revisionOrZero(Live::GENEALOGY_TEAM);
    }

    /** ETag scope for the binary tree page (view + depth + root + viewer). */
    private function binaryScope(int $rootId, int $depth, int $userId): string
    {
        return Live::scope(
            ['view' => 'binary', 'root' => (string) $rootId, 'depth' => (string) $depth],
            $userId
        )
        . '|team:' . Live::revisionOrZero(Live::GENEALOGY_TEAM)
        . '|binary:' . Live::revisionOrZero(Live::binarySurface($userId));
    }

    /**
     * JSON feed for the open genealogy page.
     *
     * The 304 check runs before genealogyContext(), so an unchanged tree
     * costs one revision lookup and no traversal. No fragment renders a
     * POST form, so the session lock is released immediately.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = Live::genealogySurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->genealogyScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            $member = Member::find($userId);

            if ($member === null) {
                Live::jsonError('Member not found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->genealogyContext($member)));
        } catch (Throwable $e) {
            error_log('[ecowealth] genealogy live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh your genealogy. Please try again.', 500);
        }
    }

    /**
     * Render the genealogy payload. Public so tests can assert the
     * fragments without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        $member = $data['member'];
        $pager  = $data['recruits_pager'];

        return [
            'ok'        => true,
            'surface'   => Live::genealogySurface((int) $member['id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'genealogy-header' => View::capture('member/genealogy/_header', [
                    'team_count'       => $data['team_count'],
                    'levels_used'      => $data['levels_used'],
                    'harvest_position' => $data['harvest_position'] ?? null,
                    'harvest_node'     => $data['harvest_node'] ?? null,
                ]),
                'genealogy-team-chain' => View::capture('member/genealogy/_team_chain', [
                    'member'       => $member,
                    'team_levels'  => $data['team_levels'],
                    'display_name' => $data['display_name'],
                ]),
                'genealogy-recruits-count' => View::capture('member/genealogy/_recruits_count', [
                    'recruits_pager' => $pager,
                ]),
                'genealogy-recruits-rows' => View::capture('member/genealogy/_recruits_rows', [
                    'direct'            => $data['direct'],
                    'harvest_positions' => $data['harvest_positions'] ?? [],
                ]),
                'genealogy-recruits-pagination' => View::capture('member/genealogy/_recruits_pagination', [
                    'recruits_pager' => $pager,
                ]),
                'genealogy-team' => View::capture('member/genealogy/_team', [
                    'deeper'            => $data['deeper'],
                    'level_pagers'      => $data['level_pagers'],
                    'harvest_positions' => $data['harvest_positions'] ?? [],
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalGenealogyUrl($pager),
            ],
        ];
    }

    /** The genealogy URL with an out-of-range recruits page clamped. */
    private function canonicalGenealogyUrl(Paginator $pager): string
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

        return url('genealogy' . ($qs !== '' ? '?' . $qs : ''));
    }
}
