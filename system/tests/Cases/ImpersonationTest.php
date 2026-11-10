<?php
declare(strict_types=1);

/* ==============================================================
 *  ImpersonationTest — super-login (plan §4.4, §7 Phase 14)
 *
 *  Covers the service, the audit row, the URL-token transport rules
 *  (hash only, TTL, IP/UA binding, terminal statuses), the per-target
 *  rate limit and the URL helper that keeps the token on every page.
 *
 *  The HTTP round trip (cookie session ↔ URL-token session, redirects,
 *  the banner and the console) is exercised by tests/smoke.ps1; this
 *  file pins the decisions that must not depend on a browser.
 * ============================================================== */

$makeSuper = static fn (): array => test_user('imp_super_' . bin2hex(random_bytes(4)), 'superadmin');

$makeMember = static function (string $status = 'active', bool $isActive = true): array {
    $member = test_user('imp_member_' . bin2hex(random_bytes(4)));

    db()->prepare('UPDATE users SET member_status = ?, is_active = ? WHERE id = ?')
        ->execute([$status, $isActive ? 1 : 0, (int) $member['id']]);

    return Member::find((int) $member['id']);
};

/** Open and bind a session in the current PHP session (the transport is smoke-tested). */
$open = static function (array $super, array $member, string $ip = '203.0.113.7', string $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/122.0'): array {
    /* Make Request::ip()/userAgent() agree with the session we are opening,
     * so a check() with its real defaults validates instead of failing the
     * IP binding against the CLI's empty REMOTE_ADDR. */
    $_SERVER['REMOTE_ADDR']     = $ip;
    $_SERVER['HTTP_USER_AGENT'] = $ua;

    $issued = (new ImpersonationService())->start((string) $member['username'], $super, $ip, $ua);
    ImpersonationService::bind($issued);
    auth_cache_reset();

    return $issued;
};

$sessionKeys = static function (): void {
    foreach ([
        'user_id', 'logged_in_at', 'last_activity', 'auth_via',
        'imp_session', 'imp_token', 'imp_log_id', 'imp_created_by',
        'imp_created_by_username', 'imp_target_username', 'imp_expires',
        'imp_ip', 'imp_ua', '_csrf',
    ] as $key) {
        unset($_SESSION[$key]);
    }

    auth_cache_reset();
};

