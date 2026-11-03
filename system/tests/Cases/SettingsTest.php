<?php
declare(strict_types=1);

/* ==============================================================
 *  SettingsTest — the `settings` key/value store (plan §4.2, §4.5)
 * ============================================================== */

return [
    'settings seed loads the documented defaults' => function (): void {
        assert_same('EcoWealth', setting('site_name'));
        assert_same('PHP', setting('currency'));
        assert_same('0', setting('maintenance_mode'));
        assert_same('10', setting('commission_indirect_max_levels'));
        assert_same('500', setting('min_payout'));
    },

    'setting() returns the fallback for a missing key' => function (): void {
        assert_null(setting('no_such_setting'));
        assert_same('fallback', setting('no_such_setting', 'fallback'));
    },

    'setting_bool() understands the common truthy spellings' => function (): void {
        assert_true(setting_bool('shop_enabled'));
        assert_false(setting_bool('maintenance_mode'));
        assert_true(setting_bool('no_such_setting', true));
        assert_false(setting_bool('no_such_setting'));
    },

    'setting_bool() normalises text values' => function (): void {
        setting_set('probe_flag', 'TRUE');
        assert_true(setting_bool('probe_flag'));

        setting_set('probe_flag', 'off');
        assert_false(setting_bool('probe_flag'));
    },

    'setting_int() and setting_float() cast values' => function (): void {
        assert_same(24, setting_int('shop_payment_deadline_hours'));
        assert_same(2.5, setting_float('usdt_trc20_gas_fee'));
        assert_same(0, setting_int('no_such_setting'));
        assert_same(1.5, setting_float('no_such_setting', 1.5));
    },

    'setting_set() inserts, updates and refreshes the cache' => function (): void {
        setting_set('probe_brand', 'First');
        assert_same('First', setting('probe_brand'));

        setting_set('probe_brand', 'Second');
        assert_same('Second', setting('probe_brand'));

        $stmt = db()->prepare('SELECT value FROM settings WHERE key_name = ?');
        $stmt->execute(['probe_brand']);
        assert_same('Second', $stmt->fetchColumn());
    },

    'setting_all() returns the whole table' => function (): void {
        $all = setting_all();

        assert_true(count($all) >= 41, 'expected at least the seeded settings');
        assert_same('EcoWealth', $all['site_name']);
        assert_true(array_key_exists('ewallet_transfer_weekly_limit', $all));
    },

    'settings written by one case are rolled back before the next' => function (): void {
        /* SkeletonTest may have written probe keys inside its transaction;
         * the harness must have rolled them back. */
        assert_null(setting('probe_brand'));
        assert_null(setting('probe_flag'));
        assert_same('0', setting('maintenance_mode'));
    },
];
