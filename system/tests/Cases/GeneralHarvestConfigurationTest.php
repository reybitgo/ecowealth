<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestConfigurationTest — Phase 21B (plan §6, §12.2–§12.4,
 *  §12.9, §13.3/§13.5, Appendix A, Appendix B.1)
 *
 *  Proves the configuration and the board arithmetic on their own:
 *
 *      card        the General Harvest sub-block, its switch, the
 *                  payable depth, ten amounts, the generated ladder,
 *                  the generated position table, the four-state strip
 *                  and the calculator
 *      defaults    the Appendix A amounts, the depth 10, the switch off
 *      validation  money precision and ceilings, the sum guard, the
 *                  payable-level rule (D-17), the frozen ladder (D-23)
 *      save        a real applySection() round-trip, ten rows, the
 *                  `general_harvest.ladder.update` audit row
 *      arithmetic  every threshold, block, board total and award count
 *                  the reference publishes, pinned to the peso
 *      board       N, gapless, frontier, backlog, the four states,
 *                  blocks, blockRange and the node ↔ member bridge
 *      inert       three registrations write no award and no money
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestConfiguration
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/** Render the settings page from the controller's real context. */
$renderPage = static function (array $overrides = []): string {
    $controller = new AdminSettingsController();
    $data       = $overrides + $controller->settingsContext() + [
        'page_title'  => 'Settings',
        'current_nav' => 'admin.settings',
        'page_css'    => ['assets/css/orders.css'],
        'page_js'     => ['assets/js/admin-live.js', 'assets/js/admin-settings.js', 'assets/js/app-team-harvest.js', 'assets/js/app-general-harvest.js'],
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

/** The General Harvest sub-block of the Commissions card. */
$block = static function (string $html): string {
    if (preg_match('/<div\b[^>]*data-general-harvest-card[^>]*>(.*?)(?:<hr\b|\z)/s', $html, $m) === 1) {
        return $m[1];
    }

    return '';
};

/** The one generated ladder row for a level (`''` when it is absent). */
$ladderRow = static function (string $html, int $level): string {
    $pattern = '/<tr\b[^>]*data-general-harvest-row="' . $level . '"[^>]*>(.*?)<\/tr>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[0] : '';
};

/** One generated position row (`''` when it is absent). */
$positionRow = static function (string $html, int $position): string {
    $pattern = '/<tr\b[^>]*data-general-harvest-position="' . $position . '"[^>]*>(.*?)<\/tr>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[0] : '';
};

/** The value of one `data-general-harvest-*` cell inside a ladder row. */
$cell = static function (string $row, string $attribute, int $level): string {
    $pattern = '/data-general-harvest-' . preg_quote($attribute, '/') . '="' . $level . '"[^>]*>(.*?)</s';

    return preg_match($pattern, $row, $m) === 1 ? trim(strip_tags($m[1])) : '';
};

/** The value of one cell of the position table. */
$cellAt = static function (string $row, int $position, int $level): string {
    $pattern = '/data-general-harvest-position-cell="' . $position . ':' . $level . '"[^>]*>(.*?)</s';

    return preg_match($pattern, $row, $m) === 1 ? trim(strip_tags($m[1])) : '';
};

/** Call a private static method on the settings controller. */
$sectionMethod = static function (string $method, string $section): array {
    $reflection = new ReflectionMethod(AdminSettingsController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $section);
};

/** Integers out of a money/position cell. */
$number = static fn (string $value): int => (int) str_replace(',', '', $value);

/**
 * Every key the Commissions card owns, read from the stored state — the same
 * payload the browser posts when nothing is edited. Both ladders are read
 * from their tables.
 *
 * @return array<string, string>
 */
$baseline = static function (): array {
    $settings = setting_all();
    $teamForm = TeamHarvestLevel::forForm(TeamHarvestLevel::amounts());
    $ghForm   = GeneralHarvestLevel::forForm(GeneralHarvestLevel::amounts());
    $posted   = [];

    foreach (array_merge($GLOBALS['__p21bKeys'], $GLOBALS['__p21bToggles']) as $key) {
        if (str_starts_with($key, TeamHarvestLevel::AMOUNT_FIELD . '.')) {
            $level        = (int) substr($key, strlen(TeamHarvestLevel::AMOUNT_FIELD . '.'));
            $posted[$key] = (string) ($teamForm[$level] ?? '');
            continue;
        }

        if (str_starts_with($key, GeneralHarvestLevel::AMOUNT_FIELD . '.')) {
            $level        = (int) substr($key, strlen(GeneralHarvestLevel::AMOUNT_FIELD . '.'));
            $posted[$key] = (string) ($ghForm[$level] ?? '');
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
 * Validate one card payload exactly as applySection() does, without writing.
 *
 * @param array<string, string> $changes
 * @return array<string, string> field => message
 */
$validate = static function (array $changes = []) use ($posted, $sectionMethod): array {
    $input = $posted($changes);

    $errors = Validator::check(
        $input,
        $sectionMethod('sectionRules', 'commissions'),
        $sectionMethod('sectionLabels', 'commissions')
    );

    $cross = new ReflectionMethod(AdminSettingsController::class, 'sectionCrossFieldErrors');
    $cross->setAccessible(true);

    return array_merge($errors, $cross->invoke(null, 'commissions', $input));
};

/** Apply one card payload through the real controller path. */
$apply = static function (array $changes = []) use ($posted): array {
    return (new AdminSettingsController())->applySection('commissions', $posted($changes));
};

/** The stored General Harvest ladder as `level => amount`. */
$storedLadder = static function (): array {
    return GeneralHarvestLevel::amounts();
};

/** The reference ladder (Appendix A) in level order. */
$reference = static function (): array {
    return GeneralHarvestLevel::referencePreset();
};

/** The keys and switches of the Commissions card, in catalogue order. */
$GLOBALS['__p21bKeys']    = [];
$GLOBALS['__p21bToggles'] = [];

$fieldKeys = new ReflectionMethod(AdminSettingsController::class, 'sectionKeys');
$fieldKeys->setAccessible(true);
$GLOBALS['__p21bKeys'] = $fieldKeys->invoke(null, 'commissions');

$toggleKeys = new ReflectionMethod(AdminSettingsController::class, 'sectionToggles');
$toggleKeys->setAccessible(true);
$GLOBALS['__p21bToggles'] = $toggleKeys->invoke(null, 'commissions');

/** The General Harvest keys inside the Commissions catalogue. */
$ghKeys = static fn (): array => array_values(array_filter(
    $GLOBALS['__p21bKeys'],
    static fn (string $key): bool => str_starts_with($key, 'general_harvest_')
));

/** The last audit id, as the "did anything get audited?" probe. */
$lastAudit = static function (): int {
    return (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
};

/** Arm the board by hand (the real arm job is 21C's). */
$armBoard = static function (int $baselineN, string $at = '2026-11-20 09:14:00'): void {
    db()->prepare(
        'UPDATE general_harvest_board SET baseline_n = ?, armed_at = ?, settled_n = ? WHERE id = 1'
    )->execute([$baselineN, $at, $baselineN]);
};

/** Disarm the board again (the reset's shape). */
$disarmBoard = static function (): void {
    db()->exec('UPDATE general_harvest_board SET baseline_n = NULL, armed_at = NULL, armed_by = NULL, settled_n = 0 WHERE id = 1');
};

/**
 * One queue position at an explicit id.
 *
 * A rolled-back INSERT still advances `AUTO_INCREMENT` in MySQL, so a case
 * that wants positions #1 … #N — the only numbering the board arithmetic is
 * defined over — writes the ids itself. The rows live inside the case's
 * transaction, so the next case starts from an empty queue again.
 */
$queueAt = static function (int $position, int $userId, string $at = '2026-11-16 10:00:00'): void {
    db()->prepare(
        'INSERT INTO harvest_queue
           (id, user_id, qualified_at, directs_at_qualification, teammates, baseline_n, source)
         VALUES (?, ?, ?, 3, 0, 0, "registration")'
    )->execute([$position, $userId, $at]);
};

/** `$count` positions, #1 … #count, with fresh member accounts. */
$queueOf = static function (int $count, string $prefix) use ($queueAt): void {
    for ($position = 1; $position <= $count; $position++) {
        $queueAt($position, (int) test_user($prefix . '_' . $position, 'member')['id']);
    }
};

return [
    /* ==========================================================
     *  The card (P21B-T1, T23, T24)
     * ========================================================== */

    'P21B-T1 the card on a fresh database shows the reference ladder, the depth and the unarmed strip' => function () use ($commissions, $block): void {
        test_signed_in_as(test_user('p21b_card_admin', 'admin'));

        $card = $commissions();
        $html = $block($card);

        assert_true($card !== '', 'the Commissions form renders');
        assert_true($html !== '', 'the General Harvest sub-block renders');

        assert_contains('General Harvest bonus', $html, 'the sub-block heading');
        assert_contains('One board · levels 1–10', $html);
        assert_contains('One board for the whole network', $html, 'the card explains the board');
        assert_contains('minus one', $html, 'the node is the position minus one');
        assert_contains('never depends on purchases, rank or the month', $html);

        /* The switch, off. */
        assert_contains('name="general_harvest_enabled"', $html);
        assert_contains('Enable the General Harvest bonus', $html);

        preg_match('/<input\b[^>]*name="general_harvest_enabled"[^>]*>/', $html, $toggle);
        assert_true($toggle !== [], 'the switch renders');
        assert_not_contains('checked', $toggle[0], 'the stream ships off');

        /* The payable depth. */
        preg_match('/<input\b[^>]*name="general_harvest_max_levels"[^>]*>/', $html, $depth);
        assert_true($depth !== [], 'the depth renders');
        assert_contains('value="10"', $depth[0], 'the depth reads the reference 10');
        assert_contains('min="1"', $depth[0]);
        assert_contains('max="10"', $depth[0]);
        assert_contains('step="1"', $depth[0]);

        /* Ten amount inputs, each once, each a money input with its preset. */
        $amounts = [2000.00, 4000.00, 8000.00, 12000.00, 25000.00, 100000.00, 250000.00, 480000.00, 2500000.00, 5000000.00];
        $inputs  = 0;

        foreach ($amounts as $index => $amount) {
            $level = $index + 1;

            assert_same(
                1,
                substr_count($html, 'name="general_harvest_amounts[' . $level . ']"'),
                'level ' . $level . ' input appears exactly once'
            );

            preg_match('/<input\b[^>]*name="general_harvest_amounts\[' . $level . '\]"[^>]*>/', $html, $input);
            assert_true($input !== [], 'level ' . $level . ' input exists');
            assert_contains('type="number"', $input[0]);
            assert_contains('min="0"', $input[0]);
            assert_contains('max="10000000.00"', $input[0]);
            assert_contains('step="0.01"', $input[0]);
            assert_contains('value="' . number_format($amount, 2, '.', '') . '"', $input[0], 'the stored reference amount');
            assert_contains('data-reference="' . number_format($amount, 2, '.', '') . '"', $input[0], 'the preset rides on the input');
            assert_not_contains('disabled', $input[0], 'an unarmed board lets the amounts be edited');

            $inputs++;
        }

        assert_same(10, $inputs, 'ten amount inputs');

        /* The strip says unarmed and names the arm job (Rule 9). */
        assert_contains('data-general-harvest-state="unarmed"', $html);
        assert_contains('Not armed', $html);
        assert_contains('bin/general_harvest_arm.php', $html, 'the next action is the arm job');
        assert_contains('nothing can pay until the board has a baseline', $html);

        /* The full ladder, the per-node maximum and the ceiling. */
        assert_contains('₱8,381,000.00', $html);
        assert_contains('88,573', $html, 'the apex needs 88,573');
        assert_contains('147,622', $html, 'node #2 needs 147,622');
        assert_contains('206,671', $html, 'node #3 needs 206,671');
        assert_contains('ceiling ₱25,000,000.00', $html, 'the typo guard is on the card');

        /* The preset is offered, never applied by itself. */
        assert_contains('Load reference ladder', $html);
        assert_contains('Clear', $html);
        assert_contains('data-general-harvest-preset', $html);
        assert_contains('data-general-harvest-clear', $html);
        assert_contains('₱8,381,000.00 in total across the ten levels', $html);

        /* Both generated tables are there, and the calculator starts from the
         * current queue size (0 on a fresh database). */
        assert_contains('data-general-harvest-table', $html);
        assert_contains('data-general-harvest-positions', $html);
        assert_contains('data-general-harvest-calculator', $html);
        assert_contains('If the queue reaches N', $html);
        assert_contains('an illustration, not a liability', $html);

        /* Rule 13: arming is not reachable from this page. */
        assert_not_contains('name="general_harvest_arm', $html);
        assert_not_contains('general_harvest_baseline_n', $html);
        assert_not_contains('arm the board', $html);
    },

    'P21B-T23 the card is server-rendered with JavaScript off' => function () use ($renderPage, $commissions, $block, $ladderRow, $cell): void {
        test_signed_in_as(test_user('p21b_nojs_admin', 'admin'));

        $html = $block($commissions());

        /* Every derived number is already in the HTML: the live script only
         * repeats them while the operator types (Rule 12). */
        foreach (range(1, 10) as $level) {
            $row = $ladderRow($html, $level);

            assert_true($row !== '', 'level ' . $level . ' row is server-rendered');
            assert_true($cell($row, 'slots', $level) !== '', 'slots are printed');
            assert_true($cell($row, 'apex', $level) !== '', 'the apex threshold is printed');
            assert_true($cell($row, 'stored', $level) !== '', 'the stored amount is printed');
            assert_true($cell($row, 'stored-cumulative', $level) !== '', 'the stored cumulative is printed');
        }

        /* The cumulative preview also starts from the server value. */
        assert_contains('data-general-harvest-cumulative="10">₱8,381,000.00', $html);

        /* The calculator's default is server-rendered too. */
        assert_contains('data-general-harvest-calculator-total>₱0.00', $html);
        assert_contains('data-general-harvest-calculator-awards>0', $html);

        /* A plain POST form: no JS-only required marker and no submit handler. */
        $page = (string) $renderPage();

        assert_contains('<form', $page);
        assert_contains('name="_csrf"', $page);
        assert_not_contains('onsubmit', $html);
        assert_not_contains('data-general-harvest-required', $html);
        assert_contains('app-general-harvest.js', $page, 'the preview script is loaded');
    },

    'P21B-T24 the card stacks its ten inputs and scrolls both tables inside the card' => function () use ($commissions, $block): void {
        test_signed_in_as(test_user('p21b_mobile_admin', 'admin'));

        $html = $block($commissions());

        /* Each amount input sits in its own stacking grid cell, so the ten
         * inputs stack on a phone instead of widening the page. */
        assert_same(
            10,
            preg_match_all('/<div class="col-12 col-md-6" data-general-harvest-level="\d+">/', $html),
            'one stacking grid cell per level'
        );

        /* Both read-only tables scroll inside their own wrapper. */
        assert_same(
            2,
            preg_match_all('/<div class="table-responsive">\s*<table\b[^>]*data-general-harvest-(?:table|positions)/s', $html),
            'both generated tables are inside .table-responsive'
        );

        /* No page-level fixed width comes from this block. */
        assert_not_contains('min-width: 1200px', $html);
        assert_not_contains('overflow-x: hidden', $html);
        assert_not_contains('style="width', $html);
    },

    /* ==========================================================
     *  Validation (P21B-T4 … T10)
     * ========================================================== */

    'P21B-T4 a ladder totalling more than 25,000,000.00 is a sum error and writes nothing' => function () use ($validate, $apply, $storedLadder, $lastAudit): void {
        test_signed_in_as(test_user('p21b_sum_admin', 'admin'));

        /* Three levels at the per-level ceiling: ₱30,195,000.00 in total,
         * each amount legal on its own. */
        $over = [
            'general_harvest_amounts.8'  => '10000000.00',
            'general_harvest_amounts.9'  => '10000000.00',
            'general_harvest_amounts.10' => '10000000.00',
        ];

        $errors = $validate($over);

        assert_true(isset($errors[GeneralHarvestLevel::SUM_KEY]), 'the sum guard is a field error');
        assert_contains('cannot exceed ₱25,000,000.00', $errors[GeneralHarvestLevel::SUM_KEY]);
        assert_contains('₱30,401,000.00', $errors[GeneralHarvestLevel::SUM_KEY], 'the message names the typed total');

        $auditBefore = $lastAudit();
        $result      = $apply($over);

        assert_true(isset($result['errors'][GeneralHarvestLevel::SUM_KEY]), 'the write path refuses it too');
        assert_same([], $result['changes'], 'no setting was written');
        assert_null($result['general_ladder'], 'no ladder change set was produced');
        assert_same(8000.00, $storedLadder()[3], 'the stored ladder is unchanged');
        assert_same(5000000.00, $storedLadder()[10], 'the level that was typed is unchanged');
        assert_same($auditBefore, $lastAudit(), 'no audit row');
    },

    'P21B-T5 an amount above the 10,000,000.00 ceiling is a field error on that level' => function () use ($validate): void {
        test_signed_in_as(test_user('p21b_ceiling_admin', 'admin'));

        $errors = $validate(['general_harvest_amounts.4' => '10000000.01']);

        assert_true(isset($errors['general_harvest_amounts.4']), 'the level itself is the field');
        assert_contains('Level 4 amount must be at most 10000000', $errors['general_harvest_amounts.4']);

        /* Exactly the ceiling is accepted when the rest is zero. */
        $zero = [];

        foreach (range(1, 10) as $level) {
            $zero['general_harvest_amounts.' . $level] = '0';
        }

        assert_same([], $validate($zero + ['general_harvest_amounts.4' => '10000000.00']), 'exactly the ceiling is accepted');

        /* A negative amount is refused too. */
        assert_true(isset($validate(['general_harvest_amounts.2' => '-0.01'])['general_harvest_amounts.2']));
    },

    'P21B-T6 four decimals or text is refused, two decimals is accepted' => function () use ($validate): void {
        test_signed_in_as(test_user('p21b_decimal_admin', 'admin'));

        foreach (['1000.1234', '1,000.00', 'abc'] as $value) {
            assert_true(
                isset($validate(['general_harvest_amounts.3' => $value])['general_harvest_amounts.3']),
                'refused: ' . $value
            );
        }

        assert_contains(
            'must be a number with at most 2 decimal places',
            $validate(['general_harvest_amounts.3' => '1000.1234'])['general_harvest_amounts.3']
        );

        assert_same([], $validate(['general_harvest_amounts.3' => '1000.12']));
        assert_same([], $validate(['general_harvest_amounts.3' => '1000.1']));
        assert_same([], $validate(['general_harvest_amounts.3' => '']), 'blank means 0');
        assert_same([], $validate(['general_harvest_amounts.3' => '0']));
    },

    'P21B-T7 enabling the stream with an all-zero ladder is refused on the switch' => function () use ($validate, $apply, $lastAudit): void {
        test_signed_in_as(test_user('p21b_zero_admin', 'admin'));

        $zero = ['general_harvest_enabled' => '1'];

        foreach (range(1, 10) as $level) {
            $zero['general_harvest_amounts.' . $level] = '0';
        }

        $errors = $validate($zero);

        assert_true(isset($errors[GeneralHarvestLevel::ENABLED_KEY]), 'the refusal lands on the switch');
        assert_contains('Level 1 has no amount', $errors[GeneralHarvestLevel::ENABLED_KEY], 'the message names the level');
        assert_contains('turn the switch off', $errors[GeneralHarvestLevel::ENABLED_KEY]);

        /* The same all-zero ladder with the switch off is a valid save. */
        assert_same([], $validate(array_merge($zero, ['general_harvest_enabled' => '0'])));

        /* Every payable level must be priced, not just one (D-17). */
        $oneOnly = $validate(array_merge($zero, ['general_harvest_amounts.1' => '0.01']));

        assert_true(isset($oneOnly[GeneralHarvestLevel::ENABLED_KEY]), 'one priced level is not enough');
        assert_contains('Level 2 has no amount', $oneOnly[GeneralHarvestLevel::ENABLED_KEY]);

        /* Ten priced levels let the switch go on. */
        $priced = ['general_harvest_enabled' => '1'];

        foreach (GeneralHarvestLevel::referencePreset() as $level => $amount) {
            $priced['general_harvest_amounts.' . $level] = number_format($amount, 2, '.', '');
        }

        assert_same([], $validate($priced), 'the reference ladder may be enabled');

        /* The refused save leaves the switch off and the ladder intact. */
        $auditBefore = $lastAudit();
        $result      = $apply($zero);

        assert_true(isset($result['errors'][GeneralHarvestLevel::ENABLED_KEY]));
        assert_same('0', setting('general_harvest_enabled'), 'the stored switch is unchanged');
        assert_same(2000.00, GeneralHarvestLevel::amount(1), 'the stored ladder is unchanged');
        assert_same($auditBefore, $lastAudit(), 'no audit row');
    },

    'P21B-T8 enabling with level 4 at 0 and a depth of 4 is refused naming level 4' => function () use ($validate, $apply): void {
        test_signed_in_as(test_user('p21b_level4_admin', 'admin'));

        /* The card is refused, and the message names the level the operator
         * has to fix (D-17). */
        $errors = $validate([
            'general_harvest_enabled'    => '1',
            'general_harvest_max_levels' => '4',
            'general_harvest_amounts.4'  => '0',
        ]);

        assert_true(isset($errors[GeneralHarvestLevel::ENABLED_KEY]));
        assert_contains('Level 4 has no amount', $errors[GeneralHarvestLevel::ENABLED_KEY]);
        assert_contains('every level 1–4', $errors[GeneralHarvestLevel::ENABLED_KEY]);
        assert_not_contains('Level 5', $errors[GeneralHarvestLevel::ENABLED_KEY], 'above the depth is not payable');

        /* The same ladder with a priced level 4 saves and switches on. */
        $result = $apply([
            'general_harvest_enabled'    => '1',
            'general_harvest_max_levels' => '4',
            'general_harvest_amounts.4'  => '12000.00',
        ]);

        assert_same([], $result['errors']);
        assert_same('1', setting('general_harvest_enabled'));
        assert_same('4', setting('general_harvest_max_levels'));

        /* Raising the depth to 5 with level 5 blank is refused on the depth. */
        $raised = $apply([
            'general_harvest_max_levels' => '5',
            'general_harvest_amounts.5'  => '0',
        ]);

        assert_true(isset($raised['errors'][GeneralHarvestLevel::DEPTH_KEY]), 'the depth refuses to expose a zero level');
        assert_contains('Level 5 has no amount', $raised['errors'][GeneralHarvestLevel::DEPTH_KEY]);
        assert_same('4', setting('general_harvest_max_levels'), 'the previous depth survives');
    },

    'P21B-T9 the depth is a whole number 1–10 and a refusal keeps the previous value' => function () use ($validate, $apply): void {
        test_signed_in_as(test_user('p21b_depth_admin', 'admin'));

        foreach (['0', '11', '2.5', '-1', 'abc', ''] as $value) {
            $errors = $validate(['general_harvest_max_levels' => $value]);
            assert_true(
                isset($errors['general_harvest_max_levels']),
                'refused: ' . var_export($value, true)
            );
        }

        assert_contains(
            'must be a whole number',
            $validate(['general_harvest_max_levels' => '2.5'])['general_harvest_max_levels']
        );

        foreach (['1', '10'] as $value) {
            assert_same([], $validate(['general_harvest_max_levels' => $value]), 'accepted: ' . $value);
        }

        foreach (['0', '11'] as $value) {
            $result = $apply(['general_harvest_max_levels' => $value]);

            assert_true(isset($result['errors']['general_harvest_max_levels']));
            assert_same('10', setting('general_harvest_max_levels'), 'the previous depth survives: ' . $value);
        }
    },

    'P21B-T10 a depth of 3 is saved and levels 4–10 are marked not paying with their amounts kept' => function () use ($apply, $commissions, $block, $ladderRow): void {
        test_signed_in_as(test_user('p21b_notpaying_admin', 'admin'));

        $result = $apply([
            'general_harvest_max_levels' => '3',
            'general_harvest_amounts.4'  => '',
            'general_harvest_amounts.5'  => '',
            'general_harvest_amounts.6'  => '',
            'general_harvest_amounts.7'  => '',
            'general_harvest_amounts.8'  => '',
            'general_harvest_amounts.9'  => '',
            'general_harvest_amounts.10' => '',
        ]);

        assert_same([], $result['errors'], 'clearing the levels above a depth of 3 is legal');
        assert_same('3', setting('general_harvest_max_levels'));
        assert_same(3, GeneralHarvestLevel::maxLevels());
        assert_same(0.0, GeneralHarvestLevel::amount(4), 'level 4 is stored as 0.00, not deleted');

        $html = $block($commissions());

        foreach (range(1, 10) as $level) {
            $row = $ladderRow($html, $level);
            assert_true($row !== '');

            $paying = str_contains($row, 'data-general-harvest-paying="1"');
            assert_same($level <= 3, $paying, 'level ' . $level . ' paying flag');
        }

        assert_contains('Not paying at this depth', $ladderRow($html, 4));
        assert_same(
            7,
            preg_match_all('/<tr\b[^>]*data-general-harvest-paying="0"/', $html),
            'levels 4–10 are marked in the generated table'
        );
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn(), 'ten rows, never nine');
    },

    /* ==========================================================
     *  Save, audit and the frozen ladder (P21B-T2, T3, extra)
     * ========================================================== */

    'P21B-T2 saving the reference ladder and the two knobs writes ten rows and one audit row' => function () use ($apply, $storedLadder, $reference, $lastAudit): void {
        $admin = test_user('p21b_save_admin', 'admin');
        test_signed_in_as($admin);

        /* Start from a ladder that is not the reference, so the save moves. */
        $result = $apply([
            'general_harvest_amounts.1' => '2500.00',
            'general_harvest_amounts.10' => '4000000.00',
        ]);

        assert_same([], $result['errors'], 'the save validates');
        assert_same(2500.0, GeneralHarvestLevel::amount(1));
        assert_same(4000000.0, GeneralHarvestLevel::amount(10));
        assert_same(7381500.00, GeneralHarvestLevel::total(), 'the full ladder follows the edit');

        /* The saved ladder stamps its actor on all ten rows. */
        assert_same(
            10,
            (int) db()->query('SELECT COUNT(*) FROM general_harvest_levels WHERE updated_by = ' . (int) $admin['id'])->fetchColumn(),
            'ten rows carry the actor'
        );

        /* One ladder audit row with the whole before/after payload. */
        $auditBefore = $lastAudit();

        $result = $apply([
            'general_harvest_enabled'    => '1',
            'general_harvest_max_levels' => '9',
            'general_harvest_amounts.1'  => '2000.00',
            'general_harvest_amounts.10' => '5000000.00',
        ]);

        assert_same([], $result['errors']);
        assert_same('1', setting('general_harvest_enabled'), 'the switch persisted');
        assert_same('9', setting('general_harvest_max_levels'), 'the depth persisted');
        assert_same($reference(), $storedLadder(), 'the stored ladder is the reference preset');

        $stmt = db()->prepare(
            "SELECT action, actor_id, subject_type, meta_json FROM audit_log
              WHERE action = 'general_harvest.ladder.update' AND id > ? ORDER BY id"
        );
        $stmt->execute([$auditBefore]);
        $rows = $stmt->fetchAll();

        assert_count(1, $rows, 'exactly one ladder audit row');
        assert_same('settings', (string) $rows[0]['subject_type']);
        assert_same((int) $admin['id'], (int) $rows[0]['actor_id']);

        $meta = json_decode((string) $rows[0]['meta_json'], true);

        assert_same(false, $meta['before']['enabled'], 'the switch was still off');
        assert_same(true, $meta['after']['enabled']);
        assert_same(10, $meta['before']['max_levels']);
        assert_same(9, $meta['after']['max_levels']);
        assert_same('2500.00', $meta['before']['levels'][1]);
        assert_same('2000.00', $meta['after']['levels'][1]);
        assert_same('4000000.00', $meta['before']['levels'][10]);
        assert_same('5000000.00', $meta['after']['levels'][10]);

        /* MySQL's JSON type sorts object keys, so compare the set, not the
         * insertion order. */
        $moved = array_keys($meta['changes']);
        sort($moved, SORT_STRING);

        assert_same(['enabled', 'levels.1', 'levels.10', 'max_levels'], $moved);

        /* The tenant-wide settings trail still records the two moved keys. */
        $settingsRows = db()->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'settings.update' AND id > ?"
        );
        $settingsRows->execute([$auditBefore]);
        assert_same(1, (int) $settingsRows->fetchColumn());

        /* Saving the same values again writes no audit row at all. */
        $againBefore = $lastAudit();
        $again       = $apply([]);

        assert_same([], $again['changes'], 'nothing moved');
        assert_same(false, $again['general_ladder']['changed'], 'the General Harvest ladder did not move');
        assert_same(false, $again['ladder']['changed'], 'the Team Harvest ladder did not move');
        assert_same($againBefore, $lastAudit(), 'no audit row for an unchanged save');

        /* A knobs-only save has no level change in the payload. */
        $knobs = $apply(['general_harvest_max_levels' => '8']);

        assert_same(['max_levels'], array_keys($knobs['general_ladder']['changes']));
    },

    'P21B-T3 three blank amounts are saved as 0.00 and render blank again' => function () use ($apply, $storedLadder, $commissions, $block): void {
        test_signed_in_as(test_user('p21b_blank_admin', 'admin'));

        $result = $apply([
            'general_harvest_amounts.2' => '',
            'general_harvest_amounts.5' => '',
            'general_harvest_amounts.9' => '',
        ]);

        assert_same([], $result['errors']);
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn(), 'ten rows, never nine');

        $ladder = $storedLadder();

        assert_same(0.0, $ladder[2]);
        assert_same(0.0, $ladder[5]);
        assert_same(0.0, $ladder[9]);
        assert_same(2000.0, $ladder[1], 'the other levels are untouched');

        /* The card shows blanks for the zero levels. */
        $html = $block($commissions());

        preg_match('/<input\b[^>]*name="general_harvest_amounts\[2\]"[^>]*>/', $html, $second);
        assert_true($second !== []);
        assert_contains('value=""', $second[0], 'a zero renders as a blank input');
    },

    'P21B-T26 an armed board freezes the ten amounts but not the depth' => function () use ($apply, $armBoard, $disarmBoard, $storedLadder, $commissions, $block, $lastAudit): void {
        test_signed_in_as(test_user('p21b_frozen_admin', 'admin'));

        $armBoard(0);

        $ladderBefore = $storedLadder();
        $auditBefore  = $lastAudit();

        $result = $apply(['general_harvest_amounts.3' => '9000.00']);

        assert_true(isset($result['errors'][GeneralHarvestLevel::ARMED_KEY]), 'a frozen ladder refuses the change');
        assert_contains('The board was armed on 2026-11-20 09:14:00', $result['errors'][GeneralHarvestLevel::ARMED_KEY]);
        assert_contains('the ten amounts are frozen', $result['errors'][GeneralHarvestLevel::ARMED_KEY]);
        assert_contains('business reset is the only way', $result['errors'][GeneralHarvestLevel::ARMED_KEY]);
        assert_same($ladderBefore, $storedLadder(), 'the stored ladder is unchanged');
        assert_same($auditBefore, $lastAudit(), 'no audit row');

        /* The card renders the freeze: disabled inputs and the sentence. */
        $html = $block($commissions());

        preg_match('/<input\b[^>]*name="general_harvest_amounts\[3\]"[^>]*>/', $html, $third);
        assert_true($third !== []);
        assert_contains('disabled', $third[0], 'a frozen amount renders disabled');
        assert_contains('data-general-harvest-frozen', $html);
        assert_contains('the ten amounts are frozen', $html);
        assert_not_contains('data-general-harvest-preset', $html, 'the preset is not offered on a frozen ladder');

        /* The depth still moves, and exposes the frozen amounts. */
        $depth = $apply(['general_harvest_max_levels' => '9']);

        assert_same([], $depth['errors'], 'the depth is not frozen');
        assert_same('9', setting('general_harvest_max_levels'));
        assert_same($ladderBefore, $storedLadder(), 'the depth change wrote no amount');

        /* The model refuses a direct write as well (Rule 14). */
        $error = assert_throws(
            static fn () => GeneralHarvestLevel::save(array_fill(1, 10, 1.0)),
            InvalidArgumentException::class
        );
        assert_contains('frozen', $error->getMessage());

        /* A disarm makes the ladder editable again. */
        $disarmBoard();

        assert_same([], $apply(['general_harvest_amounts.3' => '9000.00'])['errors']);
        assert_same(9000.0, GeneralHarvestLevel::amount(3));
    },

    'P21B-T28 a card that does not post a ladder never clears it, and an empty array does' => function () use ($posted, $storedLadder, $reference): void {
        test_signed_in_as(test_user('p21b_partial_admin', 'admin'));

        $controller = new AdminSettingsController();

        /* Both ladders absent from the post. */
        $partial = $posted();

        foreach (array_keys($partial) as $key) {
            if (str_starts_with((string) $key, TeamHarvestLevel::AMOUNT_FIELD . '.')
                || str_starts_with((string) $key, GeneralHarvestLevel::AMOUNT_FIELD . '.')) {
                unset($partial[$key]);
            }
        }

        $partial['general_harvest_enabled'] = '1';

        $result = $controller->applySection('commissions', $partial);

        assert_same([], $result['errors']);
        assert_same('1', setting('general_harvest_enabled'));
        assert_same($reference(), $storedLadder(), 'the General Harvest ladder survived the partial post');
        assert_same(['enabled'], array_keys($result['general_ladder']['changes']), 'only the switch moved');

        /* Only the General Harvest array is absent: the Team Harvest one posts. */
        $partial2 = $posted(['general_harvest_enabled' => '0']);

        foreach (array_keys($partial2) as $key) {
            if (str_starts_with((string) $key, GeneralHarvestLevel::AMOUNT_FIELD . '.')) {
                unset($partial2[$key]);
            }
        }

        $result = $controller->applySection('commissions', $partial2);

        assert_same([], $result['errors']);
        assert_same($reference(), $storedLadder(), 'the General Harvest ladder is still intact');

        /* An explicit empty array *is* a submission: it clears the ladder. */
        $partial2[GeneralHarvestLevel::AMOUNT_FIELD] = [];

        $cleared = $controller->applySection('commissions', $partial2);

        assert_same([], $cleared['errors'], 'the switch is off, so an all-zero ladder is legal');
        assert_same(array_fill(1, 10, 0.0), $storedLadder(), 'an explicit empty submission clears all ten rows');
        assert_same(0.0, GeneralHarvestLevel::total());
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn(), 'cleared, never deleted');

        /* The Team Harvest ladder was posted unchanged and is untouched. */
        assert_same(TeamHarvestLevel::total(TeamHarvestLevel::referencePreset()), TeamHarvestLevel::total());
    },

    'P21B-T34 a post that omits the depth keeps it, and an empty depth is still refused' => function () use ($posted): void {
        test_signed_in_as(test_user('p21b_depthpost_admin', 'admin'));

        $controller = new AdminSettingsController();

        /* An older form (or a script that only flips a switch) that never
         * carries the depth must not fail validation or lose the knob. */
        $partial = $posted(['general_harvest_enabled' => '1']);

        unset($partial[GeneralHarvestLevel::DEPTH_KEY]);

        $kept = $controller->applySection('commissions', $partial);

        assert_same([], $kept['errors'], 'a post without the depth validates');
        assert_same('10', setting(GeneralHarvestLevel::DEPTH_KEY), 'the stored depth survives');
        assert_same(['enabled'], array_keys($kept['general_ladder']['changes']), 'only the switch moved');

        /* A depth that is present but empty is a submission: `required`. */
        $blank = $controller->applySection('commissions', $posted([
            'general_harvest_enabled'    => '0',
            GeneralHarvestLevel::DEPTH_KEY => '',
        ]));

        assert_true(isset($blank['errors'][GeneralHarvestLevel::DEPTH_KEY]), 'an empty depth is refused');
        assert_same('10', setting(GeneralHarvestLevel::DEPTH_KEY), 'the previous depth survives the refusal');
    },

    'P21B-T29 a refused save changes neither store and its values and errors survive the redirect' => function () use ($apply, $storedLadder, $lastAudit): void {
        test_signed_in_as(test_user('p21b_refuse_admin', 'admin'));

        $ladderBefore = $storedLadder();
        $depthBefore  = setting('general_harvest_max_levels');
        $auditBefore  = $lastAudit();

        $result = $apply([
            'general_harvest_max_levels' => '7',
            'general_harvest_amounts.8'  => '10000000.00',
            'general_harvest_amounts.9'  => '10000000.00',
            'general_harvest_amounts.10' => '10000000.00',
        ]);

        assert_true(isset($result['errors'][GeneralHarvestLevel::SUM_KEY]), 'the card is refused');
        assert_same([], $result['changes'], 'a refused card writes no setting');
        assert_same($ladderBefore, $storedLadder(), 'a refused card writes no ladder row');
        assert_same($depthBefore, setting('general_harvest_max_levels'), 'the depth sent with the refused card did not land');
        assert_same($auditBefore, $lastAudit(), 'no audit row');

        /* save() keeps the input and the errors for the next render. */
        errors_set($result['errors']);
        old_set($result['input']);

        assert_same('10000000.00', old('general_harvest_amounts.10'), 'the typed value survives');
        assert_contains('cannot exceed', error_for(GeneralHarvestLevel::SUM_KEY));
        assert_same('7', old('general_harvest_max_levels'), 'the typed depth survives');

        $html = (function () use ($result): string {
            ob_start();

            try {
                View::page('admin', 'admin/settings/index', (new AdminSettingsController())->settingsContext());
            } finally {
                $rendered = (string) ob_get_clean();
            }

            return $rendered;
        })();

        assert_contains('value="10000000.00"', $html, 'the card re-renders the typed amount');
        assert_contains('cannot exceed ₱25,000,000.00', $html, 'the card re-renders the error');
        assert_contains('value="7"', $html, 'the card re-renders the typed depth');

        errors_set([]);
        old_clear();

        assert_contains('₱5,000,000.00', $html, 'the stored ladder is still what the table prints');
    },

    /* ==========================================================
     *  The board arithmetic (P21B-T11 … T20)
     * ========================================================== */

    'P21B-T11 the apex thresholds are the reference staircase' => function (): void {
        $staircase = [4, 13, 40, 121, 364, 1093, 3280, 9841, 29524, 88573];

        foreach (range(1, 10) as $index => $level) {
            assert_same($staircase[$index], GeneralHarvestLevel::threshold(0, $level), 'threshold(0, ' . $level . ')');
            assert_same($staircase[$index], GeneralHarvestLevel::apexThreshold($level), 'apexThreshold(' . $level . ')');
            assert_same((int) (3 ** $level), GeneralHarvestLevel::slots($level), 'slots(' . $level . ')');
        }

        assert_same(59049, GeneralHarvestLevel::slots(10));
        assert_same(88573, GeneralHarvestLevel::apexThreshold(10));
    },

    'P21B-T12 positions #2 and #4 move the staircase right by 3^L·q' => function (): void {
        $node1 = [7, 22, 67, 202, 607, 1822, 5467, 16402, 49207, 147622];
        $node3 = [13, 40, 121, 364, 1093, 3280, 9841, 29524, 88573, 265720];

        foreach (range(1, 10) as $index => $level) {
            assert_same($node1[$index], GeneralHarvestLevel::threshold(1, $level), 'threshold(1, ' . $level . ')');
            assert_same($node3[$index], GeneralHarvestLevel::threshold(3, $level), 'threshold(3, ' . $level . ')');
        }

        /* The reference's §4.5 table, all four positions. */
        $reference = [
            1 => [4, 13, 40, 121],
            2 => [7, 22, 67, 202],
            3 => [10, 31, 94, 283],
            4 => [13, 40, 121, 364],
        ];

        foreach ($reference as $position => $cells) {
            foreach ($cells as $index => $value) {
                assert_same($value, GeneralHarvestLevel::threshold($position - 1, $index + 1), 'position ' . $position . ' level ' . ($index + 1));
            }
        }

        /* Position #4 is the apex shifted one level (the nesting rule). */
        foreach (range(1, 9) as $level) {
            assert_same(
                GeneralHarvestLevel::apexThreshold($level + 1),
                GeneralHarvestLevel::threshold(3, $level),
                'node 3 level ' . $level . ' is the apex level ' . ($level + 1)
            );
        }
    },

    'P21B-T13 the apex blocks start at start+1 and are 3^L positions wide' => function (): void {
        $from = [2, 5, 14, 41, 122, 365, 1094, 3281, 9842, 29525];
        $to   = [4, 13, 40, 121, 364, 1093, 3280, 9841, 29524, 88573];

        foreach (range(1, 10) as $index => $level) {
            assert_same($from[$index], GeneralHarvestLevel::blockFrom(0, $level), 'blockFrom(0, ' . $level . ')');
            assert_same($to[$index], GeneralHarvestLevel::blockTo(0, $level), 'blockTo(0, ' . $level . ')');
            assert_same(
                GeneralHarvestLevel::slots($level),
                GeneralHarvestLevel::blockTo(0, $level) - GeneralHarvestLevel::blockFrom(0, $level) + 1,
                'the block is 3^L positions wide: level ' . $level
            );
            assert_same(
                GeneralHarvestLevel::blockFrom(0, $level) - 1,
                GeneralHarvestLevel::blockStart(0, $level),
                'the 1-based start is the 0-based slot plus one'
            );
        }

        /* A block always lies after its node (§12.2 Rule 3). */
        foreach ([0, 1, 5, 40] as $seq) {
            foreach (range(1, 10) as $level) {
                assert_true(
                    GeneralHarvestLevel::blockFrom($seq, $level) > $seq + 1,
                    'node ' . $seq . ' level ' . $level . ' starts after the node'
                );
            }
        }
    },

    'P21B-T14 qFor is the highest complete node, or -1' => function (): void {
        $expected = [
            4   => [0, -1, -1, -1, -1, -1, -1, -1, -1, -1],
            13  => [3, 0, -1, -1, -1, -1, -1, -1, -1, -1],
            20  => [5, 0, -1, -1, -1, -1, -1, -1, -1, -1],
            22  => [6, 1, -1, -1, -1, -1, -1, -1, -1, -1],
            40  => [12, 3, 0, -1, -1, -1, -1, -1, -1, -1],
            121 => [39, 12, 3, 0, -1, -1, -1, -1, -1, -1],
        ];

        foreach ($expected as $n => $byLevel) {
            foreach (range(1, 10) as $index => $level) {
                assert_same($byLevel[$index], GeneralHarvestLevel::qFor($level, $n), 'qFor(' . $level . ', ' . $n . ')');
            }
        }

        /* The prefix property: complete nodes are exactly 0 … qFor. */
        foreach ([0, 3, 4, 39, 120, 88572] as $n) {
            for ($seq = 0; $seq <= 40; $seq++) {
                assert_same(
                    $seq <= GeneralHarvestLevel::qFor(1, $n),
                    GeneralHarvestLevel::complete($seq, 1, $n),
                    'node ' . $seq . ' at N = ' . $n
                );
            }
        }

        /* filled() clamps: partial, empty and full. */
        assert_same(0, GeneralHarvestLevel::filled(0, 3, 13));
        assert_same(26, GeneralHarvestLevel::filled(0, 3, 39));
        assert_same(27, GeneralHarvestLevel::filled(0, 3, 40));
        assert_same(27, GeneralHarvestLevel::filled(0, 3, 100), 'a full block never overfills');
        assert_same([1, 2, 3], GeneralHarvestLevel::levelsFor(0, 40));
    },

    'P21B-T15 the whole-board totals are the reference closed form' => function () use ($reference): void {
        $totals = [
            4     => 2000.00,
            13    => 12000.00,
            20    => 16000.00,
            22    => 22000.00,
            40    => 50000.00,
            121   => 176000.00,
            364   => 579000.00,
            1093  => 1888000.00,
            3280  => 6065000.00,
        ];

        foreach ($totals as $n => $total) {
            assert_same($total, GeneralHarvestLevel::boardTotal($n, $reference()), 'boardTotal(' . $n . ')');
        }

        /* The closed form is the sum over nodes, node by node. */
        foreach ([0, 4, 13, 40, 121] as $n) {
            $sum = 0.0;

            for ($seq = 0; $seq < $n; $seq++) {
                $sum += GeneralHarvestLevel::earnedBy($seq, $n, $reference());
            }

            assert_same(GeneralHarvestLevel::boardTotal($n, $reference()), round($sum, 2), 'node-by-node sum at N = ' . $n);
        }

        assert_same(0.0, GeneralHarvestLevel::boardTotal(0, $reference()), 'an empty queue owes nothing');
        assert_same(0.0, GeneralHarvestLevel::boardTotal(3, $reference()), 'the first block needs N = 4');
    },

    'P21B-T16 the last position of an 88,573 queue is worth exactly one full ladder' => function () use ($reference): void {
        assert_same(181827000.00, GeneralHarvestLevel::boardTotal(88572, $reference()));
        assert_same(190208000.00, GeneralHarvestLevel::boardTotal(88573, $reference()));
        assert_same(
            8381000.00,
            GeneralHarvestLevel::boardTotal(88573, $reference()) - GeneralHarvestLevel::boardTotal(88572, $reference()),
            'the 88,573rd member completes node 0 level 10'
        );

        /* The per-node ceiling (§12.8 G, §12.4 Rule 2). */
        assert_same(8381000.00, GeneralHarvestLevel::earnedBy(0, 88573, $reference()));
        assert_same(3381000.00, GeneralHarvestLevel::earnedBy(0, 88572, $reference()), 'level 10 is one position short');
        assert_same(8381000.00, GeneralHarvestLevel::earnedBy(1, 147622, $reference()));
        assert_same(3381000.00, GeneralHarvestLevel::earnedBy(1, 147621, $reference()));
    },

    'P21B-T17 awardCount counts one award per completed block' => function (): void {
        $counts = [
            4     => 1,
            13    => 5,
            20    => 7,
            22    => 9,
            40    => 18,
            121   => 58,
            364   => 179,
            1093  => 543,
            3280  => 1636,
            88572 => 44271,
            88573 => 44281,
        ];

        foreach ($counts as $n => $count) {
            assert_same($count, GeneralHarvestLevel::awardCount($n), 'awardCount(' . $n . ')');
        }

        assert_same(0, GeneralHarvestLevel::awardCount(3));
        assert_same(
            8381000.00 / 8381000.00 * GeneralHarvestLevel::awardCount(88573),
            (float) GeneralHarvestLevel::awardCount(88573),
            'the count is a whole number'
        );
    },

    'P21B-T18 a node earns only the levels its block has filled' => function () use ($reference): void {
        assert_same(14000.00, GeneralHarvestLevel::earnedBy(0, 40, $reference()), 'the apex has levels 1–3');
        assert_same(6000.00, GeneralHarvestLevel::earnedBy(1, 40, $reference()), 'node 1 has levels 1–2');
        assert_same(0.00, GeneralHarvestLevel::earnedBy(13, 40, $reference()), 'node 13 needs N = 43');

        /* The depth caps what a node is paid, but not what the board
         * completes. */
        assert_same(6000.00, GeneralHarvestLevel::earnedBy(0, 40, $reference(), 2), 'at depth 2 the apex stops at level 2');
        assert_same(2000.00, GeneralHarvestLevel::earnedBy(0, 40, $reference(), 1));

        /* §12.8 B: the 20-member board, position by position. */
        $board = [0 => 6000.0, 1 => 2000.0, 2 => 2000.0, 3 => 2000.0, 4 => 2000.0, 5 => 2000.0, 6 => 0.0];

        foreach ($board as $seq => $earned) {
            assert_same($earned, GeneralHarvestLevel::earnedBy($seq, 20, $reference()), 'position #' . ($seq + 1));
        }

        assert_same(16000.00, GeneralHarvestLevel::boardTotal(20, $reference()), 'the 20-member board total');
    },

    'P21B-T19 the next completion is the queue length the next block needs' => function (): void {
        assert_same(88573, GeneralHarvestLevel::nextN(0, 10));
        assert_same(4, GeneralHarvestLevel::nextN(0, 1));

        $next = GeneralHarvestLevel::nextCompletion(1204);

        assert_same(1207, $next[1]['next_n'], 'level 1 next needs 1,207');
        assert_same(1210, $next[2]['next_n'], 'level 2 next needs 1,210');
        assert_same(1228, $next[3]['next_n'], 'level 3 next needs 1,228');
        assert_same(401, $next[1]['node'], 'level 1 next completes node 401');
        assert_same(range(1, 10), array_keys($next));

        /* At N = 0 every level waits for the apex's own threshold. */
        foreach (GeneralHarvestLevel::nextCompletion(0) as $level => $row) {
            assert_same(0, $row['node']);
            assert_same(GeneralHarvestLevel::apexThreshold($level), $row['next_n']);
        }
    },

    'P21B-T20 a sweep of every N to 1,210 never completes two blocks of one level' => function (): void {
        for ($n = 0; $n <= 1210; $n++) {
            $next = GeneralHarvestLevel::nextCompletion($n);

            foreach (range(1, 10) as $level) {
                /* The next completion is always in the future or now — never
                 * behind the queue. */
                assert_true($next[$level]['next_n'] >= $n, 'level ' . $level . ' next_n ' . $next[$level]['next_n'] . ' >= ' . $n);

                /* At most one block of a level completes per position. */
                $completed = GeneralHarvestLevel::paid($level, $n) - GeneralHarvestLevel::paid($level, $n - 1);

                assert_true($completed >= 0 && $completed <= 1, 'level ' . $level . ' at N = ' . $n . ' completed ' . $completed);

                /* A completion at N is exactly "the previous next_n is N". */
                if ($n > 0) {
                    $previous = GeneralHarvestLevel::nextCompletion($n - 1)[$level]['next_n'];

                    assert_same($completed === 1, $previous === $n, 'level ' . $level . ' completed at N = ' . $n);
                }
            }
        }

        /* §12.8 A's seven-member tree: N = 7 pays nodes 0 and 1. */
        assert_same(4000.00, GeneralHarvestLevel::boardTotal(7, GeneralHarvestLevel::referencePreset()));
        assert_same(2000.00, GeneralHarvestLevel::earnedBy(0, 7, GeneralHarvestLevel::referencePreset()), 'position #1 is paid at N = 7');
        assert_same(2000.00, GeneralHarvestLevel::earnedBy(1, 7, GeneralHarvestLevel::referencePreset()), 'position #2 is paid at N = 7');
        assert_same(0.00, GeneralHarvestLevel::earnedBy(2, 7, GeneralHarvestLevel::referencePreset()), 'position #3 needs N = 10');
    },

    'P21B-T22 the generated tables equal Appendix A and the reference position table' => function () use ($commissions, $block, $ladderRow, $positionRow, $cell, $cellAt, $number): void {
        test_signed_in_as(test_user('p21b_appendix_admin', 'admin'));

        $html = $block($commissions());

        assert_same(10, preg_match_all('/data-general-harvest-row="\d+"/', $html), 'ten read-only ladder rows');

        $slots      = [3, 9, 27, 81, 243, 729, 2187, 6561, 19683, 59049];
        $apex       = [4, 13, 40, 121, 364, 1093, 3280, 9841, 29524, 88573];
        $amounts    = [2000, 4000, 8000, 12000, 25000, 100000, 250000, 480000, 2500000, 5000000];
        $cumulative = [2000, 6000, 14000, 26000, 51000, 151000, 401000, 881000, 3381000, 8381000];

        foreach (range(1, 10) as $index => $level) {
            $row = $ladderRow($html, $level);

            assert_same($slots[$index], $number($cell($row, 'slots', $level)), 'slots ' . $level);
            assert_same($apex[$index], $number($cell($row, 'apex', $level)), 'apex threshold ' . $level);
            assert_contains('₱' . number_format($amounts[$index], 2), $cell($row, 'stored', $level), 'amount ' . $level);
            assert_contains('₱' . number_format($cumulative[$index], 2), $cell($row, 'stored-cumulative', $level), 'cumulative ' . $level);

            /* The model and the plan agree on every row. */
            assert_same($slots[$index], GeneralHarvestLevel::slots($level));
            assert_same($apex[$index], GeneralHarvestLevel::apexThreshold($level));
            assert_same((float) $amounts[$index], GeneralHarvestLevel::referencePreset()[$level]);
            assert_same((float) $cumulative[$index], GeneralHarvestLevel::cumulative(GeneralHarvestLevel::referencePreset())[$level]);
        }

        assert_same(8381000.00, GeneralHarvestLevel::total(GeneralHarvestLevel::referencePreset()));

        /* The position table: the reference's §4.5 table is its first four
         * rows and four columns, line for line (§6 Rule 8). */
        assert_same(6, preg_match_all('/data-general-harvest-position="\d+"/', $html), 'six position rows');

        $positions = [
            1 => [4, 13, 40, 121],
            2 => [7, 22, 67, 202],
            3 => [10, 31, 94, 283],
            4 => [13, 40, 121, 364],
            5 => [16, 49, 148, 445],
            6 => [19, 58, 175, 526],
        ];

        foreach ($positions as $position => $cells) {
            $row = $positionRow($html, $position);

            assert_true($row !== '', 'position row ' . $position . ' renders');

            foreach ($cells as $index => $value) {
                assert_same($value, $number($cellAt($row, $position, $index + 1)), 'position ' . $position . ' level ' . ($index + 1));
            }
        }

        /* Rule 7: no board constant is typed into the view. */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/admin/settings/_general_harvest.php');

        foreach (['59049', '88573', '8381000', '147622', '206671', '29524'] as $literal) {
            assert_not_contains($literal, $source, 'the view carries no board literal: ' . $literal);
        }
    },

    'P21B-T21 three registrations after configuring write no board row, no award and no money' => function (): void {
        test_signed_in_as(test_user('p21b_inert_admin', 'admin'));

        /* Configure a real ladder and turn the stream on first. */
        setting_set(GeneralHarvestLevel::ENABLED_KEY, '1');
        setting_set('team_harvest_ledger_since', '2026-11-16 10:00:00');
        setting_set('team_harvest_enabled', '1');
        setting_cache_clear();

        $sponsor      = test_user('p21b_sponsor', 'member');
        $walletBefore = (string) db()->query(
            "SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users"
        )->fetchColumn();
        $boardBefore  = db()->query('SELECT baseline_n, armed_at, settled_n FROM general_harvest_board WHERE id = 1')->fetch();
        $awardBefore  = (int) db()->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn();
        $ledgerBefore = (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest'")->fetchColumn();

        for ($i = 1; $i <= 3; $i++) {
            $username = 'p21b_direct_' . $i . '_' . bin2hex(random_bytes(3));

            $result = (new RegistrationService())->register([
                'mode'             => 'pending',
                'username'         => $username,
                'email'            => $username . '@test.local',
                'full_name'        => 'Harvest Direct ' . $i,
                'phone'            => '',
                'password'         => 'Member@1234',
                'password_confirm' => 'Member@1234',
                'sponsor'          => (string) $sponsor['username'],
                'package_id'       => '',
                'reg_code'         => '',
                'terms'            => true,
            ], null);

            assert_true((int) ($result['user_id'] ?? 0) > 0, 'registration ' . $i . ' succeeded');
        }

        /* Phase 20's hook queues the sponsor; nothing else happens. */
        assert_same(
            1,
            (int) db()->query('SELECT COUNT(*) FROM harvest_queue WHERE user_id = ' . (int) $sponsor['id'])->fetchColumn(),
            'the sponsor is queued by 20C'
        );

        assert_same($boardBefore, db()->query('SELECT baseline_n, armed_at, settled_n FROM general_harvest_board WHERE id = 1')->fetch(), 'the board row is untouched');
        assert_same($awardBefore, (int) db()->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn(), 'no award was written');
        assert_same($ledgerBefore, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest'")->fetchColumn(), 'no wallet movement');
        assert_same(
            $walletBefore,
            (string) db()->query("SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users")->fetchColumn(),
            'the wallet is unchanged'
        );

        /* The board reads N = 1 and the calculator follows it. */
        assert_same(1, GeneralHarvestBoard::queueN()['n']);
        assert_same(0.00, GeneralHarvestLevel::boardTotal(1), 'one position pays nothing');
        assert_same('unarmed', GeneralHarvestBoard::status()['state']);
    },

    /* ==========================================================
     *  The board's state and the four-state strip (P21B-T27)
     * ========================================================== */

    'P21B-T27 the strip renders exactly one of the four states and never claims money' => function () use ($commissions, $block, $armBoard, $disarmBoard, $queueOf): void {
        test_signed_in_as(test_user('p21b_state_admin', 'admin'));

        /** The status strip of the card, up to the next block of the partial. */
        $strip = static function () use ($commissions, $block): string {
            $html  = $block($commissions());
            $start = strpos($html, 'data-general-harvest-status');

            if ($start === false) {
                return '';
            }

            $end = strpos($html, '<div class="row g-4">', $start);

            return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
        };

        /* 1. Unarmed. */
        assert_contains('data-general-harvest-state="unarmed"', $strip());
        assert_contains('Not armed', $strip());
        assert_contains('no baseline has been recorded', $strip());
        assert_contains('bin/general_harvest_arm.php', $strip());
        assert_contains('nothing can pay until the board has a baseline', $strip());
        assert_not_contains('Armed', $strip(), 'an unarmed board never says it is armed');

        /* A queue of 40 positions so the armed states have something to say. */
        $queueOf(40, 'p21b_strip');

        assert_contains('data-general-harvest-state="unarmed"', $strip(), 'a full queue is still unarmed');

        /* 2. Armed and switched off. */
        $armBoard(13);

        assert_contains('data-general-harvest-state="armed_off"', $strip());
        assert_contains('Armed and switched off.', $strip());
        assert_contains('The baseline of 13 positions was recorded on 2026-11-20 09:14:00', $strip());
        assert_contains('Positions are still being recorded', $strip());

        /* 3. Armed, on, nothing waiting: the board is settled to the queue. */
        db()->exec('UPDATE general_harvest_board SET settled_n = 40 WHERE id = 1');
        setting_set(GeneralHarvestLevel::ENABLED_KEY, '1');
        setting_cache_clear();

        assert_contains('data-general-harvest-state="armed_on"', $strip());
        assert_contains('Armed, switch on — nothing is waiting.', $strip());

        /* 4. Armed, on, with unsettled positions. */
        db()->exec('UPDATE general_harvest_board SET settled_n = 13 WHERE id = 1');

        assert_contains('data-general-harvest-state="unsettled"', $strip());
        assert_contains('positions are waiting to be settled', $strip());
        assert_contains('bin/general_harvest_catchup.php', $strip());
        assert_contains('27 positions', $strip(), '40 − 13 positions are waiting');
        assert_contains('₱38,000.00', $strip(), 'and they are worth the closed-form difference');

        $disarmBoard();
        setting_set(GeneralHarvestLevel::ENABLED_KEY, '0');
        setting_cache_clear();

        /* The four states are a contract, in order. */
        assert_same(
            ['unarmed', 'armed_off', 'armed_on', 'unsettled'],
            AdminSettingsController::GENERAL_HARVEST_STATES
        );
        assert_same(AdminSettingsController::GENERAL_HARVEST_STATES, GeneralHarvestBoard::STATES);
    },

    'P21B-T30 the board reads the queue, the frontier and the backlog without a writer' => function () use ($reference, $queueAt, $queueOf): void {
        /* An empty queue is gapless and owes nothing. */
        assert_same(['n' => 0, 'max_id' => 0, 'gapless' => true], GeneralHarvestBoard::queueN());
        assert_same(true, GeneralHarvestBoard::gapless());
        assert_null(GeneralHarvestBoard::positionOf(1));
        assert_null(GeneralHarvestBoard::ownerOf(0));

        /* Build three positions by hand — the position numbers are the ids. */
        $seq = 1;

        foreach (['p21b_node_a', 'p21b_node_b', 'p21b_node_c'] as $username) {
            $user = test_user($username, 'member');

            $queueAt($seq, (int) $user['id']);

            assert_same($seq, GeneralHarvestBoard::positionOf((int) $user['id']), 'position of ' . $username);
            $seq++;
        }

        $queue = GeneralHarvestBoard::queueN();

        assert_same(3, $queue['n']);
        assert_same(3, $queue['max_id']);
        assert_same(true, $queue['gapless']);

        /* Node 1 is position #2. */
        $owner = GeneralHarvestBoard::ownerOf(1);

        assert_same('p21b_node_b', $owner['username']);
        assert_same(2, $owner['position']);
        assert_same(1, $owner['seq']);

        /* At N = 3 nothing is complete; every frontier next_n is the apex's
         * own threshold and nothing is missing. */
        $frontier = GeneralHarvestBoard::frontier();

        assert_count(10, $frontier);
        assert_same(0, $frontier[1]['rows']);
        assert_same(0, $frontier[1]['complete']);
        assert_same(0, $frontier[1]['missing']);
        assert_same(4, $frontier[1]['next_n']);
        assert_same(2000.0, $frontier[1]['amount']);
        assert_same(88573, $frontier[10]['next_n']);

        /* Add 37 positions: node 0's levels 1–3 and nodes 1–3's levels 1–2
         * are complete (the 40-qualified board, §12.8 D). */
        for ($position = 4; $position <= 40; $position++) {
            $queueAt($position, (int) test_user('p21b_fill_' . $position, 'member')['id'], '2026-11-16 10:01:00');
        }

        $frontier = GeneralHarvestBoard::frontier();

        assert_same(40, GeneralHarvestBoard::queueN()['n']);
        assert_same(0, $frontier[1]['rows'], 'no award row exists yet');
        assert_same(13, $frontier[1]['complete'], 'level 1 has 13 complete blocks at N = 40');
        assert_same(13, $frontier[1]['missing'], 'and 13 rows are missing');
        assert_same(4, $frontier[1]['next_n'], 'the first unwritten node needs N = 4');
        assert_same(4, $frontier[2]['complete'], 'nodes 0–3 complete level 2 at N = 40');
        assert_same(1, $frontier[3]['complete'], 'only node 0 completes level 3');

        /* Two level-1 rows written by hand move the frontier: the next
         * unwritten node is node 2, which needs N = 10. */
        $queueRows = db()->query('SELECT id, user_id FROM harvest_queue ORDER BY id LIMIT 2')->fetchAll();

        foreach ($queueRows as $index => $row) {
            db()->prepare(
                'INSERT INTO general_harvest_awards
                   (seq, user_id, level, threshold_n, n_at_award, amount, status, credited_at)
                 VALUES (?, ?, 1, ?, 40, 2000.00, "baseline", ?)'
            )->execute([$index, (int) $row['user_id'], 4, '2026-11-20 09:14:00']);
        }

        $frontier = GeneralHarvestBoard::frontier();

        assert_same(2, $frontier[1]['rows']);
        assert_same(11, $frontier[1]['missing']);
        assert_same(10, $frontier[1]['next_n'], 'node 2 level 1 completes at N = 10');

        /* The backlog of an unarmed board is zero even with a full queue. */
        $backlog = GeneralHarvestBoard::backlog();

        assert_same(false, $backlog['armed']);
        assert_same(0, $backlog['positions']);
        assert_same(0.0, $backlog['amount']);

        /* One node's ten blocks at N = 40. */
        $blocks = GeneralHarvestBoard::blocks(0, 40);

        assert_count(10, $blocks);
        assert_same(3, $blocks[1]['filled']);
        assert_same(true, $blocks[1]['complete']);
        assert_same(2000.0, $blocks[1]['earned']);
        assert_same(2, $blocks[1]['from']);
        assert_same(4, $blocks[1]['to']);
        assert_same(4, $blocks[1]['threshold']);
        assert_same(27, $blocks[3]['filled']);
        assert_same(true, $blocks[3]['complete']);
        assert_same(0, $blocks[4]['filled']);
        assert_same(false, $blocks[4]['complete']);
        assert_same(0.0, $blocks[4]['earned']);
        assert_same(true, $blocks[10]['paying'], 'the default depth pays level 10');

        /* A page of the board's structure at level 1: 13 complete nodes. */
        $page = GeneralHarvestBoard::blockRange(1, 0, 5);

        assert_same(13, $page['total']);
        assert_count(5, $page['rows']);
        assert_same(0, $page['rows'][0]['seq']);
        assert_same(1, $page['rows'][0]['position']);
        assert_same('p21b_node_a', $page['rows'][0]['username']);
        assert_same(2000.0, $page['rows'][0]['amount']);
        assert_same(4, $page['rows'][0]['threshold']);
        assert_same(10, GeneralHarvestBoard::blockRange(1, 10, 50)['rows'][0]['seq'], 'the page starts at the offset');
        assert_same(3, count(GeneralHarvestBoard::blockRange(1, 10, 50)['rows']), '13 complete nodes leave 3 past an offset of 10');

        /* The frontier's amount is the configured one, and the ladder is what
         * boardTotal() reads. */
        assert_same(GeneralHarvestLevel::amount(1), $frontier[1]['amount']);
        assert_same(50000.00, GeneralHarvestLevel::boardTotal(40, $reference()));
        assert_same(50000.00, GeneralHarvestLevel::boardTotal(40));
    },

    'P21B-T31 an armed board reports its backlog and its settled length' => function () use ($armBoard, $disarmBoard, $reference, $queueOf): void {
        $queueOf(40, 'p21b_armed');

        /* Armed at 13, settled to 13, queue at 40: 27 positions waiting. */
        $armBoard(13);
        db()->exec('UPDATE general_harvest_board SET settled_n = 13 WHERE id = 1');
        setting_set(GeneralHarvestLevel::ENABLED_KEY, '1');
        setting_cache_clear();

        $backlog = GeneralHarvestBoard::backlog();

        assert_same(true, $backlog['armed']);
        assert_same(40, $backlog['n']);
        assert_same(13, $backlog['settled_n']);
        assert_same(27, $backlog['positions']);
        assert_same(
            GeneralHarvestLevel::boardTotal(40, $reference()) - GeneralHarvestLevel::boardTotal(13, $reference()),
            $backlog['amount'],
            'the backlog is worth the closed-form difference'
        );
        assert_same(38000.00, $backlog['amount'], '§12.8 F: 50,000 − 12,000');

        $status = GeneralHarvestBoard::status();

        assert_same('unsettled', $status['state']);
        assert_same(13, $status['baseline_n']);
        assert_same(27, $status['backlog']);
        assert_same(40, $status['n']);

        /* Nothing was written: the board holds state, not money. */
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM general_harvest_awards')->fetchColumn());
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'general_harvest'")->fetchColumn());

        $disarmBoard();
        setting_set(GeneralHarvestLevel::ENABLED_KEY, '0');
        setting_cache_clear();

        assert_same('unarmed', GeneralHarvestBoard::status()['state']);
    },

    'P21B-T32 the model ranges, the zero-filled reads and centavo exactness' => function (): void {
        /* A level outside 1 … 10 reads ₱0.00; a threshold(0) throws. */
        assert_same(0.0, GeneralHarvestLevel::amount(0));
        assert_same(0.0, GeneralHarvestLevel::amount(11));

        foreach ([0, 11] as $level) {
            assert_throws(static fn () => GeneralHarvestLevel::slots($level), InvalidArgumentException::class);
            assert_throws(static fn () => GeneralHarvestLevel::paid($level, 10), InvalidArgumentException::class);
        }

        assert_throws(static fn () => GeneralHarvestLevel::threshold(-1, 1), InvalidArgumentException::class, 'a negative node is refused');

        /* A missing row reads 0.00 and the total stays exact. */
        db()->exec('DELETE FROM general_harvest_levels WHERE level = 6');

        assert_same(0.0, GeneralHarvestLevel::amount(6), 'a missing row is ₱0.00');
        assert_same(8281000.00, GeneralHarvestLevel::total(), 'the truncated ladder total');
        assert_same(100000.0, GeneralHarvestLevel::referencePreset()[6], 'the preset is not affected by the rows');

        /* The table can be empty: ten zeroes, never nine keys. */
        db()->exec('DELETE FROM general_harvest_levels');

        $empty = GeneralHarvestLevel::amounts();

        assert_count(10, $empty);
        assert_same(array_fill(1, 10, 0.0), $empty);
        assert_same(0.0, GeneralHarvestLevel::total());
        assert_same(0.0, GeneralHarvestLevel::boardTotal(121), 'an unpriced ladder owes nothing');
        assert_same(58, GeneralHarvestLevel::awardCount(121), 'the count is structural, not monetary');

        /* One query per bulk read (the 20B idiom). */
        $questions = static function (): int {
            $row = db()->query('SHOW SESSION STATUS LIKE "Questions"')->fetch();

            return (int) ($row['Value'] ?? 0);
        };

        $before = $questions();
        $amounts = GeneralHarvestLevel::amounts();
        $after  = $questions();

        assert_same(2, $after - $before, 'amounts() runs one statement (plus the counter read)');
        assert_same(range(1, 10), array_keys($amounts));
    },

    'P21B-T25 the phase adds no schema: 39 tables, 76 seed settings, ten ladder rows' => function () use ($appRoot): void {
        /* 21B is configuration and arithmetic only: the schema is exactly
         * 21A's, and the release phase (21G) is the one that moves APP_PHASE. */
        assert_same(39, count(test_table_list(test_pdo(['db' => DB_NAME]))));
        assert_same(76, (int) db()->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM general_harvest_levels')->fetchColumn());
        assert_same(1, (int) db()->query('SELECT COUNT(*) FROM general_harvest_board')->fetchColumn());
        assert_same(20, APP_PHASE, '21G owns the release badge');

        /* install.sql is untouched by this phase and still matches its sources. */
        $install = (string) file_get_contents($appRoot . '/database/install.sql');

        assert_contains('39 tables', $install);
        assert_contains('79 settings', $install);
        assert_contains('10 General Harvest level amounts', $install);

        /* The new card owns no route (D-15) and no arm button (Rule 13). */
        foreach (['/app/routes.php', '/app/routes_inventory.php'] as $file) {
            assert_same(0, preg_match('/general_harvest/i', (string) file_get_contents($appRoot . $file)), $file . ' has no General Harvest route');
        }

        /* 21C adds the arm job and the ledger's baseline writer; the
         * catch-up (21D) and every page above the card (21E/21F) do not
         * exist yet, and this phase still changes no schema. */
        assert_true(is_file($appRoot . '/bin/general_harvest_arm.php'), 'the arm job is 21C');
        assert_true(is_file($appRoot . '/bin/general_harvest_catchup.php'), 'the catch-up job is 21D');
        assert_false(is_file($appRoot . '/app/Controllers/GeneralHarvestController.php'), 'the pages are 21E/21F');
    },

    'P21B-T33 the card is the only place the phase renders and no other card owns a key' => function () use ($sectionMethod, $ghKeys): void {
        /* The General Harvest configuration is on the Commissions card only. */
        foreach (['general', 'shop', 'accounts', 'payouts', 'windows', 'fulfilment', 'transfers'] as $section) {
            foreach (array_merge($sectionMethod('sectionKeys', $section), $sectionMethod('sectionToggles', $section)) as $key) {
                assert_false(str_starts_with($key, 'general_harvest_'), $section . ' owns ' . $key);
            }
        }

        /* One depth + ten amounts = eleven keys, and one switch. */
        assert_same(11, count($ghKeys()), 'one knob and ten amounts');
        assert_true(in_array('general_harvest_max_levels', $ghKeys(), true));
        assert_true(in_array('general_harvest_amounts.1', $ghKeys(), true));
        assert_true(in_array('general_harvest_amounts.10', $ghKeys(), true));

        assert_same(['general_harvest_enabled'], array_values(array_filter(
            $GLOBALS['__p21bToggles'],
            static fn (string $key): bool => str_starts_with($key, 'general_harvest_')
        )));

        $rules = $sectionMethod('sectionRules', 'commissions');

        assert_same('required|integer|gte:1|lte:10', $rules['general_harvest_max_levels']);
        assert_same(GeneralHarvestLevel::AMOUNT_RULE, $rules['general_harvest_amounts.1']);

        /* There is deliberately no baseline key (§13.5). */
        assert_false(array_key_exists('general_harvest_baseline_n', $rules));
        assert_same(
            0,
            (int) db()->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'general\\_harvest%' AND key_name NOT IN ('general_harvest_enabled', 'general_harvest_max_levels')")->fetchColumn(),
            'the amounts are not settings'
        );

        /* The audit action and the state list are stable strings. */
        assert_same('general_harvest.ladder.update', 'general_harvest.ladder.update');
        assert_same('armed', GeneralHarvestLevel::ARMED_KEY);
        assert_same('general_harvest_amounts.sum', GeneralHarvestLevel::SUM_KEY);
    },

    'P21B-T35 a card carrying null placeholders for the absent ladder keys keeps the ladder' => function () use ($posted): void {
        /* `save()` reads every key `sectionKeys()` names, so a field the form
         * did not post used to arrive as null. A null is the **absence** of
         * the field, not a blank amount: counted as a submission it cleared
         * the operator's ten rows and audited the wipe (Rule 1, D-11). The
         * smoke walk found it — its Commissions saves post neither ladder. */
        test_signed_in_as(test_user('p21b_nullkeys_admin', 'admin'));

        $stored = GeneralHarvestLevel::amounts();
        $before = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        $payload = $posted();

        for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
            $payload[GeneralHarvestLevel::AMOUNT_FIELD . '.' . $level] = null;
        }

        $result = (new AdminSettingsController())->applySection('commissions', $payload);

        assert_same([], $result['errors'], 'a null is the absence of the field, not a refused value');
        assert_same($stored, GeneralHarvestLevel::amounts(), 'the ten rows survive the save');
        assert_same(
            $before,
            (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn(),
            'nothing is written and nothing is audited'
        );
    },
];
