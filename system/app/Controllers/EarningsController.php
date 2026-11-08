<?php
declare(strict_types=1);

/* ==============================================================
 *  EarningsController — the member's commission statement
 *  (plan §7 Phase 10; reference MemberController::earnings and
 *  views/member/earnings.php)
 *
 *  Route (Appendix A): GET /earnings   guard `member`
 *
 *  The page answers three questions at a glance:
 *    • how much has been credited (all time and this month),
 *    • which leg paid it (direct vs. the indirect ladder), and
 *    • which activations produced it — one row per award.
 *
 *  Members only ever see their own rows (`WHERE user_id = ?`), the
 *  same ownership rule as orders and the wallet statement.
 *
 *  The page is one per-member live surface (`member.earnings:{id}`).
 *  The summary cards, the skipped-award notice, the card heading, the
 *  rows and the pagination are regions; the filter form stays outside
 *  them, so a background refresh never discards an unsubmitted filter.
 * ============================================================== */

final class EarningsController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        $userId  = (int) $user['id'];
        $isAdmin = is_admin($user);

        /* The same URL doubles as the JSON feed. The surface is per member,
         * so one member's award never refreshes another member's page. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        if (Member::find($userId) === null) {
            View::error(404);
        }

        View::page($isAdmin ? 'admin' : 'member', 'member/earnings', $this->earningsContext($userId) + [
            'page_title'  => 'Earnings',
            'current_nav' => $isAdmin ? 'admin.membership' : 'earnings',
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
            'live'        => Live::meta(
                Live::earningsSurface($userId),
                $this->earningsScope($userId)
            ),
        ]);
    }

    /**
     * Everything the statement renders, built once for the page and its
     * feed. Public so the view tests can assert the real screen data.
     *
     * @return array<string, mixed>
     */
    public function earningsContext(int $userId): array
    {
        $filters = Commission::normaliseFilters([
            'type'   => Request::string('type'),
            'level'  => Request::string('level'),
            'status' => Request::string('status'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
            'q'      => Request::string('q'),
        ]);

        $pager = Paginator::fromTableRequest(Commission::countForUser($userId, $filters));
        $rows  = Commission::pageForUser($userId, $filters, $pager);

        return [
            'user_id'       => $userId,
            'totals'        => Commission::totalsForUser($userId),
            'filters'       => $filters,
            'pager'         => $pager,
            'commissions'   => $rows,
            'wallet_url'    => url('wallet'),
            'genealogy_url' => url('genealogy'),
            'statement_url' => url('earnings'),
        ];
    }

    /**
     * ETag scope for the statement.
     *
     * The "last award" label is relative and `this_month` counts from
     * CURDATE(), so the hour is part of the scope: a tab left open
     * revalidates without a write instead of showing a stale age.
     */
    private function earningsScope(int $userId): string
    {
        return Live::scope($_GET, $userId) . '|hour:' . date('Y-m-d H');
    }

    /**
     * JSON feed for the open earnings statement.
     *
     * The 304 check runs before earningsContext(), so an unchanged page
     * costs one revision lookup and no list/aggregate queries. No fragment
     * renders a POST form, so the session lock is released immediately.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = Live::earningsSurface($userId);

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, $this->earningsScope($userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            if (Member::find($userId) === null) {
                Live::jsonError('Member not found.', 404);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->earningsContext($userId)));
        } catch (Throwable $e) {
            error_log('[ecowealth] member earnings live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh your earnings. Please try again.', 500);
        }
    }

    /**
     * Render the earnings payload. Public so tests can assert the
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
            'surface'   => Live::earningsSurface((int) $data['user_id']),
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'earnings-header' => View::capture('member/earnings/_header', [
                    'pager' => $pager,
                ]),
                'earnings-stats' => View::capture('member/earnings/_stats', [
                    'totals' => $data['totals'],
                ]),
                'earnings-skipped' => View::capture('member/earnings/_skipped', [
                    'totals' => $data['totals'],
                ]),
                'earnings-rows' => View::capture('member/earnings/_rows', [
                    'commissions' => $data['commissions'],
                    'filters'     => $data['filters'],
                    'wallet_url'  => $data['wallet_url'],
                ]),
                'earnings-pagination' => View::capture('member/earnings/_pagination', [
                    'pager' => $pager,
                ]),
            ],
            'meta'      => [
                'page'      => $pager->page,
                'pages'     => $pager->pages,
                'total'     => $pager->total,
                'per_page'  => $pager->perPage,
                'canonical' => $this->canonicalEarningsUrl($pager),
            ],
        ];
    }

    /** The earnings URL with an out-of-range page clamped to a real page. */
    private function canonicalEarningsUrl(Paginator $pager): string
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

        return url('earnings' . ($qs !== '' ? '?' . $qs : ''));
    }
}
