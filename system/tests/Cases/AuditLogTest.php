<?php
declare(strict_types=1);

/* ==============================================================
 *  AuditLogTest — the append-only audit trail and its admin desk
 *  (plan §4.2, §7 Phase 15; P15-T2, P15-T7, P15-T12)
 * ============================================================== */

$renderAudit = static function (array $data = []): string {
    $filters = $data['filters'] ?? AuditLog::filterDefaults();
    $pager   = $data['pager'] ?? new Paginator(0);

    ob_start();

    try {
        View::page('admin', 'admin/audit/index', array_merge([
            'page_title'  => 'Audit log',
            'current_nav' => 'admin.audit',
            'page_css'    => ['assets/css/reports.css'],
            'stats'       => $data['stats'] ?? AuditLog::stats(),
            'filters'     => $filters,
            'pager'       => $pager,
            'entries'     => $data['entries'] ?? [],
            'actions'     => $data['actions'] ?? [],
            'subjects'    => $data['subjects'] ?? [],
            'audit_url'   => url('admin/audit'),
            'has_filters' => array_filter($filters, static fn (string $v): bool => $v !== '') !== [],
        ], $data));
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

return [
    'P15 an audit entry records actor, action, subject, meta and ip' => function (): void {
        $admin = test_user('audit_admin', 'admin');

        $id = AuditLog::record([
            'action'       => 'settings.update',
            'actor_id'     => (int) $admin['id'],
            'actor_role'   => 'admin',
            'subject_type' => 'settings',
            'meta'         => ['section' => 'shop', 'changes' => ['shop_enabled' => ['from' => '1', 'to' => '0']]],
            'ip'           => '203.0.113.9',
        ]);

        $stmt = db()->prepare('SELECT * FROM audit_log WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        assert_same('settings.update', $row['action']);
        assert_same((int) $admin['id'], (int) $row['actor_id']);
        assert_same('admin', $row['actor_role']);
        assert_same('settings', $row['subject_type']);
        assert_same('203.0.113.9', $row['ip']);

        $meta = AuditLog::meta((string) $row['meta_json']);
        assert_same('shop', $meta['section']);
        assert_same('0', $meta['changes']['shop_enabled']['to']);

        /* The member's own trail reads the same row. */
        $entries = AuditLog::forSubject('settings', 0);
        assert_same([], $entries, 'null subject id never matches a real row');

        AuditLog::record([
            'action'       => 'member.status',
            'subject_type' => 'member',
            'subject_id'   => 42,
            'meta'         => ['username' => 'juan', 'amount' => 12.5],
        ]);

        $memberTrail = AuditLog::forSubject('member', 42);
        assert_count(1, $memberTrail);
        assert_same('member.status', $memberTrail[0]['action']);
    },

    'P15 meta decoding never throws on broken JSON' => function (): void {
        assert_same([], AuditLog::meta(null));
        assert_same([], AuditLog::meta(''));
        assert_same([], AuditLog::meta('{not json'));
        assert_same(['a' => 1], AuditLog::meta('{"a":1}'));
    },

    'P15 the audit list filters by action, subject, text and date' => function (): void {
        $admin = test_user('audit_filter_admin', 'admin');

        foreach ([
            ['action' => 'wallet.topup', 'subject_type' => 'member'],
            ['action' => 'settings.update', 'subject_type' => 'settings'],
            ['action' => 'report.export', 'subject_type' => 'report'],
        ] as $entry) {
            AuditLog::record($entry + [
                'actor_id'   => (int) $admin['id'],
                'actor_role' => 'admin',
                'meta'       => ['username' => 'juan'],
            ]);
        }

        $all = AuditLog::normaliseFilters([]);
        assert_same('', $all['action']);
        assert_true(AuditLog::countAdmin($all) >= 3);

        $byAction = AuditLog::normaliseFilters(['action' => 'wallet.topup']);
        assert_same(1, AuditLog::countAdmin($byAction));

        $bySubject = AuditLog::normaliseFilters(['subject_type' => 'settings']);
        assert_same(1, AuditLog::countAdmin($bySubject));

        $byText = AuditLog::normaliseFilters(['q' => 'report.export']);
        assert_same(1, AuditLog::countAdmin($byText));

        $byActor = AuditLog::normaliseFilters(['q' => 'audit_filter_admin']);
        assert_same(3, AuditLog::countAdmin($byActor), 'search also matches the actor username');

        $today = AuditLog::normaliseFilters(['from' => date('Y-m-d'), 'to' => date('Y-m-d')]);
        assert_true(AuditLog::countAdmin($today) >= 3);

        $yesterday = AuditLog::normaliseFilters(['to' => date('Y-m-d', strtotime('-1 day'))]);
        assert_same(0, AuditLog::countAdmin($yesterday));

        /* A reversed range is swapped, not rejected. */
        $reversed = AuditLog::normaliseFilters([
            'from' => date('Y-m-d'),
            'to'   => date('Y-m-d', strtotime('-5 days')),
        ]);
        assert_same(date('Y-m-d', strtotime('-5 days')), $reversed['from']);
        assert_same(date('Y-m-d'), $reversed['to']);

        $rows = AuditLog::adminList($byAction, new Paginator(1, 10, 1));
        assert_count(1, $rows);
        assert_same('wallet.topup', $rows[0]['action']);
        assert_same('audit_filter_admin', $rows[0]['actor_username']);
    },

    'P15 the audit vocabulary and counters feed the filters' => function (): void {
        AuditLog::record(['action' => 'probe.alpha', 'subject_type' => 'probe']);
        AuditLog::record(['action' => 'probe.beta', 'subject_type' => 'probe']);

        $vocabulary = AuditLog::vocabulary();
        assert_true(in_array('probe.alpha', $vocabulary['actions'], true));
        assert_true(in_array('probe', $vocabulary['subjects'], true));

        $stats = AuditLog::stats();
        assert_true($stats['total'] >= 2);
        assert_true($stats['today'] >= 2);
        assert_true($stats['actors'] >= 0);
        assert_true($stats['settings'] >= 0);
    },

    'P15 the audit trail has no update or delete path' => function (): void {
        $reflection = new ReflectionClass(AuditLog::class);

        foreach (['update', 'delete', 'remove', 'edit', 'purge'] as $forbidden) {
            assert_false($reflection->hasMethod($forbidden), 'AuditLog::' . $forbidden . '() must not exist');
        }

        assert_true($reflection->hasMethod('record'));
        assert_true($reflection->hasMethod('adminList'));
    },

    'P15 the audit desk renders entries, filters and pagination' => function () use ($renderAudit): void {
        test_signed_in_as(test_user('audit_view_admin', 'admin'));

        $admin = test_user('audit_view_admin', 'admin');

        AuditLog::record([
            'action'       => 'settings.update',
            'actor_id'     => (int) $admin['id'],
            'actor_role'   => 'admin',
            'subject_type' => 'settings',
            'meta'         => ['section' => 'general', 'changes' => ['site_name' => ['from' => 'A', 'to' => '<b>B</b>']]],
        ]);
        AuditLog::record([
            'action'       => 'report.export',
            'actor_id'     => (int) $admin['id'],
            'actor_role'   => 'admin',
            'subject_type' => 'report',
            'meta'         => ['type' => 'sales', 'rows' => 30, 'from' => '2026-01-01', 'to' => '2026-01-31'],
        ]);

        $filters = AuditLog::normaliseFilters(['q' => '']);
        $entries = AuditLog::adminList($filters, new Paginator(2, 10, 1));

        $html = $renderAudit([
            'stats'    => AuditLog::stats(),
            'entries'  => $entries,
            'actions'  => AuditLog::vocabulary()['actions'],
            'subjects' => AuditLog::vocabulary()['subjects'],
            'pager'    => new Paginator(2, 10, 1),
        ]);

        assert_contains('Audit log', $html);
        assert_contains('settings.update', $html);
        assert_contains('report.export', $html);
        assert_contains('general', $html);
        assert_contains('sales', $html);
        assert_contains('30 rows', $html);
        assert_contains('@audit_view_admin', $html);
        assert_contains('Entries', $html);
        assert_contains('name="action"', $html);
        assert_contains('name="subject_type"', $html);
        assert_contains('name="from"', $html);

        /* The before/after values are not printed into the summary; only
         * the key list is, and every value is escaped anyway. */
        assert_not_contains('<b>B</b>', $html);
    },

    'P15 the audit desk shows an empty state' => function () use ($renderAudit): void {
        test_signed_in_as(test_user('audit_empty_admin', 'admin'));

        $filters = AuditLog::normaliseFilters(['q' => 'nothing-matches-this']);
        $html    = $renderAudit([
            'entries'  => [],
            'actions'  => [],
            'subjects' => [],
            'pager'    => new Paginator(0),
            'filters'  => $filters,
            'has_filters' => true,
        ]);

        assert_contains('No audit entries match these filters.', $html);
        assert_contains('Clear', $html);
    },

    'P15 only an admin route can reach the audit desk' => function (): void {
        $router = new Router(require dirname(__DIR__, 2) . '/app/routes.php');
        $match  = $router->match('GET', '/admin/audit');

        assert_not_null($match);
        assert_same('admin', $match['route']['guard']);
        assert_same(AdminAuditController::class, $match['route']['handler'][0]);
        assert_same('index', $match['route']['handler'][1]);

        $export = $router->match('GET', '/admin/reports/export');
        assert_not_null($export);
        assert_same('admin', $export['route']['guard']);
    },
];
