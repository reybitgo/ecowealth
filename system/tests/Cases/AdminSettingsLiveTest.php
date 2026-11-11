<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminSettingsLiveTest — the settings page
 *
 *  One surface (`admin.settings`) with nine regions: the placeholder
 *  notice and the eight card bodies (general, shop, receiving accounts,
 *  payout methods, payment windows, fulfilment, commissions, transfers).
 *  The bodies are held regions; the forms, headers and footers stay static.
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

    'the settings feed renders the notice and eight held card bodies' => function () use ($makeAdmin): void {
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
                'settings-general',
                'settings-shop',
                'settings-accounts',
                'settings-payouts',
                'settings-windows',
                'settings-fulfilment',
                'settings-commissions',
                'settings-transfers',
            ],
            array_keys($payload['fragments'])
        );

        $notice     = $payload['fragments']['settings-notice'];
        $general    = $payload['fragments']['settings-general'];
        $shop       = $payload['fragments']['settings-shop'];
        $accounts   = $payload['fragments']['settings-accounts'];
        $payouts    = $payload['fragments']['settings-payouts'];
        $windows    = $payload['fragments']['settings-windows'];
        $fulfilment = $payload['fragments']['settings-fulfilment'];
        $commissions = $payload['fragments']['settings-commissions'];
        $transfers  = $payload['fragments']['settings-transfers'];

        assert_contains('data-live-region="settings-notice"', $notice);
        assert_contains('data-live-region="settings-general"', $general);
        assert_contains('data-live-hold="dirty"', $general);
        assert_contains('name="site_name"', $general);
        assert_contains('name="maintenance_mode"', $general);

        assert_contains('data-live-region="settings-shop"', $shop);
        assert_contains('data-live-hold="dirty"', $shop);
        assert_contains('name="shop_enabled"', $shop);

        assert_contains('data-live-region="settings-accounts"', $accounts);
        assert_contains('data-live-hold="dirty"', $accounts);
        assert_contains('name="payment_gcash_number"', $accounts);
        assert_contains('name="payment_bank_account_number"', $accounts);

        assert_contains('data-live-region="settings-payouts"', $payouts);
        assert_contains('data-live-hold="dirty"', $payouts);
        assert_contains('name="min_payout"', $payouts);
        assert_contains('name="usdt_rate"', $payouts);
        assert_contains('name="payout_usdt_trc20_enabled"', $payouts);
        assert_contains('name="service_fee_usdt_trc20"', $payouts);
        assert_contains('name="usdt_trc20_gas_fee"', $payouts);
        assert_contains('name="usdt_bep20_gas_fee"', $payouts);

        assert_contains('data-live-region="settings-windows"', $windows);
        assert_contains('data-live-hold="dirty"', $windows);
        assert_contains('name="shop_payment_deadline_hours"', $windows);
        assert_contains('name="shop_expiry_enabled"', $windows);

        assert_contains('data-live-region="settings-fulfilment"', $fulfilment);
        assert_contains('data-live-hold="dirty"', $fulfilment);
        assert_contains('name="seller_name"', $fulfilment);
        assert_contains('name="shop_autocomplete_days"', $fulfilment);
        assert_contains('name="shop_autocomplete_enabled"', $fulfilment);

        assert_contains('data-live-region="settings-commissions"', $commissions);
        assert_contains('data-live-hold="dirty"', $commissions);
        assert_contains('name="commission_indirect_max_levels"', $commissions);
        assert_contains('name="commission_direct_enabled"', $commissions);
        assert_contains('name="free_registration_enabled"', $commissions);

        assert_contains('data-live-region="settings-transfers"', $transfers);
        assert_contains('data-live-hold="dirty"', $transfers);
        assert_contains('name="ewallet_transfer_fee"', $transfers);
        assert_contains('name="ewallet_transfer_weekly_limit"', $transfers);

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

    'the payout card follows a settings change and warns while the USDT rate is unset' => function () use ($makeAdmin): void {
        $admin = $makeAdmin();

        test_signed_in_as($admin);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/admin/settings';

        $controller = new AdminSettingsController();

        $before = setting('usdt_rate');
        $beforeMin = setting('min_payout');
        $beforeTrc = setting('payout_usdt_trc20_enabled');
        $beforeGas = setting('usdt_trc20_gas_fee');

        try {
            setting_set('usdt_rate', '0');
            setting_set('min_payout', '250');
            setting_set('payout_usdt_trc20_enabled', '0');
            setting_set('usdt_trc20_gas_fee', '3.5');
            setting_cache_clear();

            $closed = $controller->feedPayload(10, '"admin.settings:10:pqr"', $controller->settingsContext());
            $card   = $closed['fragments']['settings-payouts'];

            assert_contains('USDT payouts are closed', $card);
            assert_contains('name="usdt_rate"', $card);
            assert_contains('value="250"', $card);
            assert_contains('value="3.5"', $card);

            /* The channel switch renders unchecked while the channel is off. */
            $open = null;

            preg_match('/<input[^>]*name="payout_usdt_trc20_enabled"[^>]*>/s', $card, $open);
            assert_true(isset($open[0]) && !str_contains($open[0], 'checked'), 'a closed channel renders unchecked');

            setting_set('usdt_rate', '56');
            setting_set('payout_usdt_trc20_enabled', '1');
            setting_cache_clear();

            $live = $controller->feedPayload(11, '"admin.settings:11:stu"', $controller->settingsContext());
            $card = $live['fragments']['settings-payouts'];

            assert_not_contains('USDT payouts are closed', $card);
            assert_contains('value="56"', $card);

            preg_match('/<input[^>]*name="payout_usdt_trc20_enabled"[^>]*>/s', $card, $open);
            assert_true(isset($open[0]) && str_contains($open[0], 'checked'), 'an open channel renders checked');

            /* The controls stay inside their own card. */
            assert_not_contains('name="usdt_rate"', $live['fragments']['settings-accounts']);
            assert_not_contains('name="min_payout"', $live['fragments']['settings-windows']);
        } finally {
            setting_set('usdt_rate', (string) $before);
            setting_set('min_payout', (string) $beforeMin);
            setting_set('payout_usdt_trc20_enabled', (string) $beforeTrc);
            setting_set('usdt_trc20_gas_fee', (string) $beforeGas);
            setting_cache_clear();
        }
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
        assert_contains('data-live-region="settings-payouts"', $html);
        assert_contains('data-live-region="settings-windows"', $html);
        assert_contains('data-live-region="settings-fulfilment"', $html);
        assert_same(8, substr_count($html, 'data-live-hold="dirty"'), 'one held body per card');
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_same(8, substr_count($html, 'Save settings'), 'one save button per card');
        assert_same(8, substr_count($html, 'name="section"'), 'one section marker per form');
    },
];
