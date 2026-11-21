<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminAuditController — the admin audit desk
 *  (plan §7 Phase 15: "audit log: admin actions, settings changes,
 *  status transitions, impersonation exits"; Appendix A extension)
 *
 *  Route: GET /admin/audit   guard `admin`
 *
 *  One filterable, paginated list over `audit_log`, the append-only
 *  table every audited write already records into:
 *
 *    settings.update      admin settings save (per-key before/after)
 *    wallet.topup         admin wallet credit
 *    *.export             commission / report CSV downloads
 *    slogin.*             super-login starts, refusals and exits
 *    member.*, order.* …  admin lifecycle actions
 *
 *  Order transitions and impersonation sessions keep their own,
 *  richer trails (`shop_order_events`, `impersonation_log`); this page
 *  links to both instead of duplicating them.
 *
 *  The list is deliberately not a live surface: an audit trail is read
 *  like a document, and a background refresh that reorders rows while
 *  an operator is reading is worse than a manual reload.
 * ============================================================== */

final class AdminAuditController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $filters = AuditLog::normaliseFilters([
            'q'            => Request::string('q'),
            'action'       => Request::string('action'),
            'subject_type' => Request::string('subject_type'),
            'from'         => Request::string('from'),
            'to'           => Request::string('to'),
        ]);

        $pager = Paginator::fromTableRequest(AuditLog::countAdmin($filters));
        $vocab = AuditLog::vocabulary();

        View::page('admin', 'admin/audit/index', [
            'page_title'  => 'Audit log',
            'current_nav' => 'admin.audit',
            'page_css'    => ['assets/css/reports.css'],
            'stats'       => AuditLog::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'entries'     => AuditLog::adminList($filters, $pager),
            'actions'     => $vocab['actions'],
            'subjects'    => $vocab['subjects'],
            'audit_url'   => url('admin/audit'),
            'has_filters' => array_filter($filters, static fn (string $value): bool => $value !== '') !== [],
        ]);
    }
}
