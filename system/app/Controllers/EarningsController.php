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

        View::page($isAdmin ? 'admin' : 'member', 'member/earnings', [
            'page_title'    => 'Earnings',
            'current_nav'   => $isAdmin ? 'admin.membership' : 'earnings',
            'page_css'      => ['assets/css/commissions.css'],
            'page_js'       => ['assets/js/app-commissions.js'],
            'totals'        => Commission::totalsForUser($userId),
            'filters'       => $filters,
            'pager'         => $pager,
            'commissions'   => $rows,
            'wallet_url'    => url('wallet'),
            'genealogy_url' => url('genealogy'),
            'statement_url' => url('earnings'),
        ]);
    }
}
