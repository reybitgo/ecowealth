<?php
declare(strict_types=1);

/* ==============================================================
 *  RoyaltyConfigurationTest — Phase 19B (plan §6, §12.9, §13.4)
 *
 *  Proves the configuration half of 19B: the Royalty sub-block on
 *  /admin/settings, its field catalogue, every validation rule and
 *  the audited save. Nothing here evaluates a month, writes a period
 *  or award row, or moves wallet money — 19C does that.
 *
 *      card        the sub-block, its two switches, thirteen inputs
 *                  and the read-only saved ladder
 *      defaults    10/10/10/10, 500k/1M/5M, 3/5 · 5/10 · 10/15
 *      validation  integer thresholds, centavo quotas, 3-decimal
 *                  rates, non-decreasing ladders, group+personal ≤ 100,
 *                  and no enabled ladder that pays nothing
 *      save        a real applySection() round-trip and one
 *                  `settings.update` audit row naming the new keys
 *      audit       no money movement at all (P19B-T11/T25)
 *
 *  Run this group alone with:  php tests/run.php RoyaltyConfiguration
 * ============================================================== */

/** Render the settings page from the controller's real context. */
$renderPage = static function (array $overrides = []): string {
    $controller = new AdminSettingsController();
    $data       = $overrides + $controller->settingsContext() + [
        'page_title'  => 'Settings',
        'current_nav' => 'admin.settings',
        'page_css'    => ['assets/css/orders.css'],
        'page_js'     => ['assets/js/admin-live.js', 'assets/js/admin-settings.js'],
        'live'        => Live::meta('admin.settings', 'page'),
    ];

    ob_start();

    try {
        View::page('admin', 'admin/settings/index', $data);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

/** The markup of one card form. */
$form = static function (string $html, string $id): string {
    $pattern = '/<form\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>(.*?)<\/form>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[0] : '';
};

/** The Commissions card of a freshly rendered page. */
$commissions = static function () use ($renderPage, $form): string {
    return $form($renderPage(), 'settings-commissions');
};

/** Call a private static method on the settings controller. */
$sectionMethod = static function (string $method, string $section): array {
    $reflection = new ReflectionMethod(AdminSettingsController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $section);
};

/**
 * Every key the Commissions card owns, read from the stored settings —
 * the same payload the browser posts when nothing is edited.
 *
 * Phases 20B and 21B added their knobs and ten amounts to the same card;
 * each ladder is a set of table rows rather than settings keys, so the two
 * are read from their own models (a posted card always carries all ten of
 * each). Posting blanks for either ladder would clear it — and audit it —
 * which is not what a Royalty edit does.
 *
 * @return array<string, string>
 */
$baseline = static function (): array {
    $settings   = setting_all();
    $amounts    = TeamHarvestLevel::forForm(TeamHarvestLevel::amounts());
    $ghAmounts  = GeneralHarvestLevel::forForm(GeneralHarvestLevel::amounts());
    $posted     = [];

    foreach (array_merge(
        $GLOBALS['__p19bKeys'],
        $GLOBALS['__p19bToggles']
    ) as $key) {
        if (str_starts_with($key, TeamHarvestLevel::AMOUNT_FIELD . '.')) {
            $level        = (int) substr($key, strlen(TeamHarvestLevel::AMOUNT_FIELD . '.'));
            $posted[$key] = (string) ($amounts[$level] ?? '');
            continue;
        }

        if (str_starts_with($key, GeneralHarvestLevel::AMOUNT_FIELD . '.')) {
            $level        = (int) substr($key, strlen(GeneralHarvestLevel::AMOUNT_FIELD . '.'));
            $posted[$key] = (string) ($ghAmounts[$level] ?? '');
            continue;
        }

        $posted[$key] = (string) ($settings[$key] ?? '');
    }

    return $posted;
};

/** Baseline + the submitted changes. */
$posted = static function (array $changes = []) use ($baseline): array {
    return array_merge($baseline(), $changes);
};

/**
 * Validate one card payload exactly as applySection() does, without
 * writing anything.
 *
 * @param array<string, string> $changes
 * @return array<string, string> field => message
 */
$validate = static function (array $changes = []) use ($posted, $sectionMethod): array {
    $input = $posted($changes);

    $errors = Validator::check($input, $sectionMethod('sectionRules', 'commissions'), $sectionMethod('sectionLabels', 'commissions'));
    $cross  = new ReflectionMethod(AdminSettingsController::class, 'sectionCrossFieldErrors');
    $cross->setAccessible(true);

    return array_merge($errors, $cross->invoke(null, 'commissions', $input));
};

/** Apply one card payload through the real controller path. */
$apply = static function (array $changes = []) use ($posted): array {
    return (new AdminSettingsController())->applySection('commissions', $posted($changes));
};

/** The Royalty keys and switches, in catalogue order (filled at load). */
$GLOBALS['__p19bKeys'] = [];
$GLOBALS['__p19bToggles'] = [];

$fieldKeys = new ReflectionMethod(AdminSettingsController::class, 'sectionKeys');
$fieldKeys->setAccessible(true);
$GLOBALS['__p19bKeys'] = $fieldKeys->invoke(null, 'commissions');

$toggleKeys = new ReflectionMethod(AdminSettingsController::class, 'sectionToggles');
$toggleKeys->setAccessible(true);
$GLOBALS['__p19bToggles'] = $toggleKeys->invoke(null, 'commissions');

/** The thirteen Royalty numeric keys (the commissions keys minus referral/repeat ones). */
$royaltyKeys = static fn (): array => array_values(array_filter(
    $GLOBALS['__p19bKeys'],
    static fn (string $key): bool => str_starts_with($key, 'royalty_')
));

return [
    /* ==========================================================
     *  The card (P19B-T1, T26)
     * ========================================================== */

    'P19B-T1 the Commissions card renders the Royalty sub-block, switches, inputs and ladder' => function () use ($commissions, $royaltyKeys): void {
        test_signed_in_as(test_user('p19b_card_admin', 'admin'));

        $html = $commissions();

        assert_true($html !== '', 'the Commissions form renders');

        assert_contains('Royalty bonus', $html, 'the sub-block heading');
        assert_contains('Monthly · 5 ranks', $html);
        assert_contains('Leader is not a paying rank', $html, 'the card says Leader earns nothing');
        assert_contains('ships <strong>off</strong>', $html, 'the card says the stream ships off');

        /* Two switches. */
        foreach (['royalty_enabled', 'royalty_pay_enabled'] as $key) {
            assert_contains('name="' . $key . '"', $html, $key . ' renders');
            assert_contains('id="setting-' . $key . '"', $html);
        }

        assert_contains('Enable the Royalty bonus', $html);
        assert_contains('Pay Royalty awards', $html);

        /* Thirteen numeric inputs, each once, all type=number. */
        $keys = $royaltyKeys();

        assert_count(13, $keys, 'four thresholds + three quotas + six rates');

        foreach ($keys as $key) {
            assert_same(
                1,
                substr_count($html, 'name="' . $key . '"'),
                $key . ' appears exactly once'
            );

            preg_match('/<input\b[^>]*name="' . preg_quote($key, '/') . '"[^>]*>/', $html, $input);
            assert_true($input !== [], $key . ' input exists');
            assert_contains('type="number"', $input[0], $key . ' is a number input');
        }

        /* The baseline is never rendered. */
        assert_not_contains('royalty_ledger_since', $html, 'the reconciliation baseline is never an input');

        /* The read-only saved ladder: five rows with the reference default. */
        assert_contains('data-royalty-ladder', $html);
        assert_same(5, preg_match_all('/data-royalty-rank="/', $html), 'five ladder rows');

        foreach (['member', 'leader', 'supervisor', 'manager', 'director'] as $slug) {
            assert_contains('data-royalty-rank="' . $slug . '"', $html);
        }

        assert_contains('10 direct members', $html, 'Leader requirement');
        assert_contains('Leader + 10 downline Leaders', $html, 'Supervisor requirement');
        assert_contains('Supervisor + 10 downline Supervisors', $html);
        assert_contains('Manager + 10 downline Managers', $html);

        assert_contains('₱500,000.00', $html);
        assert_contains('₱1,000,000.00', $html);
        assert_contains('₱5,000,000.00', $html);
        assert_contains('3.000%', $html);
        assert_contains('5.000%', $html);
        assert_contains('8.000%', $html, 'Supervisor own purchase is 3 + 5');
        assert_contains('10.000%', $html);
        assert_contains('15.000%', $html);
        assert_contains('25.000%', $html, 'Director own purchase is 10 + 15');
    },

    'P19B-T26 the saved ladder previews the stored rates and own-purchase column' => function () use ($renderPage, $form): void {
        test_signed_in_as(test_user('p19b_ladder_admin', 'admin'));

        $ladder = RoyaltyRank::ladder();

        assert_same(['leader' => 10, 'supervisor' => 10, 'manager' => 10, 'director' => 10], $ladder['thresholds']);
        assert_same(['supervisor' => 500000.0, 'manager' => 1000000.0, 'director' => 5000000.0], $ladder['quotas']);
        assert_same(['group' => 3.0, 'personal' => 5.0], $ladder['rates']['supervisor']);
        assert_same(['group' => 5.0, 'personal' => 10.0], $ladder['rates']['manager']);
        assert_same(['group' => 10.0, 'personal' => 15.0], $ladder['rates']['director']);
        assert_same(8.0, $ladder['own_pct']['supervisor']);
        assert_same(15.0, $ladder['own_pct']['manager']);
        assert_same(25.0, $ladder['own_pct']['director']);
        assert_false($ladder['enabled'], 'the stream ships off');
        assert_true($ladder['pay_enabled'], 'the payment switch ships on');
        assert_same('Automatic on registration', $ladder['ranks'][RoyaltyRank::MEMBER]['requirement']);
        assert_same(50000000, $ladder['ranks'][RoyaltyRank::SUPERVISOR]['quota_cents']);

        /* A stored edit is what the preview shows — the table is not a
         * hard-coded copy of the defaults. */
        setting_set('royalty_manager_group_pct', '6.000');
        setting_set('royalty_manager_personal_pct', '11.000');
        setting_cache_clear();

        $html   = $form($renderPage(), 'settings-commissions');
        $ladder = RoyaltyRank::ladder();

        assert_same(6.0, $ladder['rates']['manager']['group']);
        assert_same(17.0, $ladder['own_pct']['manager']);

        preg_match('/<tr\b[^>]*data-royalty-rank="manager"[^>]*>(.*?)<\/tr>/s', $html, $row);
        $managerRow = $row[1] ?? '';

        assert_true($managerRow !== '', 'the Manager ladder row renders');
        assert_contains('6.000%', $managerRow, 'the edited group rate');
        assert_contains('11.000%', $managerRow, 'the edited personal rate');
        assert_contains('17.000%', $managerRow, 'own purchase tracks the edit');
        assert_contains('₱1,000,000.00', $managerRow);
    },

    /* ==========================================================
     *  Validation (P19B-T3 … T10)
     * ========================================================== */

    'P19B-T3 a directs threshold is an integer between 1 and 1000' => function () use ($validate): void {
        foreach (['0', '-1', 'abc', '1001', '1.5', ''] as $value) {
            $errors = $validate(['royalty_leader_directs' => $value]);
            assert_true(isset($errors['royalty_leader_directs']), 'refused: ' . var_export($value, true));
        }

        foreach (['1', '2', '1000'] as $value) {
            assert_same([], $validate(['royalty_leader_directs' => $value]), 'accepted: ' . $value);
        }

        /* The three downline thresholds allow the larger range. */
        assert_same([], $validate(['royalty_supervisor_leaders' => '100000']));
        assert_true(isset($validate(['royalty_supervisor_leaders' => '100001'])['royalty_supervisor_leaders']));
    },

    'P19B-T4 a quota is a centavo amount from 0 to 999,999,999.99' => function () use ($validate): void {
        foreach (['-1', '1.234', '1e9', '', 'abc'] as $value) {
            $errors = $validate(['royalty_supervisor_quota' => $value]);
            assert_true(isset($errors['royalty_supervisor_quota']), 'refused: ' . var_export($value, true));
        }

        assert_same([], $validate(['royalty_supervisor_quota' => '0.00']));
        assert_same([], $validate(['royalty_director_quota' => '999999999.99']));

        /* The cap is inclusive and the next centavo is refused. */
        assert_true(isset($validate(['royalty_director_quota' => '1000000000.00'])['royalty_director_quota']));
    },

    'P19B-T5 a rate has three decimals and stays within 0–100' => function () use ($validate): void {
        $zeroGroups = [
            'royalty_supervisor_group_pct' => '0.000',
            'royalty_manager_group_pct'    => '0.000',
            'royalty_director_group_pct'   => '0.000',
        ];

        foreach (['-0.001', '100.001', '1.2345', 'abc', ''] as $value) {
            $errors = $validate($zeroGroups + ['royalty_supervisor_personal_pct' => $value]);
            assert_true(isset($errors['royalty_supervisor_personal_pct']), 'refused: ' . var_export($value, true));
        }

        assert_same([], $validate($zeroGroups + ['royalty_supervisor_personal_pct' => '0.000']));
        assert_same([], $validate($zeroGroups + [
            'royalty_supervisor_personal_pct' => '5.000',
            'royalty_manager_personal_pct'    => '10.000',
            'royalty_director_personal_pct'   => '100.000',
        ]), '100.000 is accepted when group is 0 and the lower ranks are lower');
    },

    'P19B-T6 quotas cannot fall as the rank rises' => function () use ($validate): void {
        $errors = $validate(['royalty_manager_quota' => '400000.00']);

        assert_true(isset($errors['royalty_manager_quota']), 'a Manager quota below the Supervisor quota is refused');
        assert_contains('cannot be below the Supervisor quota', $errors['royalty_manager_quota']);

        $errors = $validate(['royalty_director_quota' => '900000.00']);

        assert_true(isset($errors['royalty_director_quota']), 'a Director quota below the Manager quota is refused');
        assert_contains('cannot be below the Manager quota', $errors['royalty_director_quota']);

        /* Equality is non-decreasing. */
        assert_same([], $validate(['royalty_manager_quota' => '500000.00']));
    },

    'P19B-T7 group rates cannot fall as the rank rises' => function () use ($validate): void {
        $errors = $validate(['royalty_director_group_pct' => '2.000']);

        assert_true(isset($errors['royalty_director_group_pct']));
        assert_contains('cannot be below the Manager group rate', $errors['royalty_director_group_pct']);

        assert_same([], $validate(['royalty_director_group_pct' => '5.000']), 'equal rates are allowed');
    },

    'P19B-T8 personal rates cannot fall as the rank rises' => function () use ($validate): void {
        $errors = $validate(['royalty_director_personal_pct' => '4.000']);

        assert_true(isset($errors['royalty_director_personal_pct']));
        assert_contains('cannot be below the Manager personal rate', $errors['royalty_director_personal_pct']);

        assert_same([], $validate(['royalty_director_personal_pct' => '10.000']), 'equal rates are allowed');
    },

    'P19B-T9 group plus personal cannot exceed 100%' => function () use ($validate): void {
        $errors = $validate([
            'royalty_supervisor_group_pct'    => '60.000',
            'royalty_manager_group_pct'       => '60.000',
            'royalty_director_group_pct'      => '60.000',
            'royalty_supervisor_personal_pct' => '50.000',
            'royalty_manager_personal_pct'    => '50.000',
            'royalty_director_personal_pct'   => '50.000',
        ]);

        foreach (['supervisor', 'manager', 'director'] as $slug) {
            assert_true(
                isset($errors['royalty_' . $slug . '_personal_pct']),
                $slug . ' 60/50 is refused'
            );
            assert_contains('cannot exceed 100%', $errors['royalty_' . $slug . '_personal_pct']);
        }

        /* Exactly 100 is allowed (Supervisor 95/5). */
        assert_same([], $validate([
            'royalty_supervisor_group_pct'    => '95.000',
            'royalty_manager_group_pct'       => '95.000',
            'royalty_director_group_pct'      => '95.000',
            'royalty_supervisor_personal_pct' => '5.000',
            'royalty_manager_personal_pct'    => '5.000',
            'royalty_director_personal_pct'   => '5.000',
        ]));
    },

    'P19B-T10 enabling a ladder with every rate at zero is refused' => function () use ($validate): void {
        $zero = [
            'royalty_enabled'                 => '1',
            'royalty_supervisor_group_pct'    => '0.000',
            'royalty_supervisor_personal_pct' => '0.000',
            'royalty_manager_group_pct'       => '0.000',
            'royalty_manager_personal_pct'    => '0.000',
            'royalty_director_group_pct'      => '0.000',
            'royalty_director_personal_pct'   => '0.000',
        ];

        $errors = $validate($zero);

        assert_true(isset($errors['royalty_enabled']), 'an all-zero enabled ladder is a field error');
        assert_contains('would pay nothing', $errors['royalty_enabled']);

        /* Off with all-zero rates is a valid stored configuration. */
        assert_same([], $validate(array_merge($zero, ['royalty_enabled' => '0'])));

        /* One positive rate is enough to enable. */
        assert_same([], $validate(array_merge($zero, [
            'royalty_supervisor_group_pct' => '0.001',
            'royalty_manager_group_pct'    => '0.001',
            'royalty_director_group_pct'   => '0.001',
        ])));
    },

    /* ==========================================================
     *  Save and audit (P19B-T2, T11)
     * ========================================================== */

    'P19B-T2 a changed Royalty value round-trips through the card' => function () use ($apply, $renderPage, $form): void {
        $admin = test_user('p19b_save_admin', 'admin');
        test_signed_in_as($admin);

        assert_same('10', setting('royalty_leader_directs'));

        $result = $apply([
            'royalty_leader_directs' => '11',
            'royalty_supervisor_quota' => '600000.00',
        ]);

        assert_same([], $result['errors'], 'the save validates');
        assert_same('10', $result['changes']['royalty_leader_directs']['from']);
        assert_same('11', $result['changes']['royalty_leader_directs']['to']);
        assert_same('600000.00', $result['changes']['royalty_supervisor_quota']['to']);

        assert_same('11', setting('royalty_leader_directs'), 'the threshold persisted');
        assert_same('600000.00', setting('royalty_supervisor_quota'), 'the quota persisted');

        $html = $form($renderPage(), 'settings-commissions');

        assert_contains('name="royalty_leader_directs"', $html);
        assert_contains('value="11"', $html, 'the card re-renders the new threshold');
        assert_contains('value="600000.00"', $html, 'the card re-renders the new quota');
        assert_contains('11 direct members', $html, 'the ladder preview follows the new threshold');
        assert_contains('₱600,000.00', $html, 'the ladder preview follows the new quota');

        /* An unchanged payload is not a change at all. */
        $again = $apply([]);
        assert_same([], $again['changes'], 'posting the stored values writes nothing');
    },

    'P19B-T11 saving the card writes one settings.update row naming the new keys and no money' => function () use ($apply): void {
        $admin = test_user('p19b_audit_admin', 'admin');
        test_signed_in_as($admin);

        $auditBefore  = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
        $ledgerBefore = (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn();
        $periodBefore = (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn();
        $awardBefore  = (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn();
        $walletBefore = (string) db()->query('SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), \'|\', COALESCE(SUM(withdrawable_balance), 0)) FROM users')->fetchColumn();

        $result = $apply([
            'royalty_enabled'                    => '1',
            'royalty_manager_supervisors'        => '12',
            'royalty_director_personal_pct'      => '16.000',
        ]);

        assert_same([], $result['errors']);
        assert_count(3, $result['changes']);

        $stmt = db()->prepare('SELECT action, actor_id, subject_type, meta_json FROM audit_log WHERE id > ? ORDER BY id');
        $stmt->execute([$auditBefore]);
        $rows = $stmt->fetchAll();

        assert_count(1, $rows, 'exactly one audit row');
        assert_same('settings.update', (string) $rows[0]['action']);
        assert_same('settings', (string) $rows[0]['subject_type']);
        assert_same((int) $admin['id'], (int) $rows[0]['actor_id']);

        $meta = json_decode((string) $rows[0]['meta_json'], true);
        assert_same('commissions', $meta['section'] ?? '');
        assert_true(isset($meta['changes']['royalty_enabled']));
        assert_true(isset($meta['changes']['royalty_manager_supervisors']));
        assert_true(isset($meta['changes']['royalty_director_personal_pct']));
        assert_same('0', (string) ($meta['changes']['royalty_enabled']['from'] ?? ''));
        assert_same('1', (string) ($meta['changes']['royalty_enabled']['to'] ?? ''));

        /* No money moved, no period or award written. */
        assert_same($ledgerBefore, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger')->fetchColumn());
        assert_same($periodBefore, (int) db()->query('SELECT COUNT(*) FROM royalty_periods')->fetchColumn());
        assert_same($awardBefore, (int) db()->query('SELECT COUNT(*) FROM royalty_awards')->fetchColumn());
        assert_same(
            $walletBefore,
            (string) db()->query('SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), \'|\', COALESCE(SUM(withdrawable_balance), 0)) FROM users')->fetchColumn()
        );

        /* The audit says what was saved; nothing else does. */
        assert_same('1', setting('royalty_enabled'));
        assert_same('12', setting('royalty_manager_supervisors'));
        assert_same('16.000', setting('royalty_director_personal_pct'));
    },
];