return [
    'P14-T1 start records a hashed session and binds the member identity' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();

        test_signed_in_as($super);

        $issued = (new ImpersonationService())->start((string) $member['username'], $super, '203.0.113.7', 'Chrome-test');

        assert_same(64, strlen((string) $issued['nonce']));
        assert_same(hash('sha256', (string) $issued['nonce']), (string) Impersonation::find((int) $issued['log_id'])['nonce_hash']);

        /* The service alone never touches the caller's cookie session. */
        assert_same((int) $super['id'], (int) $_SESSION['user_id'], 'start() must not switch the session');
        assert_false(auth_is_imp());

        ImpersonationService::bind($issued);

        assert_true(auth_is_imp());
        assert_same((int) $member['id'], (int) $_SESSION['user_id']);
        assert_same((int) $member['id'], (int) auth_user()['id']);
        assert_same((string) $issued['nonce'], imp_token());

        $log = Impersonation::find((int) $issued['log_id']);
        assert_same('active', $log['status']);
        assert_same((string) $super['username'], $log['superadmin_name']);
        assert_same((string) $member['username'], $log['target_username']);
        assert_same('203.0.113.7', $log['ip']);
        assert_same('Chrome-test', $log['user_agent']);

        $sessionKeys();
    },

    'P14-T2 every app URL keeps the token; assets and shared links do not' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member);

        $token = (string) $issued['nonce'];

        assert_contains('imp=' . $token, url('shop'), 'a plain link keeps the token');
        assert_contains('imp=' . $token, url('shop?q=coffee'), 'a link with a query keeps the token');
        assert_contains('imp=' . $token, url('orders/7'), 'deep links keep the token');
        assert_same(1, substr_count(url('shop?q=x'), 'imp='), 'the token is never duplicated');

        assert_not_contains('imp=', url('assets/css/app.css'), 'static assets never carry the token');
        assert_not_contains('imp=', asset_url('assets/css/app.css'), 'asset_url() never carries the token');
        assert_not_contains('imp=', url('uploads/products/a.png'), 'uploaded media never carries the token');
        assert_not_contains('imp=', absolute_url('register?ref=JUAN0001'), 'a shareable referral link never carries the token');
        assert_contains('register?ref=JUAN0001', absolute_url('register?ref=JUAN0001'));

        $sessionKeys();
    },

    'P14-T3 exit marks the log logged_out and the token is refused afterwards' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member);

        $service = new ImpersonationService();

        $log = $service->exit();
        assert_same('logged_out', Impersonation::find((int) $issued['log_id'])['status']);

        assert_same('logged_out', $service->check()['reason'], 'the token is dead');
        assert_false($service->check()['ok']);

        /* A second end (or a validation-driven expiry) cannot overwrite
         * the deliberate status: the first terminal status wins. */
        assert_false(Impersonation::mark((int) $issued['log_id'], 'expired'));
        assert_same('logged_out', Impersonation::find((int) $issued['log_id'])['status']);

        assert_same((string) $log['target_username'], (string) $member['username']);

        $sessionKeys();
    },

    'P14-T4 a session past its TTL is refused and the audit row can be expired' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member);

        $service = new ImpersonationService();

        assert_true($service->check()['ok']);

        /* The row is the authority: its expires_at decides even when the
         * session copy still looks fresh. */
        db()->prepare('UPDATE impersonation_log SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')
            ->execute([(int) $issued['log_id']]);

        $check = $service->check();
        assert_false($check['ok']);
        assert_same('expired', $check['reason']);

        db()->prepare('UPDATE impersonation_log SET expires_at = DATE_ADD(NOW(), INTERVAL 8 HOUR) WHERE id = ?')
            ->execute([(int) $issued['log_id']]);

        /* The session's own deadline refuses first too. */
        $_SESSION['imp_expires'] = time() - 1;

        $check = $service->check();
        assert_same('expired', $check['reason']);

        /* sweep: an overdue active row is flipped without a request. */
        db()->prepare('UPDATE impersonation_log SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')
            ->execute([(int) $issued['log_id']]);

        assert_true(Impersonation::expireOverdue() >= 1);
        assert_same('expired', Impersonation::find((int) $issued['log_id'])['status']);

        $sessionKeys();
    },

    'P14-T5 an IP or user-agent change ends the session' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member, '203.0.113.7', 'UA-one');

        $service = new ImpersonationService();
        $ua      = (string) $issued['ua'];

        $ipCheck = $service->check(null, '198.51.100.9', $ua);
        assert_same('ip', $ipCheck['reason']);

        $uaCheck = $service->check(null, '203.0.113.7', 'UA-two');
        assert_same('ua', $uaCheck['reason']);

        /* Tampering with the session copies is refused as well, even if
         * the log row still matches. */
        $_SESSION['imp_ip'] = '198.51.100.9';
        assert_same('ip', $service->check(null, '203.0.113.7', $ua)['reason']);

        $_SESSION['imp_ip'] = '203.0.113.7';
        $_SESSION['imp_ua'] = 'UA-two';
        assert_same('ua', $service->check(null, '203.0.113.7', $ua)['reason']);

        $sessionKeys();
    },

    'P14-T6 only a superadmin can open a member session' => function () use ($makeMember, $sessionKeys): void {
        $member = $makeMember();
        $admin  = test_user('imp_admin_' . bin2hex(random_bytes(4)), 'admin');

        $service = new ImpersonationService();

        $e = assert_throws(
            static fn () => $service->start((string) $member['username'], $admin, '203.0.113.7', 'UA'),
            ImpersonationException::class
        );
        assert_same('admin', $e->reason);

        test_signed_in_as(null);
        $e = assert_throws(
            static fn () => $service->start((string) $member['username'], null, '203.0.113.7', 'UA'),
            ImpersonationException::class
        );
        assert_same('admin', $e->reason);

        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM impersonation_log')->fetchColumn());

        $sessionKeys();
    },

    'P14-T7 the target must be an active member' => function () use ($makeSuper, $sessionKeys): void {
        $super   = $makeSuper();
        $service = new ImpersonationService();

        $admin = test_user('imp_target_admin_' . bin2hex(random_bytes(4)), 'admin');

        $e = assert_throws(
            static fn () => $service->start((string) $admin['username'], $super, '203.0.113.7', 'UA'),
            ImpersonationException::class
        );
        assert_same('not_member', $e->reason);

        $suspended = test_user('imp_suspended_' . bin2hex(random_bytes(4)));
        db()->prepare("UPDATE users SET member_status = 'suspended', is_active = 0 WHERE id = ?")
            ->execute([(int) $suspended['id']]);

        $e = assert_throws(
            static fn () => $service->start((string) $suspended['username'], $super, '203.0.113.7', 'UA'),
            ImpersonationException::class
        );
        assert_same('suspended', $e->reason);

        $e = assert_throws(
            static fn () => $service->start('no_such_member_xyz', $super, '203.0.113.7', 'UA'),
            ImpersonationException::class
        );
        assert_same('not_found', $e->reason);

        $e = assert_throws(
            static fn () => $service->start('bad name!', $super, '203.0.113.7', 'UA'),
            ImpersonationException::class
        );
        assert_same('username', $e->reason);

        /* The five failures were logged for the audit. */
        $denied = (int) db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'slogin.denied'")->fetchColumn();
        assert_same(4, $denied);

        $sessionKeys();
    },

    'P14-T8 six attempts in the window are rate-limited' => function () use ($makeSuper, $sessionKeys): void {
        $super   = $makeSuper();
        $service = new ImpersonationService();
        $target  = 'imp_missing_' . bin2hex(random_bytes(3));

        for ($i = 1; $i <= 5; $i++) {
            assert_throws(
                static fn () => $service->start($target, $super, '203.0.113.7', 'UA'),
                ImpersonationException::class,
                'attempt ' . $i . ' is a normal not-found refusal'
            );
        }

        assert_same(5, $service->attempts(strtolower($target)));
        assert_same(0, $service->attemptsLeft(strtolower($target)));

        $e = assert_throws(
            static fn () => $service->start($target, $super, '203.0.113.7', 'UA'),
            ImpersonationException::class
        );
        assert_same('rate_limited', $e->reason);

        /* A different target is unaffected. */
        assert_same(5, $service->attemptsLeft('another_target'));

        $sessionKeys();
    },

    'P14-T9 a tampered token is rejected without touching the audit row' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member);

        $service = new ImpersonationService();

        $before = Impersonation::find((int) $issued['log_id']);

        $_SESSION['imp_token'] = str_repeat('cd', 32);

        $check = $service->check();
        assert_same('tampered', $check['reason']);

        $after = Impersonation::find((int) $issued['log_id']);
        assert_same($before['status'], $after['status']);
        assert_same($before['nonce_hash'], $after['nonce_hash']);

        /* An unknown log id is refused as well. */
        $_SESSION['imp_token'] = (string) $issued['nonce'];
        $_SESSION['imp_log_id'] = 99999999;

        $check = $service->check();
        assert_same('missing_log', $check['reason']);

        $sessionKeys();
    },

    'P14-T10 the raw nonce is never stored' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member);

        $row = Impersonation::find((int) $issued['log_id']);
        $raw = (string) $issued['nonce'];

        assert_same(hash('sha256', $raw), (string) $row['nonce_hash']);
        assert_not_same($raw, (string) $row['nonce_hash']);

        $encoded = json_encode($row);
        assert_true(is_string($encoded));
        assert_not_contains($raw, $encoded, 'no column contains the raw token');

        /* The console cannot reconstruct a lost URL either. */
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM impersonation_log WHERE nonce_hash = " . db()->quote($raw))->fetchColumn());

        $sessionKeys();
    },

    'P14-T11 the superadmin session survives start and exit' => function () use ($makeSuper, $makeMember, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();

        test_signed_in_as($super);

        $service = new ImpersonationService();
        $issued  = $service->start((string) $member['username'], $super, '203.0.113.7', 'UA');

        assert_same((int) $super['id'], (int) $_SESSION['user_id'], 'start leaves the cookie session alone');
        assert_false(auth_is_imp());
        assert_same((int) $super['id'], (int) auth_user()['id']);
        assert_true(is_superadmin());

        /* A refused attempt must not disturb it either. */
        try {
            $service->start('missing_' . bin2hex(random_bytes(3)), $super, '203.0.113.7', 'UA');
        } catch (ImpersonationException) {
            /* expected */
        }

        assert_same((int) $super['id'], (int) $_SESSION['user_id']);
        assert_same((int) $issued['log_id'], (int) Impersonation::recent(1)[0]['id']);

        $sessionKeys();
    },

    'P14-T12 the audit list filters by status, search and date' => function () use ($makeSuper, $makeMember, $sessionKeys): void {
        $super = $makeSuper();

        $first  = $makeMember();
        $second = $makeMember();

        $service = new ImpersonationService();
        $a = $service->start((string) $first['username'], $super, '203.0.113.7', 'Mozilla/5.0 (Macintosh; Mac OS X) Safari/605');
        $b = $service->start((string) $second['username'], $super, '198.51.100.4', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120');

        Impersonation::mark((int) $a['log_id'], 'logged_out');

        $all = Paginator::fromTableRequest(10);
        assert_same(2, Impersonation::queueCount(Impersonation::normaliseFilters(['status' => ''])));

        $loggedOut = Impersonation::normaliseFilters(['status' => 'logged_out']);
        assert_same(1, Impersonation::queueCount($loggedOut));

        $rows = Impersonation::queue($loggedOut, $all);
        assert_count(1, $rows);
        assert_same('logged_out', $rows[0]['status']);

        $byMember = Impersonation::normaliseFilters(['q' => (string) $second['username']]);
        assert_same(1, Impersonation::queueCount($byMember));

        $byIp = Impersonation::normaliseFilters(['q' => '198.51.100.4']);
        assert_same(1, Impersonation::queueCount($byIp));

        $byDevice = Impersonation::normaliseFilters(['q' => 'Firefox']);
        assert_same(1, Impersonation::queueCount($byDevice));

        $today = Impersonation::normaliseFilters(['from' => date('Y-m-d'), 'to' => date('Y-m-d')]);
        assert_same(2, Impersonation::queueCount($today));

        $past = Impersonation::normaliseFilters(['from' => '2020-01-01', 'to' => '2020-01-02']);
        assert_same(0, Impersonation::queueCount($past));

        $counts = Impersonation::statusCounts();
        assert_same(1, $counts['active']);
        assert_same(1, $counts['logged_out']);
        assert_same(2, $counts['all']);

        $totals = Impersonation::totals();
        assert_same(2, $totals['sessions']);
        assert_same(1, $totals['superadmins']);
        assert_same(2, $totals['targets']);

        /* A session whose TTL passed is swept out of `active`. */
        db()->prepare('UPDATE impersonation_log SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')
            ->execute([(int) $b['log_id']]);

        assert_true(Impersonation::expireOverdue() >= 1);
        assert_same('expired', Impersonation::find((int) $b['log_id'])['status']);

        $sessionKeys();
    },

    'P14-T13 a target suspended after the session opened is refused' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member);

        db()->prepare("UPDATE users SET member_status = 'suspended', is_active = 0 WHERE id = ?")
            ->execute([(int) $member['id']]);

        $check = (new ImpersonationService())->check();
        assert_false($check['ok']);
        assert_same('target', $check['reason']);
        assert_same((int) $issued['log_id'], (int) $check['log']['id']);

        $sessionKeys();
    },

    'P14-T14 a deleted audit row ends the session' => function () use ($makeSuper, $makeMember, $open, $sessionKeys): void {
        $super  = $makeSuper();
        $member = $makeMember();
        $issued = $open($super, $member);

        db()->prepare('DELETE FROM impersonation_log WHERE id = ?')->execute([(int) $issued['log_id']]);

        $check = (new ImpersonationService())->check();
        assert_same('missing_log', $check['reason']);
        assert_null($check['log']);

        $sessionKeys();
    },

    'P14-T15 bind refuses an invalid nonce' => function (): void {
        assert_throws(
            static fn () => ImpersonationService::bind(['nonce' => 'short', 'target' => ['id' => 1]]),
            InvalidArgumentException::class
        );

        assert_throws(
            static fn () => ImpersonationService::bind([]),
            InvalidArgumentException::class
        );
    },

    'P14-T16 the console member picker lists only active members' => function () use ($makeSuper): void {
        $member   = test_user('imp_pick_' . bin2hex(random_bytes(4)));
        $admin    = test_user('imp_pick_admin_' . bin2hex(random_bytes(4)), 'admin');
        $inactive = test_user('imp_pick_off_' . bin2hex(random_bytes(4)));
        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([(int) $inactive['id']]);

        $options = Member::activeOptions(500);
        $names   = array_column($options, 'username');

        assert_true(in_array((string) $member['username'], $names, true));
        assert_false(in_array((string) $admin['username'], $names, true), 'staff are not impersonatable');
        assert_false(in_array((string) $inactive['username'], $names, true), 'deactivated accounts are hidden');

        foreach ($options as $option) {
            assert_same('member', (string) db()->query('SELECT role FROM users WHERE id = ' . (int) $option['id'])->fetchColumn());
        }
    },

    'P14-T17 the console and the audit trail render their real screens' => function () use ($makeSuper, $makeMember): void {
        $super  = $makeSuper();
        $member = $makeMember();

        test_signed_in_as($super);

        $issued = (new ImpersonationService())->start((string) $member['username'], $super, '203.0.113.7', 'Mozilla/5.0 (Windows NT 10.0) Chrome/122.0');

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/slogin';

        ob_start();

        try {
            (new SLoginController())->index();
        } finally {
            $console = (string) ob_get_clean();
        }

        assert_contains('Open a member session', $console);
        assert_contains('name="username"', $console);
        assert_contains('list="sloginMembers"', $console);
        assert_contains('@' . $member['username'], $console, 'the recent session is listed');
        assert_contains('Open the full audit trail', $console);
        assert_contains('Super-login', $console);
        assert_not_contains((string) $issued['nonce'], $console, 'the console never shows a raw token');

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/impersonations';

        ob_start();

        try {
            (new SLoginController())->audit();
        } finally {
            $audit = (string) ob_get_clean();
        }

        assert_contains('Super-login audit', $audit);
        assert_contains('@' . $member['username'], $audit);
        assert_contains('@' . $super['username'], $audit);
        assert_contains('Chrome · Windows', $audit, 'the device label is human-readable');

        assert_not_contains((string) $issued['nonce'], $audit, 'the audit never shows a raw token');
    },
];
