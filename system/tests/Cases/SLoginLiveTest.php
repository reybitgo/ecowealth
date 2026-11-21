<?php
declare(strict_types=1);

/* ==============================================================
 *  SLoginLiveTest — the live contract of the S-Login console
 *  (plan §7 Phase 14, docs/live-updates.md)
 *
 *  One surface, `admin.slogin`, with two regions: the "Active sessions"
 *  card and the "Recent sessions" card. These cases pin that
 *
 *    · every impersonation write bumps it — open, exit, expiry and the
 *      overdue sweep — and a write that moves no row does not;
 *    · the feed returns both region wrappers, with the counts inside them
 *      so the badge can never disagree with the rows;
 *    · a switched-to tab sees the sessions that opened while it was
 *      hidden, which is the whole point of the surface;
 *    · the member picker stays outside every region, so a poll never
 *      discards a typed username.
 * ============================================================== */

$makeSuper = static fn (): array => test_user('sll_super_' . bin2hex(random_bytes(4)), 'superadmin');
$makeMember = static fn (): array => test_user('sll_member_' . bin2hex(random_bytes(4)));

/** Open an S-Login row without binding a session (the console only reads the log). */
$open = static function (array $super, array $member): array {
    return (new ImpersonationService())->start(
        (string) $member['username'],
        $super,
        '203.0.113.7',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/122.0'
    );
};

