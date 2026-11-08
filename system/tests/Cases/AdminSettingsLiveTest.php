<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminSettingsLiveTest — the settings page
 *
 *  One surface (`admin.settings`) with five regions: the placeholder
 *  notice and the four card bodies. The bodies are held regions; the
 *  forms, headers and footers stay static.
 * ============================================================== */

$makeAdmin = static function (): array {
    return test_user('settings_live_admin', 'admin');
};

$renderPage = static function (array $data): string {
    ob_start();

    try {
        View::page('admin', 'admin/settings/index', $data + [
            'page_title'  => 'Settings',
            'current_nav' => 'admin.settings',
            'page_css'    => ['assets/css/orders.css'],
            'page_js'     => ['assets/js/admin-live.js'],
            'live'        => Live::meta('admin.settings', 'page'),
        ]);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

return [
    'settings saves bump the settings page and the shared config revision' => function (): void {
        $settingsBefore = Live::revision('admin.settings');
        $configBefore   = Live::revision(Live::CONFIG);

        $scope = Live::scope([], 1) . '|config:' . Live::revisionOrZero(Live::CONFIG);
        $etag  = Live::etag('member.membership:1', 0, $scope);

        Live::touchSettings();

        assert_same($settingsBefore + 1, Live::revision('admin.settings'), 'settings page');
        assert_same($configBefore + 1, Live::revision(Live::CONFIG), 'shared config');

        $nextScope = Live::scope([], 1) . '|config:' . Live::revisionOrZero(Live::CONFIG);
        $nextEtag  = Live::etag('member.membership:1', 0, $nextScope);

        assert_not_same($etag, $nextEtag, 'a config move changes a membership ETag');
    },

    'the settings feed renders the notice and four held card bodies' => function () use ($makeAdmin): void {
        $admin = $makeAdmin();

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/settings';

        $controller = new AdminSettingsController();
        $context    = $controller->settingsContext();
        $payload    = $controller->feedPayload(5, '"admin.settings:5:abc"', $context);

        assert_same('admin.settings', $payload['surface']);
        assert_same(
            [
                'settings-notice',
                'settings-shop',
                'settings-accounts',
                'settings-windows',
                'settings-fulfilment',
            ],
            array_keys($payload['fragments'])
        );

        $notice     = $payload['fragments']['settings-notice'];
        $shop       = $payload['fragments']['settings-shop'];
        $accounts   = $payload['fragments']['settings-accounts'];
        $windows    = $payload['fragments']['settings-windows'];
        $fulfilment = $payload['fragments']['settings-fulfilment'];

        assert_contains('data-live-region="settings-notice"', $notice);
        assert_contains('data-live-region="settings-shop"', $shop);
        assert_contains('data-live-hold="dirty"', $shop);
        assert_contains('name="shop_enabled"', $shop);

        assert_contains('data-live-region="settings-accounts"', $accounts);
        assert_contains('data-live-hold="dirty"', $accounts);
        assert_contains('name="payment_gcash_number"', $accounts);
        assert_contains('name="payment_bank_account_number"', $accounts);

        assert_contains('data-live-region="settings-windows"', $windows);
        assert_contains('data-live-hold="dirty"', $windows);
        assert_contains('name="shop_payment_deadline_hours"', $windows);
        assert_contains('name="shop_expiry_enabled"', $windows);

        assert_contains('data-live-region="settings-fulfilment"', $fulfilment);
        assert_contains('data-live-hold="dirty"', $fulfilment);
        assert_contains('name="seller_name"', $fulfilment);
        assert_contains('name="shop_autocomplete_days"', $fulfilment);
        assert_contains('name="shop_autocomplete_enabled"', $fulfilment);

        assert_same(url('admin/settings'), $payload['meta']['canonical']);

        /* Both notice states can be rendered from the same wrapper. */
        $visible = $controller->feedPayload(6, '"admin.settings:6:def"', ['manual_open' => false] + $context);
        assert_contains('look like placeholders', $visible['fragments']['settings-notice']);
        assert_not_contains('settings-notice" hidden', $visible['fragments']['settings-notice']);

        $hidden = $controller->feedPayload(7, '"admin.settings:7:ghi"', ['manual_open' => true] + $context);
        assert_contains('settings-notice" hidden', $hidden['fragments']['settings-notice']);
        assert_not_contains('look like placeholders', $hidden['fragments']['settings-notice']);

        /* A failed save keeps its typed value and error inside its own card. */
        errors_set(['shop_payment_deadline_hours' => 'Payment window (hours) must be at least 1.']);
        old_set(['shop_payment_deadline_hours' => '0']);

        $failed = $controller->feedPayload(8, '"admin.settings:8:jkl"', $controller->settingsContext());

        assert_contains('value="0"', $failed['fragments']['settings-windows']);
        assert_contains('must be at least 1', $failed['fragments']['settings-windows']);
        assert_not_contains('must be at least 1', $failed['fragments']['settings-accounts']);

        /* A hostile value is escaped in its card. */
        setting_set('seller_name', '<b>Hostile Seller</b>');
        setting_cache_clear();

        $hostile = $controller->feedPayload(9, '"admin.settings:9:mno"', $controller->settingsContext());

        assert_contains('&lt;b&gt;Hostile Seller&lt;/b&gt;', $hostile['fragments']['settings-fulfilment']);
        assert_not_contains('<b>Hostile Seller</b>', $hostile['fragments']['settings-fulfilment']);
    },

    'the settings page declares its live surface and regions' => function () use ($makeAdmin, $renderPage): void {
        $admin = $makeAdmin();

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/settings';

        $controller = new AdminSettingsController();
        $html       = $renderPage($controller->settingsContext());

        assert_contains('data-live-surface="admin.settings"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="settings-notice"', $html);
        assert_contains('data-live-region="settings-shop"', $html);
        assert_contains('data-live-region="settings-accounts"', $html);
        assert_contains('data-live-region="settings-windows"', $html);
        assert_contains('data-live-region="settings-fulfilment"', $html);
        assert_same(4, substr_count($html, 'data-live-hold="dirty"'), 'one held body per card');
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_same(4, substr_count($html, 'Save settings'), 'one save button per card');
        assert_same(4, substr_count($html, 'name="section"'), 'one section marker per form');
    },
];
