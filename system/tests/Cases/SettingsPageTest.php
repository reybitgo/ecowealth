<?php
declare(strict_types=1);

/* ==============================================================
 *  SettingsPageTest — one independent form per settings card
 *  (plan §7 Phase 7/8, §9.5; supports P7-T4 and P8-T16)
 *
 *  Every card on /admin/settings posts its own `section` and its own
 *  Save settings button. The controller must only validate, write and
 *  audit that card's keys, so this file checks both the rendered
 *  structure and the section catalogue behind the save.
 * ============================================================== */

/**
 * Render the settings page with the data AdminSettingsController::index()
 * builds and return the HTML.
 *
 * @param array<string, mixed> $overrides
 */
$render = static function (array $overrides = []): string {
    $data = array_merge([
        'page_title'        => 'Settings',
        'current_nav'       => 'admin.settings',
        'page_css'          => ['assets/css/orders.css'],
        'settings'          => setting_all(),
    ], (new AdminSettingsController())->settingsContext(), [
        'shop_enabled'      => true,
        'expiry_enabled'    => true,
        'autocomplete_enabled' => true,
        'maintenance_mode'  => false,
        'manual_open'       => true,
    ], $overrides);

    ob_start();

    try {
        View::page('admin', 'admin/settings/index', $data);
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return (string) ob_get_clean();
};

/** The markup of one form (there are no nested forms on the page). */
$form = static function (string $html, string $id): string {
    $pattern = '/<form\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>(.*?)<\/form>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[0] : '';
};

/** Call a private static method on the settings controller. */
$sectionMethod = static function (string $method, string $section): array {
    $reflection = new ReflectionMethod(AdminSettingsController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $section);
};

return [
    /* ---------- The rendered page --------------------------------- */

    'P7-T4 each settings card has its own form and Save settings button' => function () use ($render, $form): void {
        test_signed_in_as(test_user('st_admin', 'admin'));

        $html = $render();

        $cards = [
            'settings-general'    => 'general',
            'settings-shop'       => 'shop',
            'settings-accounts'   => 'accounts',
            'settings-payouts'    => 'payouts',
            'settings-windows'    => 'windows',
            'settings-fulfilment' => 'fulfilment',
            'settings-commissions' => 'commissions',
            'settings-transfers'  => 'transfers',
        ];

        foreach ($cards as $id => $section) {
            $scope = $form($html, $id);

            assert_true($scope !== '', $id . ' form must render');
            assert_contains('name="section" value="' . $section . '"', $scope);
            assert_contains('name="_csrf"', $scope);
            assert_same(1, substr_count($scope, 'Save settings'), $id . ' has exactly one save button');
            assert_contains('action="' . url('admin/settings') . '"', $scope);
        }

        assert_same(8, substr_count($html, 'Save settings'), 'one save button per card');
        assert_same(8, substr_count($html, 'name="section"'), 'one section marker per form');

        /* Screen-reader labels distinguish the eight buttons. */
        assert_contains('aria-label="Save general settings"', $html);
        assert_contains('aria-label="Save shop settings"', $html);
        assert_contains('aria-label="Save receiving accounts"', $html);
        assert_contains('aria-label="Save payout methods"', $html);
        assert_contains('aria-label="Save payment windows"', $html);
        assert_contains('aria-label="Save fulfilment and invoice settings"', $html);
        assert_contains('aria-label="Save commission settings"', $html);
        assert_contains('aria-label="Save transfer settings"', $html);
    },

    'P7-T4 a card only contains its own fields' => function () use ($render, $form): void {
        test_signed_in_as(test_user('st_admin', 'admin'));

        $html = $render();

        $shop       = $form($html, 'settings-shop');
        $accounts   = $form($html, 'settings-accounts');
        $payouts    = $form($html, 'settings-payouts');
        $windows    = $form($html, 'settings-windows');
        $fulfilment = $form($html, 'settings-fulfilment');
        $general    = $form($html, 'settings-general');
        $commissions = $form($html, 'settings-commissions');
        $transfers  = $form($html, 'settings-transfers');

        /* Phase 15 cards: identity, commission switches and transfer rules. */
        assert_contains('name="site_name"', $general);
        assert_contains('name="site_tagline"', $general);
        assert_contains('name="maintenance_mode"', $general);
        assert_not_contains('name="shop_enabled"', $general);
        assert_not_contains('name="commission_direct_enabled"', $general);

        assert_contains('name="commission_indirect_max_levels"', $commissions);
        assert_contains('name="commission_direct_enabled"', $commissions);
        assert_contains('name="commission_indirect_enabled"', $commissions);
        assert_contains('name="commission_requires_active_sponsor"', $commissions);
        assert_contains('name="free_registration_enabled"', $commissions);
        assert_contains('name="royalty_enabled"', $commissions);
        assert_contains('name="royalty_pay_enabled"', $commissions);
        assert_contains('name="royalty_supervisor_quota"', $commissions);
        assert_contains('Royalty bonus', $commissions);
        assert_not_contains('name="royalty_ledger_since"', $commissions);
        assert_contains('name="team_harvest_enabled"', $commissions);
        assert_contains('name="team_harvest_qualify_directs"', $commissions);
        assert_contains('name="team_harvest_max_levels"', $commissions);
        assert_contains('Team Harvest bonus', $commissions);
        assert_contains('data-team-harvest-table', $commissions);
        assert_not_contains('name="team_harvest_ledger_since"', $commissions);
        assert_contains('name="general_harvest_enabled"', $commissions);
        assert_contains('name="general_harvest_max_levels"', $commissions);
        assert_contains('General Harvest bonus', $commissions);
        assert_contains('data-general-harvest-table', $commissions);
        assert_contains('data-general-harvest-state="unarmed"', $commissions);
        assert_not_contains('name="general_harvest_arm', $commissions);
        assert_not_contains('name="ewallet_transfer_fee"', $commissions);
        assert_not_contains('name="site_name"', $commissions);

        assert_contains('name="ewallet_transfer_fee"', $transfers);
        assert_contains('name="ewallet_min_transfer"', $transfers);
        assert_contains('name="ewallet_transfer_daily_limit"', $transfers);
        assert_contains('name="ewallet_transfer_weekly_limit"', $transfers);
        assert_not_contains('name="commission_direct_enabled"', $transfers);
        assert_not_contains('name="min_payout"', $transfers);

        assert_contains('name="shop_enabled"', $shop);
        assert_contains('name="shop_cancel_enabled"', $shop);
        assert_contains('Enable Cancel of Order', $shop);
        assert_not_contains('name="payment_gcash_number"', $shop);
        assert_not_contains('name="seller_name"', $shop);

        assert_contains('name="payment_gcash_number"', $accounts);
        assert_contains('name="payment_bank_account_number"', $accounts);
        assert_not_contains('name="shop_payment_deadline_hours"', $accounts);
        assert_not_contains('name="seller_name"', $accounts);
        assert_not_contains('name="min_payout"', $accounts);

        /* The payout card owns the withdrawal rules, not the receiving
         * accounts or the shop windows. */
        assert_contains('name="min_payout"', $payouts);
        assert_contains('name="usdt_rate"', $payouts);
        assert_contains('name="payout_gcash_enabled"', $payouts);
        assert_contains('name="service_fee_gcash"', $payouts);
        assert_contains('name="payout_usdt_trc20_enabled"', $payouts);
        assert_contains('name="service_fee_usdt_trc20"', $payouts);
        assert_contains('name="usdt_trc20_gas_fee"', $payouts);
        assert_contains('name="usdt_bep20_gas_fee"', $payouts);
        assert_not_contains('name="payment_gcash_number"', $payouts);
        assert_not_contains('name="shop_payment_deadline_hours"', $payouts);
        assert_not_contains('name="seller_name"', $payouts);

        assert_contains('name="shop_payment_deadline_hours"', $windows);
        assert_contains('name="shop_correction_window_hours"', $windows);
        assert_contains('name="shop_expiry_enabled"', $windows);
        assert_not_contains('name="seller_name"', $windows);
        assert_not_contains('name="shop_autocomplete_days"', $windows);
        assert_not_contains('name="usdt_rate"', $windows);

        assert_contains('name="seller_name"', $fulfilment);
        assert_contains('name="seller_address"', $fulfilment);
        assert_contains('name="shop_autocomplete_days"', $fulfilment);
        assert_contains('name="shop_autocomplete_enabled"', $fulfilment);
        assert_not_contains('name="payment_gcash_number"', $fulfilment);
        assert_not_contains('name="shop_expiry_enabled"', $fulfilment);
    },

    'P7-T4 errors and typed values stay inside their own card' => function () use ($render, $form): void {
        test_signed_in_as(test_user('st_admin', 'admin'));

        errors_set([
            'shop_payment_deadline_hours' => 'Payment window (hours) must be at least 1.',
            'shop_autocomplete_days'      => 'Auto-complete delivered orders after (days) must be at least 1.',
        ]);
        old_set([
            'shop_payment_deadline_hours' => '0',
            'shop_autocomplete_days'      => '0',
        ]);

        $html    = $render();
        $windows = $form($html, 'settings-windows');
        $fulfil  = $form($html, 'settings-fulfilment');

        assert_contains('must be at least 1', $windows);
        assert_contains('value="0"', $windows);
        assert_not_contains('Auto-complete delivered orders after', $windows);
        assert_not_contains('value="Typed Seller"', $windows);

        assert_contains('Auto-complete delivered orders after', $fulfil);
        assert_contains('value="0"', $fulfil);
        assert_not_contains('Payment window (hours) must be at least 1.', $fulfil);

        /* A seller error does not leak into the accounts card. */
        errors_set(['seller_name' => 'Registered business name is required.']);
        old_set(['seller_name' => 'Typed Seller']);

        $second      = $render();
        $fulfilment2 = $form($second, 'settings-fulfilment');
        $accounts    = $form($second, 'settings-accounts');

        assert_contains('value="Typed Seller"', $fulfilment2);
        assert_contains('Registered business name is required.', $fulfilment2);
        assert_not_contains('Typed Seller', $accounts);
    },

    'P7-T4 the legacy all-in-one form is gone' => function () use ($render): void {
        test_signed_in_as(test_user('st_admin', 'admin'));

        $html = $render();

        assert_not_contains('class="row g-6 settings-form"', $html, 'no form wraps the whole grid');
        assert_same(8, preg_match_all('/<form\b[^>]*class="[^"]*settings-form/', $html), 'eight card forms');
    },

    'the settings page has a section menu for every card' => function () use ($render): void {
        test_signed_in_as(test_user('st_admin', 'admin'));

        $html = $render();

        assert_contains('data-settings-tabs', $html);
        assert_contains('aria-label="Settings sections"', $html);

        preg_match('/<nav\b[^>]*data-settings-tabs[^>]*>(.*?)<\/nav>/s', $html, $menu);
        $menu = $menu[1] ?? '';

        assert_true($menu !== '', 'the section menu must render');
        assert_same(8, substr_count($menu, 'data-settings-tab="'), 'one tab per settings card');

        $tabs = [
            'settings-general'     => 'General',
            'settings-shop'        => 'Shop',
            'settings-accounts'    => 'Accounts',
            'settings-payouts'     => 'Payouts',
            'settings-windows'     => 'Windows',
            'settings-fulfilment'  => 'Fulfilment',
            'settings-commissions' => 'Commissions',
            'settings-transfers'   => 'Transfers',
        ];

        foreach ($tabs as $id => $label) {
            assert_contains('href="#' . $id . '"', $menu, $id . ' tab target');
            assert_contains('data-settings-tab="' . $id . '"', $menu, $id . ' tab hook');
            assert_contains($label, $menu, $id . ' tab label');
            assert_contains('id="' . $id . '"', $html, $id . ' card must exist');
        }

        assert_true(
            strpos($html, 'data-settings-tabs') < strpos($html, 'id="settings-general"'),
            'the menu sits above the first card'
        );

        /* The menu is statically rendered, so it survives a live refresh
         * and never becomes a held form body. */
        assert_not_contains('data-live-hold', $menu);
    },

    'the settings cards keep a gap when they follow one another' => function () use ($render): void {
        test_signed_in_as(test_user('st_admin', 'admin'));

        $html = $render();

        preg_match('/<form\b[^>]*\bid="settings-fulfilment"[^>]*>/s', $html, $form);
        $fulfilment = $form[0] ?? '';

        assert_true($fulfilment !== '', 'the fulfilment card must render');
        assert_contains('mb-6', $fulfilment, 'Fulfilment & invoices keeps a gap before Commissions');
        assert_contains('card', $fulfilment);
    },

    'the shop card renders Enable Cancel of Order off by default and on when enabled' => function () use ($render, $form): void {
        test_signed_in_as(test_user('st_admin', 'admin'));

        assert_same('0', setting('shop_cancel_enabled'), 'the seed ships the switch off');
        assert_false(Order::cancelEnabled());

        $html = $render();
        $shop = $form($html, 'settings-shop');

        assert_contains('name="shop_cancel_enabled"', $shop);
        assert_contains('Enable Cancel of Order', $shop);
        assert_contains('Off by default', $shop);

        preg_match('/<input\b[^>]*name="shop_cancel_enabled"[^>]*>/', $shop, $input);
        assert_true($input !== [], 'the switch renders');
        assert_not_contains('checked', $input[0], 'the default is unchecked');

        test_enable_order_cancel();
        assert_true(Order::cancelEnabled());

        $html = $render();
        $shop = $form($html, 'settings-shop');

        preg_match('/<input\b[^>]*name="shop_cancel_enabled"[^>]*>/', $shop, $input);
        assert_true($input !== [], 'the switch renders after the save');
        assert_contains('checked', $input[0], 'an enabled switch renders checked');
    },

    /* ---------- The section catalogue behind the save ------------- */

    'P7-T4 the field catalog is partitioned by section' => function () use ($sectionMethod): void {
        $accounts   = $sectionMethod('sectionKeys', 'accounts');
        $payouts    = $sectionMethod('sectionKeys', 'payouts');
        $windows    = $sectionMethod('sectionKeys', 'windows');
        $fulfilment = $sectionMethod('sectionKeys', 'fulfilment');
        $shop       = $sectionMethod('sectionKeys', 'shop');
        $general    = $sectionMethod('sectionKeys', 'general');
        $commissions = $sectionMethod('sectionKeys', 'commissions');
        $transfers  = $sectionMethod('sectionKeys', 'transfers');

        assert_same(['site_name', 'site_tagline'], $general);
        assert_same(
            [
                'commission_indirect_max_levels',
                'unilevel_max_levels',
                'unilevel_active_threshold',
                'unilevel_hold_days',
                'royalty_leader_directs',
                'royalty_supervisor_leaders',
                'royalty_manager_supervisors',
                'royalty_director_managers',
                'royalty_supervisor_quota',
                'royalty_manager_quota',
                'royalty_director_quota',
                'royalty_supervisor_group_pct',
                'royalty_supervisor_personal_pct',
                'royalty_manager_group_pct',
                'royalty_manager_personal_pct',
                'royalty_director_group_pct',
                'royalty_director_personal_pct',
                'team_harvest_qualify_directs',
                'team_harvest_max_levels',
                'team_harvest_amounts.1',
                'team_harvest_amounts.2',
                'team_harvest_amounts.3',
                'team_harvest_amounts.4',
                'team_harvest_amounts.5',
                'team_harvest_amounts.6',
                'team_harvest_amounts.7',
                'team_harvest_amounts.8',
                'team_harvest_amounts.9',
                'team_harvest_amounts.10',
                'general_harvest_max_levels',
                'general_harvest_amounts.1',
                'general_harvest_amounts.2',
                'general_harvest_amounts.3',
                'general_harvest_amounts.4',
                'general_harvest_amounts.5',
                'general_harvest_amounts.6',
                'general_harvest_amounts.7',
                'general_harvest_amounts.8',
                'general_harvest_amounts.9',
                'general_harvest_amounts.10',
            ],
            $commissions,
            'the commissions card owns the referral depth, both repeat-purchase numbers, the escrow hold, '
                . 'the thirteen Royalty ladder values, the two Team Harvest knobs and its ten amounts, '
                . 'and the General Harvest depth with its ten amounts'
        );
        assert_same(
            [
                'ewallet_transfer_fee',
                'ewallet_min_transfer',
                'ewallet_transfer_daily_limit',
                'ewallet_transfer_weekly_limit',
            ],
            $transfers
        );
        assert_same([], array_intersect($general, $commissions), 'no identity key in commissions');
        assert_same([], array_intersect($commissions, $transfers), 'no commission key in transfers');
        assert_same([], array_intersect($transfers, $payouts), 'the transfer card owns no payout key');

        assert_same([], $shop, 'the shop card carries only its switch');
        assert_true(in_array('payment_gcash_number', $accounts, true));
        assert_true(in_array('payment_bank_account_number', $accounts, true));
        assert_same([], array_intersect($accounts, $windows), 'no window key in accounts');
        assert_same([], array_intersect($accounts, $fulfilment), 'no seller key in accounts');
        assert_same([], array_intersect($accounts, $payouts), 'no payout key in accounts');

        assert_same(
            [
                'min_payout',
                'usdt_rate',
                'service_fee_gcash',
                'service_fee_maya',
                'service_fee_usdt_trc20',
                'usdt_trc20_gas_fee',
                'service_fee_usdt_bep20',
                'usdt_bep20_gas_fee',
            ],
            $payouts,
            'the payout card owns the floor, every fee, and both USDT gas fees'
        );
        assert_same([], array_intersect($payouts, $windows), 'no window key in payouts');
        assert_same([], array_intersect($payouts, $fulfilment), 'no seller key in payouts');

        assert_same(
            ['shop_payment_deadline_hours', 'shop_correction_window_hours', 'shop_max_proof_attempts'],
            $windows
        );
        assert_same([], array_intersect($windows, $fulfilment), 'no seller key in windows');

        assert_true(in_array('seller_name', $fulfilment, true));
        assert_true(in_array('seller_address', $fulfilment, true));
        assert_true(in_array('shop_autocomplete_days', $fulfilment, true));
        assert_same([], array_intersect($fulfilment, $accounts), 'no account key in fulfilment');

        assert_same([], $sectionMethod('sectionKeys', 'nope'));
        assert_same([], $sectionMethod('sectionToggles', 'nope'));
    },

    'P7-T4 each section validates and saves only its own keys' => function () use ($sectionMethod): void {
        assert_same(['shop_enabled', 'shop_cancel_enabled'], $sectionMethod('sectionToggles', 'shop'));
        assert_same(
            [
                'payout_gcash_enabled',
                'payout_maya_enabled',
                'payout_usdt_trc20_enabled',
                'payout_usdt_bep20_enabled',
            ],
            $sectionMethod('sectionToggles', 'payouts'),
            'one switch per payout channel'
        );
        assert_same(['shop_expiry_enabled'], $sectionMethod('sectionToggles', 'windows'));
        assert_same(['shop_autocomplete_enabled'], $sectionMethod('sectionToggles', 'fulfilment'));
        assert_same([], $sectionMethod('sectionToggles', 'accounts'));
        assert_same(['maintenance_mode'], $sectionMethod('sectionToggles', 'general'));
        assert_same(
            [
                'commission_direct_enabled',
                'commission_indirect_enabled',
                'commission_requires_active_sponsor',
                'commission_compression',
                'free_registration_enabled',
                'unilevel_enabled',
                'unilevel_requires_active_upline',
                'unilevel_reversal_enabled',
                'unilevel_release_enabled',
                'royalty_enabled',
                'royalty_pay_enabled',
                'team_harvest_enabled',
                'general_harvest_enabled',
            ],
            $sectionMethod('sectionToggles', 'commissions'),
            'one switch per compensation rule, including the four repeat-purchase, two Royalty, one '
                . 'Team Harvest and one General Harvest switch'
        );
        assert_same([], $sectionMethod('sectionToggles', 'transfers'));

        $shopRules = $sectionMethod('sectionRules', 'shop');
        assert_same(['shop_enabled', 'shop_cancel_enabled'], array_keys($shopRules));

        $accountRules = $sectionMethod('sectionRules', 'accounts');
        assert_same(
            array_values(array_diff($sectionMethod('sectionKeys', 'accounts'), [])),
            array_keys($accountRules),
            'every account field is validated and nothing else'
        );
        assert_false(array_key_exists('shop_payment_deadline_hours', $accountRules));

        $windowRules = $sectionMethod('sectionRules', 'windows');
        assert_same(
            [
                'shop_expiry_enabled',
                'shop_payment_deadline_hours',
                'shop_correction_window_hours',
                'shop_max_proof_attempts',
            ],
            array_keys($windowRules),
            'the windows card validates its fields plus its switch'
        );

        $fulfilmentRules = $sectionMethod('sectionRules', 'fulfilment');
        assert_true(array_key_exists('seller_name', $fulfilmentRules));
        assert_true(array_key_exists('shop_autocomplete_days', $fulfilmentRules));
        assert_true(array_key_exists('shop_autocomplete_enabled', $fulfilmentRules));
        assert_false(array_key_exists('payment_gcash_number', $fulfilmentRules));
        assert_false(array_key_exists('shop_payment_deadline_hours', $fulfilmentRules));

        /* The payout card validates its own keys only, with the money and
         * precision rules the columns store. */
        $payoutRules = $sectionMethod('sectionRules', 'payouts');
        assert_same('required|in:0,1', $payoutRules['payout_usdt_trc20_enabled'] ?? '');
        assert_contains('gte:0', $payoutRules['min_payout'] ?? '');
        assert_contains('decimal:2', $payoutRules['min_payout'] ?? '');
        assert_contains('decimal:4', $payoutRules['usdt_rate'] ?? '');
        assert_contains('lte:100', $payoutRules['service_fee_usdt_trc20'] ?? '');
        assert_contains('decimal:4', $payoutRules['usdt_trc20_gas_fee'] ?? '');
        assert_false(array_key_exists('payment_gcash_number', $payoutRules));
        assert_false(array_key_exists('shop_enabled', $payoutRules));
        assert_false(array_key_exists('seller_name', $payoutRules));
        assert_same([], array_diff(array_keys($payoutRules), array_merge(
            $sectionMethod('sectionKeys', 'payouts'),
            $sectionMethod('sectionToggles', 'payouts')
        )), 'the payout card validates nothing it does not own');

        /* Labels follow the same partition, so messages name the right field. */
        $labels = $sectionMethod('sectionLabels', 'fulfilment');
        assert_same('Invoice Registered business name', $labels['seller_name'] ?? '');
        assert_same('Automatic completion', $labels['shop_autocomplete_enabled'] ?? '');
        assert_false(array_key_exists('payment_gcash_number', $labels));

        $payoutLabels = $sectionMethod('sectionLabels', 'payouts');
        assert_same('Minimum payout', $payoutLabels['min_payout'] ?? '');
        assert_same('USDT rate', $payoutLabels['usdt_rate'] ?? '');
        assert_same('USDT (TRC20) payouts', $payoutLabels['payout_usdt_trc20_enabled'] ?? '');
        assert_same('USDT (TRC20) service fee (%)', $payoutLabels['service_fee_usdt_trc20'] ?? '');
        assert_same('USDT (BEP20) network fee (USDT)', $payoutLabels['usdt_bep20_gas_fee'] ?? '');
        assert_false(array_key_exists('seller_name', $payoutLabels));

        /* The Phase 15 cards validate only their own keys. */
        $generalRules = $sectionMethod('sectionRules', 'general');
        assert_same(
            ['maintenance_mode', 'site_name', 'site_tagline'],
            array_keys($generalRules),
            'identity fields plus the maintenance switch'
        );
        assert_contains('required', $generalRules['site_name'] ?? '');
        assert_same('required|in:0,1', $generalRules['maintenance_mode'] ?? '');

        $commissionRules = $sectionMethod('sectionRules', 'commissions');
        assert_contains('gte:2', $commissionRules['commission_indirect_max_levels'] ?? '');
        assert_contains('lte:10', $commissionRules['commission_indirect_max_levels'] ?? '');
        assert_same('required|in:0,1', $commissionRules['commission_direct_enabled'] ?? '');
        assert_contains('gte:1', $commissionRules['royalty_leader_directs'] ?? '');
        assert_contains('lte:1000', $commissionRules['royalty_leader_directs'] ?? '');
        assert_contains('decimal:2', $commissionRules['royalty_supervisor_quota'] ?? '');
        assert_contains('decimal:3', $commissionRules['royalty_director_personal_pct'] ?? '');
        assert_same('required|in:0,1', $commissionRules['royalty_enabled'] ?? '');
        assert_same('required|in:0,1', $commissionRules['royalty_pay_enabled'] ?? '');
        assert_false(array_key_exists('royalty_ledger_since', $commissionRules), 'the baseline is never validated');
        assert_false(array_key_exists('ewallet_transfer_fee', $commissionRules));

        /* The Team Harvest block (Phase 20B): two small integers, ten money
         * amounts and one switch; the seed's baseline is never a field. */
        assert_same('required|integer|gte:1|lte:10', $commissionRules['team_harvest_qualify_directs'] ?? '');
        assert_same('required|integer|gte:1|lte:10', $commissionRules['team_harvest_max_levels'] ?? '');
        assert_same(TeamHarvestLevel::AMOUNT_RULE, $commissionRules['team_harvest_amounts.1'] ?? '');
        assert_same(TeamHarvestLevel::AMOUNT_RULE, $commissionRules['team_harvest_amounts.10'] ?? '');
        assert_same('required|in:0,1', $commissionRules['team_harvest_enabled'] ?? '');
        assert_false(array_key_exists('team_harvest_ledger_since', $commissionRules));

        $commissionLabels = $sectionMethod('sectionLabels', 'commissions');
        assert_same('Level 3 amount', $commissionLabels['team_harvest_amounts.3'] ?? '');
        assert_same('Direct members needed to qualify', $commissionLabels['team_harvest_qualify_directs'] ?? '');
        assert_same('Enable the Team Harvest bonus', $commissionLabels['team_harvest_enabled'] ?? '');

        $transferRules = $sectionMethod('sectionRules', 'transfers');
        foreach (['ewallet_transfer_fee', 'ewallet_min_transfer', 'ewallet_transfer_daily_limit', 'ewallet_transfer_weekly_limit'] as $key) {
            assert_contains('decimal:2', $transferRules[$key] ?? '', $key . ' keeps the column precision');
            assert_contains('gte:0', $transferRules[$key] ?? '', $key . ' cannot go negative');
        }
        assert_false(array_key_exists('commission_direct_enabled', $transferRules));

        $generalLabels = $sectionMethod('sectionLabels', 'general');
        assert_same('Site name', $generalLabels['site_name'] ?? '');
        assert_same('Maintenance mode', $generalLabels['maintenance_mode'] ?? '');

        $transferLabels = $sectionMethod('sectionLabels', 'transfers');
        assert_same('Transfer fee', $transferLabels['ewallet_transfer_fee'] ?? '');
        assert_same('Daily limit', $transferLabels['ewallet_transfer_daily_limit'] ?? '');
    },
];
