<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminDashboardController — back-office landing page
 *  (reference: admin). Phase 1 renders live account counts and the
 *  build status; the commerce KPIs arrive with their phases.
 * ============================================================== */

final class AdminDashboardController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $db = db();

        $stats = [
            'members'        => 0,
            'members_active' => 0,
            'admins'         => 0,
            'settings'       => 0,
        ];

        $stats['members'] = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
        $stats['members_active'] = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND is_active = 1")->fetchColumn();
        $stats['admins'] = (int) $db->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','superadmin')")->fetchColumn();

        try {
            $stats['settings'] = (int) $db->query('SELECT COUNT(*) FROM settings')->fetchColumn();
        } catch (PDOException $e) {
            error_log('[ecowealth] admin dashboard settings count failed: ' . $e->getMessage());
        }

        $recent = $db->query(
            'SELECT id, username, email, role, is_active, created_at, last_login_at
             FROM users
             ORDER BY created_at DESC, id DESC
             LIMIT 8'
        )->fetchAll();

        View::page('admin', 'admin/dashboard', [
            'page_title'  => 'Admin dashboard',
            'current_nav' => 'admin.dashboard',
            'stats'       => $stats,
            'recent'      => $recent,
        ]);
    }
}