return [
    'the console surface is admin.slogin and every impersonation write bumps it' => function () use ($makeSuper, $makeMember, $open): void {
        $super  = $makeSuper();
        $member = $makeMember();

        assert_same('admin.slogin', Live::SLOGIN);

        $before = Live::revision(Live::SLOGIN);
        Live::touchSLogin();
        assert_same($before + 1, Live::revision(Live::SLOGIN), 'the helper bumps the surface');

        /* Opening records the row: the active list and both counts move. */
        $issued = $open($super, $member);
        assert_same($before + 2, Live::revision(Live::SLOGIN), 'a new session bumps the console');

        /* Exit is a real transition: it bumps once. */
        (new ImpersonationService())->exit([
            'imp_session' => true,
            'imp_log_id'  => (int) $issued['log_id'],
        ]);

        assert_same($before + 3, Live::revision(Live::SLOGIN), 'exit bumps the console');

        /* A second terminal status moves no row, so an open tab keeps its payload. */
        assert_false(Impersonation::mark((int) $issued['log_id'], 'expired'));
        assert_same($before + 3, Live::revision(Live::SLOGIN), 'a terminal row never bumps twice');
    },

    'the overdue sweep bumps the console only when it expires a row' => function () use ($makeSuper, $makeMember, $open): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member);

        $before = Live::revision(Live::SLOGIN);

        assert_same(0, Impersonation::expireOverdue(), 'nothing is overdue yet');
        assert_same($before, Live::revision(Live::SLOGIN), 'a sweep that moves nothing never bumps');

        db()->prepare('UPDATE impersonation_log SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')
            ->execute([(int) $issued['log_id']]);

        assert_same(1, Impersonation::expireOverdue(), 'the row is swept');
        assert_same($before + 1, Live::revision(Live::SLOGIN), 'the expiry bumps the console');

        assert_same(0, Impersonation::expireOverdue(), 'the sweep is idempotent');
        assert_same($before + 1, Live::revision(Live::SLOGIN));
        assert_same('expired', (string) Impersonation::find((int) $issued['log_id'])['status']);
    },

    'the console feed renders the active and recent cards' => function () use ($makeSuper, $makeMember, $open): void {
        $super   = $makeSuper();
        $member  = $makeMember();
        $second  = $makeMember();

        $issued = $open($super, $member);
        $open($super, $second);
        (new ImpersonationService())->exit([
            'imp_session' => true,
            'imp_log_id'  => (int) $issued['log_id'],
        ]);

        test_signed_in_as($super);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/slogin';

        $controller = new SLoginController();
        $payload    = $controller->feedPayload(
            5,
            '"admin.slogin:5:abc"',
            $controller->consoleContext()
        );

        assert_true($payload['ok']);
        assert_same('admin.slogin', $payload['surface']);
        assert_same(5, $payload['revision']);
        assert_same(
            ['slogin-active', 'slogin-recent'],
            array_keys($payload['fragments'])
        );

        $active = $payload['fragments']['slogin-active'];
        $recent = $payload['fragments']['slogin-recent'];

        /* Both fragments are the cards themselves, badge included. */
        assert_contains('data-live-region="slogin-active"', $active);
        assert_contains('Active sessions', $active);
        assert_contains('@' . $second['username'], $active, 'the second open session is listed');
        assert_not_contains('@' . $member['username'], $active, 'the exited session left the active list');
        assert_contains('<span class="badge rounded-pill bg-label-success">1</span>', $active);

        assert_contains('data-live-region="slogin-recent"', $recent);
        assert_contains('Recent sessions', $recent);
        assert_contains('@' . $member['username'], $recent);
        assert_contains('@' . $second['username'], $recent);
        assert_contains('Logged out', $recent);
        assert_contains('<span class="badge rounded-pill bg-label-secondary">2 total</span>', $recent);
        assert_contains('Open the full audit trail', $recent);

        /* The token is a bearer credential: it never reaches a fragment. */
        assert_not_contains((string) $issued['nonce'], $active . $recent);

        assert_contains('slogin', (string) $payload['meta']['canonical']);
    },

    'the console feed renders both empty states' => function () use ($makeSuper): void {
        test_signed_in_as($makeSuper());

        $_GET = [];

        $controller = new SLoginController();
        $payload    = $controller->feedPayload(0, '"admin.slogin:0:abc"', $controller->consoleContext());

        assert_same(
            ['slogin-active', 'slogin-recent'],
            array_keys($payload['fragments'])
        );

        assert_contains('data-live-region="slogin-active"', $payload['fragments']['slogin-active']);
        assert_contains('No member session is open right now.', $payload['fragments']['slogin-active']);
        assert_contains(
            '<span class="badge rounded-pill bg-label-success">0</span>',
            $payload['fragments']['slogin-active']
        );

        assert_contains('data-live-region="slogin-recent"', $payload['fragments']['slogin-recent']);
        assert_contains('No super-login has been used yet.', $payload['fragments']['slogin-recent']);
        assert_contains(
            '<span class="badge rounded-pill bg-label-secondary">0 total</span>',
            $payload['fragments']['slogin-recent']
        );
    },

    'the console page declares the surface and keeps the form outside it' => function () use ($makeSuper, $makeMember, $open): void {
        $super  = $makeSuper();
        $member = $makeMember();

        test_signed_in_as($super);
        $open($super, $member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/slogin';

        ob_start();

        try {
            (new SLoginController())->index();
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.slogin"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-etag="', $html);
        assert_contains('data-live-status', $html, 'the page has a status placeholder');

        assert_contains('data-live-region="slogin-active"', $html);
        assert_contains('data-live-region="slogin-recent"', $html);
        assert_same(1, substr_count($html, 'data-live-region="slogin-active"'));
        assert_same(1, substr_count($html, 'data-live-region="slogin-recent"'));

        /* The picker owns a text input: it must stay outside every region. */
        $formAt   = strpos($html, 'data-slogin-form');
        $regionAt = strpos($html, 'data-live-region=');

        assert_true($formAt !== false, 'the console form is rendered');
        assert_true($regionAt !== false, 'a live region is rendered');
        assert_true($formAt < $regionAt, 'the member picker comes before (outside) the regions');
    },

    'the console ETag follows the revision and the signed-in superadmin' => function () use ($makeSuper, $makeMember, $open): void {
        $super  = $makeSuper();
        $other  = $makeSuper();
        $member = $makeMember();

        test_signed_in_as($super);

        $_GET = [];

        $controller = new SLoginController();
        $scope      = $controller->consoleScope((int) $super['id']);

        assert_contains(Live::scope($_GET, (int) $super['id']), $scope, 'the scope carries the user');
        assert_contains('|hour:' . date('Y-m-d H'), $scope, 'relative "started" labels revalidate hourly');
        assert_not_same($scope, $controller->consoleScope((int) $other['id']), 'two superadmins never share an ETag');

        $etagBefore = Live::etag(Live::SLOGIN, Live::revision(Live::SLOGIN), $scope);

        $open($super, $member);

        $etagAfter = Live::etag(Live::SLOGIN, Live::revision(Live::SLOGIN), $scope);

        assert_not_same($etagBefore, $etagAfter, 'a new session changes the ETag');
        assert_false(Live::matchesIfNoneMatch($etagBefore, $etagAfter), 'the old ETag is a 200, not a 304');
        assert_true(Live::matchesIfNoneMatch($etagAfter, $etagAfter), 'the current ETag answers 304');
    },
];
