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
 *  Every genealogy page also includes the shared `member.genealogy`
 *  revision in its ETag: a team change moves the ancestors' counters and
 *  lists and the descendants' upline chain, so one shared bump keeps the
 *  whole tree correct.
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

        /* The same URL doubles as the JSON feed. The page key is per user;
         * the shared team revision is part of the ETag scope. */
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

        $deeper = [];

        foreach ($byLevel as $level => $rows) {
            if ($level === 1) {
                continue;
            }

            $deeper[$level] = $rows;
        }

        $displayName = trim((string) ($member['full_name'] ?? '')) !== ''
            ? (string) $member['full_name']
            : (string) $member['username'];

        return [
            'member'         => $member,
            'display_name'   => $displayName,
            'team_levels'    => $byLevel,
            'direct'         => $direct,
            'recruits_pager' => $recruitsPager,
            'deeper'         => $deeper,
            'team_count'     => count($team),
            'levels_used'    => $byLevel === [] ? 0 : max(array_keys($byLevel)),
            'referral_code'  => (string) ($member['referral_code'] ?? ''),
            'referral_link'  => absolute_url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
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
                    'team_count'  => $data['team_count'],
                    'levels_used' => $data['levels_used'],
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
                    'direct' => $data['direct'],
                ]),
                'genealogy-recruits-pagination' => View::capture('member/genealogy/_recruits_pagination', [
                    'recruits_pager' => $pager,
                ]),
                'genealogy-team' => View::capture('member/genealogy/_team', [
                    'deeper' => $data['deeper'],
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
