<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminCommissionController — the company-wide commission ledger
 *  (plan §7 Phase 10, Appendix A extension)
 *
 *  Route: GET /admin/commissions   guard `admin`
 *
 *  Every award the plan has ever produced, credited or skipped, with
 *  filters by recipient/source/package (q), type, level, status and
 *  date. The header carries the numbers finance needs: total
 *  credited, this month, awards, and how many were skipped.
 *
 *  The ledger is read-only by design: an award is never edited. A
 *  correction would be a new `adjustment` wallet movement plus a
 *  `voided` row (Phase 15), never an UPDATE on money history.
 * ============================================================== */

final class AdminCommissionController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $filters = Commission::normaliseFilters([
            'type'   => Request::string('type'),
            'level'  => Request::string('level'),
            'status' => Request::string('status'),
            'from'   => Request::string('from'),
            'to'     => Request::string('to'),
            'q'      => Request::string('q'),
        ]);

        $pager = Paginator::fromTableRequest(Commission::countAdmin($filters));
        $rows  = Commission::adminList($filters, $pager);

        View::page('admin', 'admin/commissions/index', [
            'page_title'  => 'Commissions',
            'current_nav' => 'admin.commissions',
            'page_css'    => ['assets/css/commissions.css'],
            'page_js'     => ['assets/js/app-commissions.js'],
            'stats'       => Commission::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'commissions' => $rows,
            'ledger_url'  => url('admin/commissions'),
            'wallet_url'  => url('admin/wallets'),
        ]);
    }
}
