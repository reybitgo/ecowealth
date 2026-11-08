<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminCodeLiveTest — the registration-code list
 *
 *  One live surface (`admin.codes`). These cases pin:
 *
 *    · generating a batch, redeeming a code and letting one expire all
 *      bump the list;
 *    · the feed returns the counters, rows and pagination and escapes
 *      every text value;
 *    · the page declares its live surface and regions.
 * ============================================================== */

$issue = static function (int $quantity = 1): array {
    return (new CodeService())->generate([
        'package_id' => (int) Package::findBySlug('package-a')['id'],
        'quantity'   => $quantity,
        'price'      => '4498.00',
        'expires_at' => '',
        'code_type'  => 'registration',
    ], test_user('clv_admin', 'admin'));
};

return [
    'generating a batch bumps the code list' => function () use ($issue): void {
        $before = Live::revision('admin.codes');

        $batch = $issue(2);

        assert_same(2, $batch['batch']);
        assert_same($before + 1, Live::revision('admin.codes'));
    },

    'redeeming a code bumps the code list' => function () use ($issue): void {
        $batch = $issue(1);
        $code  = (string) $batch['codes'][0];

        $before   = Live::revision('admin.codes');
        $username = 'clv_reg_' . bin2hex(random_bytes(4));

        (new RegistrationService())->register([
            'mode'             => 'code',
            'username'         => $username,
            'email'            => $username . '@test.local',
            'full_name'        => 'Code Live Test',
            'phone'            => '',
            'password'         => 'Member@1234',
            'password_confirm' => 'Member@1234',
            'sponsor'          => 'juan',
            'package_id'       => '',
            'reg_code'         => $code,
            'terms'            => true,
        ], null);

        assert_same($before + 1, Live::revision('admin.codes'));
        assert_same('used', (string) RegCode::findByCode($code)['status']);
    },

    'an expiring code bumps the code list' => function (): void {
        $packageId = (int) Package::findBySlug('package-a')['id'];
        $admin     = test_user('clv_expiry_admin', 'admin');
        $code      = CodeService::randomCode();

        db()->prepare(
            "INSERT INTO reg_codes (code, package_id, price, code_type, status, created_by, expires_at)
             VALUES (?, ?, '4498.00', 'registration', 'unused', ?, ?)"
        )->execute([$code, $packageId, (int) $admin['id'], date('Y-m-d', strtotime('-1 day'))]);

        $before = Live::revision('admin.codes');

        $check = (new CodeService())->validate($code);

        assert_false($check['valid']);
        assert_contains('expired', (string) $check['message']);
        assert_same($before + 1, Live::revision('admin.codes'));
    },

    'the code feed renders the counters, rows and pagination' => function () use ($issue): void {
        $admin = test_user('clv_feed_admin', 'admin');
        $batch = $issue(6);

        /* The list is newest-first, so use the last generated code: it is
         * the one that lands on page 1 with per_page=5. */
        $code = (string) $batch['codes'][5];

        $hostile = test_user('clv_<i>z</i>');

        db()->prepare("UPDATE reg_codes SET status = 'used', used_by = ?, used_at = NOW() WHERE code = ?")
            ->execute([(int) $hostile['id'], $code]);

        test_signed_in_as($admin);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/codes?per_page=5';

        $controller = new AdminCodeController();
        $payload    = $controller->feedPayload(3, '"admin.codes:3:abc"', $controller->listContext());

        assert_true($payload['ok']);
        assert_same('admin.codes', $payload['surface']);
        assert_same(
            ['code-stats', 'code-rows', 'code-pagination'],
            array_keys($payload['fragments'])
        );

        $stats = $payload['fragments']['code-stats'];
        $rows  = $payload['fragments']['code-rows'];
        $pages = $payload['fragments']['code-pagination'];

        assert_contains('data-live-region="code-stats"', $stats);
        assert_contains('Total codes', $stats);
        assert_contains('Revenue (used)', $stats);

        assert_contains('data-live-region="code-rows"', $rows);
        assert_contains('data-live-key=', $rows);
        assert_contains('data-live-focus="copy"', $rows);
        assert_contains('data-live-focus="member"', $rows);
        assert_contains('&lt;i&gt;z&lt;/i&gt;', $rows);
        assert_not_contains('<i>z</i>', $rows);
        assert_contains('data-copy=', $rows);
        assert_contains('Unused', $rows);
        assert_contains('Used', $rows);
        assert_not_contains('data-code-package', $rows, 'the issue form stays outside the regions');

        assert_contains('data-live-region="code-pagination"', $pages);
        assert_contains('admin/codes?per_page=5&amp;page=2', $pages);
        assert_not_contains('/feed', $pages);

        assert_contains('admin/codes?per_page=5', $payload['meta']['canonical']);
    },

    'the code page declares its live surface and regions' => function () use ($issue): void {
        $admin = test_user('clv_page_admin', 'admin');

        $issue(2);

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/codes';

        $controller = new AdminCodeController();
        $data       = $controller->listContext();

        ob_start();

        try {
            View::page('admin', 'admin/codes/index', $data + [
                'page_title'  => 'Registration codes',
                'current_nav' => 'admin.codes',
                'page_css'    => ['assets/css/membership.css'],
                'page_js'     => ['assets/js/app-codes.js'],
                'live'        => Live::meta('admin.codes', 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="admin.codes"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="code-stats"', $html);
        assert_contains('data-live-region="code-rows"', $html);
        assert_contains('data-live-region="code-pagination"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('data-code-package', $html, 'the issue form is still on the page');
        assert_not_contains('data-live-stale', $html, 'no form region is held on this page');
    },
];
