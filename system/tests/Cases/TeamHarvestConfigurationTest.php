<?php
declare(strict_types=1);

/* ==============================================================
 *  TeamHarvestConfigurationTest — Phase 20B (plan §6, §12.6,
 *  §12.10, §13.3/§13.5, Appendix A)
 *
 *  Proves the configuration half of 20B on its own: the Team
 *  Harvest sub-block of /admin/settings → Commissions, its field
 *  catalogue, every validation rule, the atomic save, the audit row
 *  and the generated ladder table. Nothing here writes a queue row,
 *  an award or a wallet movement — 20C and 20D do that.
 *
 *      card        the sub-block, its switch, two knobs, ten
 *                  amounts, the four-state strip and the saved ladder
 *      defaults    the Appendix A amounts and the 3/10 knobs
 *      validation  money precision and ceilings, the sum guard,
 *                  enabling a zero ladder, the two small integers
 *      save        a real applySection() round-trip, ten rows, the
 *                  `team_harvest.ladder.update` audit row
 *      ladder      slots 3^L, thresholds (3^(L+1)-3)/2, cumulative,
 *                  the full-ladder total and the depth marking
 *      model       amount()/threshold() ranges, zero-filled reads,
 *                  one query per bulk read
 *      inert       three registrations write no queue row, no award,
 *                  no money; an award keeps its snapshot
 *
 *  Run this group alone with:  php tests/run.php TeamHarvestConfiguration
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/** How many statements the connection has run in this session. */
$questions = static function (): int {
    $row = db()->query('SHOW SESSION STATUS LIKE "Questions"')->fetch();

    return (int) ($row['Value'] ?? 0);
};

/** Render the settings page from the controller's real context. */
$renderPage = static function (array $overrides = []): string {
    $controller = new AdminSettingsController();
    $data       = $overrides + $controller->settingsContext() + [
        'page_title'  => 'Settings',
        'current_nav' => 'admin.settings',
        'page_css'    => ['assets/css/orders.css'],
        'page_js'     => ['assets/js/admin-live.js', 'assets/js/admin-settings.js', 'assets/js/app-team-harvest.js'],
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

/** The Team Harvest sub-block of the Commissions card. */
$block = static function (string $html): string {
    if (preg_match('/<div\b[^>]*data-team-harvest-card[^>]*>(.*?)(?:<hr\b|\z)/s', $html, $m) === 1) {
        return $m[1];
    }

    return '';
};

/** The one generated table row for a level (`0` when it is absent). */
$ladderRow = static function (string $html, int $level): string {
    $pattern = '/<tr\b[^>]*data-team-harvest-row="' . $level . '"[^>]*>(.*?)<\/tr>/s';

    return preg_match($pattern, $html, $m) === 1 ? $m[0] : '';
};

/** Call a private static method on the settings controller. */
$sectionMethod = static function (string $method, string $section): array {
    $reflection = new ReflectionMethod(AdminSettingsController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $section);
};

/** The value of one `data-team-harvest-*` cell inside a table row. */
$cell = static function (string $row, string $attribute, int $level): string {
    $pattern = '/data-team-harvest-' . preg_quote($attribute, '/') . '="' . $level . '"[^>]*>(.*?)</s';

    return preg_match($pattern, $row, $m) === 1 ? trim(strip_tags($m[1])) : '';
};

/**
 * Every key the Commissions card owns, read from the stored state — the
 * same payload the browser posts when nothing is edited.
 *
 * @return array<string, string>
 */
$baseline = static function (): array {
    $settings = setting_all();
    $form     = TeamHarvestLevel::forForm(TeamHarvestLevel::amounts());
    $posted   = [];

    foreach (array_merge($GLOBALS['__p20bKeys'], $GLOBALS['__p20bToggles']) as $key) {
        if (str_starts_with($key, TeamHarvestLevel::AMOUNT_FIELD . '.')) {
            $level        = (int) substr($key, strlen(TeamHarvestLevel::AMOUNT_FIELD . '.'));
            $posted[$key] = (string) ($form[$level] ?? '');
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

/** The ladder rows as `level => amount` (floats), read from the database. */
$storedLadder = static function (): array {
    return TeamHarvestLevel::amounts();
};

/** The reference ladder in level order (Appendix A). */
$reference = static function (): array {
    return TeamHarvestLevel::referencePreset();
};

/** The keys and switches of the Commissions card, in catalogue order. */
$GLOBALS['__p20bKeys']     = [];
$GLOBALS['__p20bToggles']  = [];

$fieldKeys = new ReflectionMethod(AdminSettingsController::class, 'sectionKeys');
$fieldKeys->setAccessible(true);
$GLOBALS['__p20bKeys'] = $fieldKeys->invoke(null, 'commissions');

$toggleKeys = new ReflectionMethod(AdminSettingsController::class, 'sectionToggles');
$toggleKeys->setAccessible(true);
$GLOBALS['__p20bToggles'] = $toggleKeys->invoke(null, 'commissions');

/** The Team Harvest keys inside the Commissions catalogue. */
$teamKeys = static fn (): array => array_values(array_filter(
    $GLOBALS['__p20bKeys'],
    static fn (string $key): bool => str_starts_with($key, 'team_harvest_')
));

return [
    /* ==========================================================
     *  The card (P20B-T1, T6, T9, T16, T18, T19)
     * ========================================================== */

    'P20B-T1 the card on a fresh database shows the reference ladder, the knobs and the unseeded strip' => function () use ($commissions, $block): void {
        test_signed_in_as(test_user('p20b_card_admin', 'admin'));

        $card = $commissions();
        $html = $block($card);

        assert_true($card !== '', 'the Commissions form renders');
        assert_true($html !== '', 'the Team Harvest sub-block renders');

        assert_contains('Team Harvest bonus', $html, 'the sub-block heading');
        assert_contains('Levels 1–10 · 3×10 matrix', $html);
        assert_contains('3×10 matrix', $html, 'the card explains the matrix');
        assert_contains('never depends on purchases, rank or the', $html);

        /* The switch, off. */
        assert_contains('name="team_harvest_enabled"', $html);
        assert_contains('Enable the Team Harvest bonus', $html);

        preg_match('/<input\b[^>]*name="team_harvest_enabled"[^>]*>/', $html, $toggle);
        assert_true($toggle !== [], 'the switch renders');
        assert_not_contains('checked', $toggle[0], 'the stream ships off');

        /* The two knobs. */
        assert_contains('name="team_harvest_qualify_directs"', $html);
        assert_contains('name="team_harvest_max_levels"', $html);
        assert_contains('Direct members needed to qualify', $html);
        assert_contains('Levels that pay', $html);

        preg_match('/<input\b[^>]*name="team_harvest_qualify_directs"[^>]*>/', $html, $gate);
        assert_true($gate !== []);
        assert_contains('value="3"', $gate[0], 'the gate reads the reference 3');
        assert_contains('min="1"', $gate[0]);
        assert_contains('max="10"', $gate[0]);
        assert_contains('step="1"', $gate[0]);

        preg_match('/<input\b[^>]*name="team_harvest_max_levels"[^>]*>/', $html, $depth);
        assert_true($depth !== []);
        assert_contains('value="10"', $depth[0], 'the depth reads the reference 10');

        /* Ten amount inputs, each once, each a money input. */
        $inputs = 0;

        foreach ([1000.00, 2000.00, 4000.00, 6000.00, 12000.00, 50000.00, 120000.00, 200000.00, 1200000.00, 3500000.00] as $index => $amount) {
            $level = $index + 1;

            assert_same(
                1,
                substr_count($html, 'name="team_harvest_amounts[' . $level . ']"'),
                'level ' . $level . ' input appears exactly once'
            );

            preg_match('/<input\b[^>]*name="team_harvest_amounts\[' . $level . '\]"[^>]*>/', $html, $input);
            assert_true($input !== [], 'level ' . $level . ' input exists');
            assert_contains('type="number"', $input[0]);
            assert_contains('min="0"', $input[0]);
            assert_contains('max="10000000.00"', $input[0]);
            assert_contains('step="0.01"', $input[0]);
            assert_contains('value="' . number_format($amount, 2, '.', '') . '"', $input[0], 'the stored reference amount');
            assert_contains('data-reference="' . number_format($amount, 2, '.', '') . '"', $input[0], 'the preset rides on the input');

            $inputs++;
        }

        assert_same(10, $inputs, 'ten amount inputs');

        /* The baseline is never an input (D-04). */
        assert_not_contains('team_harvest_ledger_since', $html);

        /* The strip says unseeded and names the seed job. */
        assert_contains('data-team-harvest-state="unseeded"', $html);
        assert_contains('Not seeded', $html);
        assert_contains('bin/harvest_queue_seed.php', $html, 'the next action is the seed job');
        assert_contains('nothing can pay until the queue exists', $html);

        /* The full-ladder total and the per-member maximum in one sentence. */
        assert_contains('₱5,095,000.00', $html);
        assert_contains('88,572', $html);
        assert_contains('per-member maximum', $html);
        assert_contains('ceiling ₱25,000,000.00', $html, 'the typo guard is on the card');

        /* The reference preset is offered, never applied by itself. */
        assert_contains('Load reference ladder', $html);
        assert_contains('Clear', $html);
        assert_contains('data-team-harvest-preset', $html);
        assert_contains('data-team-harvest-clear', $html);
        assert_contains('₱5,095,000.00 in total across the ten levels', $html);
    },

    'P20B-T23 the card states the two rules an operator must not have to guess' => function () use ($commissions, $block): void {
        test_signed_in_as(test_user('p20b_copy_admin', 'admin'));

        $html = $block($commissions());

        assert_contains('every award keeps the amount', $html, 'Rule 10 in one line');
        assert_contains('it was paid at', $html);
        assert_contains('generated from the', $html);
        assert_contains("level's place in the matrix", $html);
        assert_contains('not settings', $html);
        assert_contains('stored now; the inputs above are what the next save writes', $html);
        assert_contains('Levels above the configured depth complete on the board', $html);
        assert_contains('no award is written by this card', $html, 'the card tells the truth about 20B');
    },

    'P20B-T16 an enabled stream with an empty baseline still reads not seeded and still saves' => function () use ($commissions, $apply): void {
        test_signed_in_as(test_user('p20b_unseeded_admin', 'admin'));

        setting_set('team_harvest_enabled', '1');
        setting_cache_clear();

        $card = $commissions();

        assert_contains('data-team-harvest-state="unseeded"', $card, 'on is not the same as seeded');
        assert_contains('bin/harvest_queue_seed.php', $card);
        assert_not_contains('nothing is waiting', $card);

        /* Saving still works with the switch on and the queue unseeded. */
        $result = $apply(['team_harvest_max_levels' => '9']);

        assert_same([], $result['errors'], 'the save validates');
        assert_same('9', setting('team_harvest_max_levels'));
    },

    'P20B-T18 the card stacks its ten inputs and scrolls the table inside the card' => function () use ($commissions, $block): void {
        test_signed_in_as(test_user('p20b_mobile_admin', 'admin'));

        $html = $block($commissions());

        /* Each amount input sits in its own col-12 grid cell, so the ten
         * inputs stack on a phone instead of widening the page. */
        assert_same(
            10,
            preg_match_all('/<div class="col-12 col-md-6" data-team-harvest-level="\d+">/', $html),
            'one stacking grid cell per level'
        );

        /* The six-column read-only table scrolls inside its own wrapper. */
        preg_match('/<div class="table-responsive">\s*<table\b[^>]*data-team-harvest-table/s', $html, $wrapper);
        assert_true($wrapper !== [], 'the ladder table is inside .table-responsive');

        /* No page-level fixed width comes from this block. */
        assert_not_contains('min-width: 1200px', $html);
        assert_not_contains('overflow-x: hidden', $html);
    },

    'P20B-T19 the card is server-rendered with JavaScript off' => function () use ($renderPage, $commissions, $block, $ladderRow, $cell): void {
        test_signed_in_as(test_user('p20b_nojs_admin', 'admin'));

        $html = $block($commissions());

        /* Every derived number is already in the HTML: the live script only
         * repeats them while the operator types (Rule 12). */
        foreach (range(1, 10) as $level) {
            $row = $ladderRow($html, $level);

            assert_true($row !== '', 'level ' . $level . ' row is server-rendered');
            assert_true($cell($row, 'stored', $level) !== '', 'the stored amount is printed, not computed by JS');
            assert_true($cell($row, 'stored-cumulative', $level) !== '', 'the stored cumulative is printed');
        }

        /* The cumulative preview also starts from the server value. */
        assert_contains('data-team-harvest-cumulative="10">₱5,095,000.00', $html);

        /* The form is a plain POST form: no JS-only required marker and no
         * submit handler is needed for it to save. */
        assert_contains('<form', (string) $renderPage());
        assert_contains('name="_csrf"', (string) $renderPage());
        assert_not_contains('onsubmit', $html);
        assert_not_contains('data-team-harvest-required', $html);
    },

    /* ==========================================================
     *  Validation (P20B-T4 … T9)
     * ========================================================== */

    'P20B-T4 a ladder totalling more than 25,000,000.00 is a sum error and writes nothing' => function () use ($validate, $apply, $storedLadder): void {
        test_signed_in_as(test_user('p20b_sum_admin', 'admin'));

        /* Three levels at the per-level ceiling: ₱30,195,000.00 in total,
         * each amount legal on its own (plan P20B-T4). */
        $over = [
            'team_harvest_amounts.8'  => '10000000.00',
            'team_harvest_amounts.9'  => '10000000.00',
            'team_harvest_amounts.10' => '10000000.00',
        ];

        $errors = $validate($over);

        assert_true(isset($errors[TeamHarvestLevel::SUM_KEY]), 'the sum guard is a field error');
        assert_contains('cannot exceed ₱25,000,000.00', $errors[TeamHarvestLevel::SUM_KEY]);
        assert_contains('₱30,195,000.00', $errors[TeamHarvestLevel::SUM_KEY], 'the message names the typed total');

        $auditBefore = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
        $result      = $apply($over);

        assert_true(isset($result['errors'][TeamHarvestLevel::SUM_KEY]), 'the write path refuses it too');
        assert_same([], $result['changes'], 'no setting was written');
        assert_null($result['ladder'], 'no ladder change set was produced');
        assert_same(4000.00, $storedLadder()[3], 'the stored ladder is unchanged');
        assert_same(3500000.00, $storedLadder()[10], 'the level that was typed is unchanged');
        assert_same($auditBefore, (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn(), 'no audit row');
    },

    'P20B-T5 an amount above the 10,000,000.00 ceiling is a field error on that level' => function () use ($validate): void {
        test_signed_in_as(test_user('p20b_ceiling_admin', 'admin'));

        $errors = $validate(['team_harvest_amounts.4' => '10000000.01']);

        assert_true(isset($errors['team_harvest_amounts.4']), 'the level itself is the field');
        assert_contains('Level 4 amount must be at most 10000000', $errors['team_harvest_amounts.4']);

        /* Exactly the ceiling is accepted when the rest is zero. */
        $zero = [];

        foreach (range(1, 10) as $level) {
            $zero['team_harvest_amounts.' . $level] = '0';
        }

        assert_same([], $validate($zero + ['team_harvest_amounts.4' => '10000000.00']), 'exactly the ceiling is accepted');

        /* A negative amount is refused too. */
        assert_true(isset($validate(['team_harvest_amounts.2' => '-0.01'])['team_harvest_amounts.2']));
    },

    'P20B-T6 four decimals or text is refused, two decimals is accepted' => function () use ($validate): void {
        test_signed_in_as(test_user('p20b_decimal_admin', 'admin'));

        foreach (['1000.1234', '1,000.00', 'abc'] as $value) {
            $errors = $validate(['team_harvest_amounts.3' => $value]);
            assert_true(isset($errors['team_harvest_amounts.3']), 'refused: ' . $value);
        }

        assert_contains(
            'must be a number with at most 2 decimal places',
            $validate(['team_harvest_amounts.3' => '1000.1234'])['team_harvest_amounts.3']
        );

        assert_same([], $validate(['team_harvest_amounts.3' => '1000.12']));
        assert_same([], $validate(['team_harvest_amounts.3' => '1000.1']));
        assert_same([], $validate(['team_harvest_amounts.3' => '']), 'blank means 0');
        assert_same([], $validate(['team_harvest_amounts.3' => '0']));
    },

    'P20B-T7 enabling the stream with an all-zero ladder is refused on the switch' => function () use ($validate, $apply): void {
        test_signed_in_as(test_user('p20b_zero_admin', 'admin'));

        $zero = ['team_harvest_enabled' => '1'];

        foreach (range(1, 10) as $level) {
            $zero['team_harvest_amounts.' . $level] = '0';
        }

        $errors = $validate($zero);

        assert_true(isset($errors['team_harvest_enabled']), 'the refusal lands on the switch');
        assert_same(
            'Turn the switch off or give at least one level an amount above zero.',
            $errors['team_harvest_enabled']
        );

        /* The same all-zero ladder with the switch off is a valid save. */
        assert_same([], $validate(array_merge($zero, ['team_harvest_enabled' => '0'])));

        /* One amount above zero is enough. */
        assert_same([], $validate(array_merge($zero, ['team_harvest_amounts.1' => '0.01'])));

        /* The refused save leaves the switch off and the ladder intact. */
        $auditBefore = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
        $result      = $apply($zero);

        assert_true(isset($result['errors']['team_harvest_enabled']));
        assert_same('0', setting('team_harvest_enabled'), 'the stored switch is unchanged');
        assert_same($auditBefore, (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn());
    },

    'P20B-T8 the gate is a whole number 1–10 and a refusal keeps the previous value' => function () use ($validate, $apply): void {
        test_signed_in_as(test_user('p20b_gate_admin', 'admin'));

        foreach (['0', '11', '2.5', '-1', 'abc', ''] as $value) {
            $errors = $validate(['team_harvest_qualify_directs' => $value]);
            assert_true(isset($errors['team_harvest_qualify_directs']), 'refused: ' . var_export($value, true));
        }

        assert_contains(
            'must be a whole number',
            $validate(['team_harvest_qualify_directs' => '2.5'])['team_harvest_qualify_directs']
        );

        foreach (['1', '3', '10'] as $value) {
            assert_same([], $validate(['team_harvest_qualify_directs' => $value]), 'accepted: ' . $value);
        }

        $result = $apply(['team_harvest_qualify_directs' => '11']);

        assert_true(isset($result['errors']['team_harvest_qualify_directs']));
        assert_same('3', setting('team_harvest_qualify_directs'), 'the previous gate survives');

        $result = $apply(['team_harvest_qualify_directs' => '5']);

        assert_same([], $result['errors']);
        assert_same('5', setting('team_harvest_qualify_directs'));
        assert_same(5, TeamHarvestLevel::qualifyDirects());
    },

    'P20B-T9 the depth is a whole number 1–10' => function () use ($validate, $apply): void {
        test_signed_in_as(test_user('p20b_depth_admin', 'admin'));

        foreach (['0', '11', '3.5', 'abc'] as $value) {
            assert_true(
                isset($validate(['team_harvest_max_levels' => $value])['team_harvest_max_levels']),
                'refused: ' . $value
            );
        }

        assert_same([], $validate(['team_harvest_max_levels' => '1']));
        assert_same([], $validate(['team_harvest_max_levels' => '10']));

        $result = $apply(['team_harvest_max_levels' => '0']);

        assert_true(isset($result['errors']['team_harvest_max_levels']));
        assert_same('10', setting('team_harvest_max_levels'), 'the previous depth survives');
    },

    /* ==========================================================
     *  Save, audit and the atomic refusal (P20B-T2, T3, T10, T14, T17)
     * ========================================================== */

    'P20B-T2 saving the reference ladder and the three knobs writes ten rows and one audit row' => function () use ($apply, $storedLadder, $reference): void {
        $admin = test_user('p20b_save_admin', 'admin');
        test_signed_in_as($admin);

        assert_same('0', setting('team_harvest_enabled'));
        $auditBefore = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        $result = $apply([
            'team_harvest_enabled'         => '1',
            'team_harvest_qualify_directs' => '4',
            'team_harvest_max_levels'      => '9',
        ]);

        assert_same([], $result['errors'], 'the save validates');
        assert_same('1', setting('team_harvest_enabled'), 'the switch persisted');
        assert_same('4', setting('team_harvest_qualify_directs'), 'the gate persisted');
        assert_same('9', setting('team_harvest_max_levels'), 'the depth persisted');
        assert_same('', setting('team_harvest_ledger_since'), 'the baseline is never written by the card');

        /* Ten rows, still exactly the reference amounts. */
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn());
        assert_same($reference(), $storedLadder(), 'the stored ladder is the reference preset');
        assert_same(1000.00, TeamHarvestLevel::amount(1));
        assert_same(5095000.00, TeamHarvestLevel::total());
        assert_same(4, TeamHarvestLevel::qualifyDirects());
        assert_same(9, TeamHarvestLevel::maxLevels());
        assert_true(TeamHarvestLevel::enabled());

        /* One ladder audit row with the whole before/after payload. */
        $stmt = db()->prepare(
            "SELECT action, actor_id, subject_type, meta_json FROM audit_log
              WHERE action = 'team_harvest.ladder.update' AND id > ? ORDER BY id"
        );
        $stmt->execute([$auditBefore]);
        $rows = $stmt->fetchAll();

        assert_count(1, $rows, 'exactly one ladder audit row');
        assert_same('settings', (string) $rows[0]['subject_type']);
        assert_same((int) $admin['id'], (int) $rows[0]['actor_id']);

        $meta = json_decode((string) $rows[0]['meta_json'], true);

        assert_same(false, $meta['before']['enabled']);
        assert_same(true, $meta['after']['enabled']);
        assert_same(3, $meta['before']['qualify_directs']);
        assert_same(4, $meta['after']['qualify_directs']);
        assert_same(10, $meta['before']['max_levels']);
        assert_same(9, $meta['after']['max_levels']);
        assert_same('1000.00', $meta['after']['levels'][1]);
        assert_same('3500000.00', $meta['after']['levels'][10]);
        assert_same(false, $meta['changes']['enabled']['from']);
        assert_same(true, $meta['changes']['enabled']['to']);
        assert_same(3, $meta['changes']['qualify_directs']['from']);
        assert_same(4, $meta['changes']['qualify_directs']['to']);

        /* The tenant-wide settings trail also names the moved keys (§15.1). */
        $settingsRow = db()->prepare(
            "SELECT subject_type FROM audit_log WHERE action = 'settings.update' AND id > ?"
        );
        $settingsRow->execute([$auditBefore]);
        assert_same('settings', (string) $settingsRow->fetchColumn());

        /* The saved ladder stamps its actor on all ten rows. */
        $actors = db()->query('SELECT COUNT(*) FROM team_harvest_levels WHERE updated_by = ' . (int) $admin['id'])->fetchColumn();
        assert_same(10, (int) $actors, 'ten rows carry the actor');

        /* Saving the same values again writes no audit row at all. */
        $againBefore = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
        $again       = $apply([]);

        assert_same([], $again['changes'], 'nothing moved');
        assert_same(false, $again['ladder']['changed'], 'the ladder did not move');
        assert_same($againBefore, (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn(), 'no audit row for an unchanged save');
    },

    'P20B-T3 three blank amounts are saved as 0.00 and render blank again' => function () use ($apply, $storedLadder, $commissions, $block): void {
        test_signed_in_as(test_user('p20b_blank_admin', 'admin'));

        $result = $apply([
            'team_harvest_amounts.2' => '',
            'team_harvest_amounts.5' => '',
            'team_harvest_amounts.9' => '',
        ]);

        assert_same([], $result['errors']);
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn(), 'ten rows, never nine');

        $ladder = $storedLadder();

        assert_same(0.0, $ladder[2]);
        assert_same(0.0, $ladder[5]);
        assert_same(0.0, $ladder[9]);
        assert_same(1000.0, $ladder[1], 'the other levels are untouched');

        assert_same(0.0, TeamHarvestLevel::amount(2));

        /* The card shows blanks for the zero levels. */
        $html = $block($commissions());

        preg_match('/<input\b[^>]*name="team_harvest_amounts\[2\]"[^>]*>/', $html, $second);
        assert_true($second !== []);
        assert_contains('value=""', $second[0], 'a zero renders as a blank input');
    },

    'P20B-T10 a depth of 3 is saved and levels 4–10 are marked not paid with their amounts kept' => function () use ($apply, $commissions, $block, $ladderRow): void {
        test_signed_in_as(test_user('p20b_notpaid_admin', 'admin'));

        $result = $apply(['team_harvest_max_levels' => '3']);

        assert_same([], $result['errors']);
        assert_same('3', setting('team_harvest_max_levels'));
        assert_same(3, TeamHarvestLevel::maxLevels());

        $html = $block($commissions());

        foreach (range(1, 10) as $level) {
            $row = $ladderRow($html, $level);
            assert_true($row !== '');

            $paying = str_contains($row, 'data-team-harvest-paying="1"');
            assert_same($level <= 3, $paying, 'level ' . $level . ' paying flag');
        }

        assert_same(7, preg_match_all('/Not paid at this depth/', $html), 'levels 4–10 are marked');
        assert_contains('Not paid</span>', $ladderRow($html, 4));

        /* The amounts are kept: ten rows, level 10 still ₱3,500,000.00. */
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn());
        assert_same(3500000.00, TeamHarvestLevel::amount(10));
        assert_contains('₱3,500,000.00', $ladderRow($html, 10));
    },

    'P20B-T14 a refused save changes neither store and its values and errors survive the redirect' => function () use ($apply, $storedLadder): void {
        test_signed_in_as(test_user('p20b_refuse_admin', 'admin'));

        $ladderBefore = $storedLadder();
        $gateBefore   = setting('team_harvest_qualify_directs');
        $auditBefore  = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        $result = $apply([
            'team_harvest_qualify_directs' => '4',
            'team_harvest_amounts.8'       => '10000000.00',
            'team_harvest_amounts.9'       => '10000000.00',
            'team_harvest_amounts.10'      => '10000000.00',
        ]);

        assert_true(isset($result['errors'][TeamHarvestLevel::SUM_KEY]), 'the card is refused');
        assert_same([], $result['changes'], 'a refused card writes no setting');
        assert_same($ladderBefore, $storedLadder(), 'a refused card writes no ladder row');
        assert_same($gateBefore, setting('team_harvest_qualify_directs'), 'the gate sent with the refused card did not land');
        assert_same($auditBefore, (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn(), 'no audit row');

        /* save() keeps the input and the errors for the next render. */
        errors_set($result['errors']);
        old_set($result['input']);

        assert_same('10000000.00', old('team_harvest_amounts.10'), 'the typed value survives');
        assert_contains('cannot exceed', error_for(TeamHarvestLevel::SUM_KEY));
        assert_same('4', old('team_harvest_qualify_directs'), 'the typed knob survives');

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
        assert_contains('value="4"', $html, 'the card re-renders the typed gate');

        errors_set([]);
        old_clear();

        /* The stored project is still the old ladder. */
        assert_contains('₱3,500,000.00', $html);
    },

    'P20B-T17 a ladder edit with an award in place changes the price but never the award' => function () use ($apply): void {
        test_signed_in_as(test_user('p20b_history_admin', 'admin'));

        $member = test_user('p20b_awarded_member', 'member');

        db()->prepare(
            'INSERT INTO team_harvest_awards (user_id, level, threshold_n, n_at_award, amount, status, credited_at)
             VALUES (?, 3, 39, 39, 4000.00, "credited", ?)'
        )->execute([(int) $member['id'], '2026-11-01 10:00:00']);

        $awardBefore = db()->query('SELECT amount, threshold_n, status FROM team_harvest_awards WHERE user_id = ' . (int) $member['id'])->fetch();

        $result = $apply(['team_harvest_amounts.3' => '5000.00']);

        assert_same([], $result['errors']);
        assert_same(5000.0, TeamHarvestLevel::amount(3), 'the ladder now pays ₱5,000.00 for level 3');
        assert_same(5096000.00, TeamHarvestLevel::total(), 'the full-ladder total follows the edit');

        $awardAfter = db()->query('SELECT amount, threshold_n, status FROM team_harvest_awards WHERE user_id = ' . (int) $member['id'])->fetch();

        assert_same($awardBefore, $awardAfter, 'the existing award keeps its snapshotted amount');
        assert_same(4000.0, (float) $awardAfter['amount']);
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn(), 'the card wrote no position');
    },

    /* ==========================================================
     *  The generated arithmetic (P20B-T11, T12, T13)
     * ========================================================== */

    'P20B-T11 the generated ladder equals Appendix A line for line' => function () use ($commissions, $block, $ladderRow, $cell, $reference): void {
        test_signed_in_as(test_user('p20b_appendix_admin', 'admin'));

        $html = $block($commissions());

        assert_contains('data-team-harvest-table', $html);
        assert_same(10, preg_match_all('/data-team-harvest-row="\d+"/', $html), 'ten read-only ladder rows');

        $slots = [3, 9, 27, 81, 243, 729, 2187, 6561, 19683, 59049];
        $thresholds = [3, 12, 39, 120, 363, 1092, 3279, 9840, 29523, 88572];
        $amounts = [1000, 2000, 4000, 6000, 12000, 50000, 120000, 200000, 1200000, 3500000];
        $cumulative = [1000, 3000, 7000, 13000, 25000, 75000, 195000, 395000, 1595000, 5095000];

        foreach (range(1, 10) as $index => $level) {
            $row = $ladderRow($html, $level);

            assert_same((string) $slots[$index], str_replace(',', '', $cell($row, 'slots', $level)), 'slots ' . $level);
            assert_same((string) $thresholds[$index], str_replace(',', '', $cell($row, 'threshold', $level)), 'threshold ' . $level);
            assert_contains('₱' . number_format($amounts[$index], 2), $cell($row, 'stored', $level), 'amount ' . $level);
            assert_contains('₱' . number_format($cumulative[$index], 2), $cell($row, 'stored-cumulative', $level), 'cumulative ' . $level);

            /* The model and the plan agree on every row. */
            assert_same($slots[$index], TeamHarvestLevel::slots($level));
            assert_same($thresholds[$index], TeamHarvestLevel::threshold($level));
            assert_same((float) $amounts[$index], $reference()[$level]);
        }

        assert_same(5095000.00, TeamHarvestLevel::total(), 'the full ladder total');
        assert_same(88572, TeamHarvestLevel::threshold(10), 'level 10 needs 88,572');
        assert_same(59049, TeamHarvestLevel::slots(10), 'level 10 holds 59,049');
        assert_contains('₱5,095,000.00', $html);
        assert_contains('88,572', $html);

        /* Rule 8: no matrix constant is typed into the view. */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/views/admin/settings/_team_harvest.php');

        foreach (['59049', '88572', '5095000', '2187'] as $literal) {
            assert_not_contains($literal, $source, 'the view carries no matrix literal: ' . $literal);
        }
    },

    'P20B-T20 the phase adds no schema: 39 tables, 76 seed settings, ten ladder rows' => function (): void {
        /* 20B is configuration only: the schema is exactly 20A's, and the
         * release phase (20G) is the one that moves APP_PHASE — this guard
         * follows the constant so it still proves the schema, not the badge.
         * Phase 21A later added three tables and two settings, so the guard
         * follows the schema it lives in (39 / 76 / 10). */
        assert_same(39, count(test_table_list(test_pdo(['db' => DB_NAME]))));
        assert_same(76, (int) db()->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn());
        assert_same(21, APP_PHASE, '21G owns the release badge');

        /* install.sql is untouched by this phase and still matches its
         * sources; the bundle's own count is 39 / 79 / 10. */
        $install = (string) file_get_contents(dirname(__DIR__, 2) . '/database/install.sql');
        assert_contains('39 tables', $install);
        assert_contains('79 settings', $install);
        assert_contains('10 Team Harvest level amounts', $install);
    },

    'P20B-T12 amount(11) is 0.00 and threshold(0) is refused' => function (): void {
        assert_same(0.0, TeamHarvestLevel::amount(0));
        assert_same(0.0, TeamHarvestLevel::amount(11));
        assert_same(0.0, TeamHarvestLevel::amount(-3));
        assert_same(1000.0, TeamHarvestLevel::amount(1));

        foreach ([0, 11, -1] as $level) {
            $error = assert_throws(
                static fn () => TeamHarvestLevel::threshold($level),
                InvalidArgumentException::class,
                'threshold(' . $level . ')'
            );

            assert_contains('1–10', $error->getMessage());
            assert_throws(static fn () => TeamHarvestLevel::slots($level), InvalidArgumentException::class);
        }

        assert_same(12, TeamHarvestLevel::threshold(2));
    },

    'P20B-T13 amounts() and map() read ten keys in one query and zero-fill a missing row' => function () use ($questions): void {
        $before = $questions();
        $amounts = TeamHarvestLevel::amounts();
        $after = $questions();

        assert_same(2, $after - $before, 'amounts() runs one statement (plus the counter read)');
        assert_same(range(1, 10), array_keys($amounts), 'keys 1 … 10');
        assert_same(1000.0, $amounts[1]);
        assert_same(3500000.0, $amounts[10]);

        $before = $questions();
        $map = TeamHarvestLevel::map();
        $after = $questions();

        assert_same(2, $after - $before, 'map() runs one statement (plus the counter read)');
        assert_same($amounts, $map, 'both reads agree');

        /* rows() is the rich shape a later phase audits from. */
        $rows = TeamHarvestLevel::rows();

        assert_count(10, $rows);
        assert_same(1, $rows[1]['level']);
        assert_same(1000.0, $rows[1]['amount']);
        assert_same(3500000.0, $rows[10]['amount']);
        assert_null($rows[1]['updated_by'], 'the fresh seed carries no actor stamp');

        /* A missing row reads 0.00, not an error. */
        db()->exec('DELETE FROM team_harvest_levels WHERE level = 6');

        assert_same(0.0, TeamHarvestLevel::amount(6), 'a missing row is ₱0.00');
        assert_same(0.0, TeamHarvestLevel::amounts()[6]);
        assert_same(0.0, TeamHarvestLevel::rows()[6]['amount']);
        assert_null(TeamHarvestLevel::rows()[6]['updated_at']);
        assert_same(9, (int) db()->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn());

        $total = TeamHarvestLevel::total();
        assert_same(5045000.00, $total, 'the total of the truncated ladder');
        assert_same(50000.0, TeamHarvestLevel::referencePreset()[6], 'the preset is not affected by the stored rows');

        /* The table can be empty: ten zeroes, never nine keys. */
        db()->exec('DELETE FROM team_harvest_levels');

        $empty = TeamHarvestLevel::amounts();
        assert_count(10, $empty);
        assert_same(array_fill(1, 10, 0.0), $empty);
        assert_same(0.0, TeamHarvestLevel::total());
    },

    /* ==========================================================
     *  Inert (P20B-T15), payload (T21) and scope (T22)
     * ========================================================== */

    'P20B-T15 three registrations after configuring write no queue row, no award and no money' => function (): void {
        test_signed_in_as(test_user('p20b_inert_admin', 'admin'));

        /* Configure a real ladder and turn the stream on first. */
        setting_set('team_harvest_enabled', '1');
        setting_set('team_harvest_qualify_directs', '3');
        setting_cache_clear();

        $sponsor = test_user('p20b_sponsor', 'member');
        $walletBefore = (string) db()->query(
            "SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users"
        )->fetchColumn();

        $queueBefore = (int) db()->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn();
        $awardBefore = (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn();
        $ledgerBefore = (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'team_harvest'")->fetchColumn();

        $registered = 0;

        for ($i = 1; $i <= 3; $i++) {
            $username = 'p20b_direct_' . $i . '_' . bin2hex(random_bytes(3));

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
            $registered++;
        }

        assert_same(3, $registered);
        assert_same(
            3,
            (int) db()->query('SELECT COUNT(*) FROM users WHERE sponsor_id = ' . (int) $sponsor['id'])->fetchColumn(),
            'the three directs are under the sponsor'
        );

        assert_same($queueBefore, (int) db()->query('SELECT COUNT(*) FROM harvest_queue')->fetchColumn(), 'no position was assigned');
        assert_same($awardBefore, (int) db()->query('SELECT COUNT(*) FROM team_harvest_awards')->fetchColumn(), 'no award was written');
        assert_same($ledgerBefore, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'team_harvest'")->fetchColumn(), 'no wallet movement');
        assert_same(
            $walletBefore,
            (string) db()->query("SELECT CONCAT(COALESCE(SUM(ewallet_balance), 0), '|', COALESCE(SUM(withdrawable_balance), 0)) FROM users")->fetchColumn(),
            'the wallet is unchanged'
        );

        /* The configured ladder is still what the card reads. */
        assert_same(5095000.00, TeamHarvestLevel::total());
    },

    'P20B-T21 the audit payload carries the three knobs and the ten amounts' => function () use ($apply): void {
        $admin = test_user('p20b_payload_admin', 'admin');
        test_signed_in_as($admin);

        $auditBefore = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        $result = $apply(['team_harvest_amounts.6' => '60000.00']);

        assert_same([], $result['errors']);
        assert_same(
            ['levels.6'],
            array_keys($result['ladder']['changes']),
            'only the level that moved is a change'
        );
        assert_same('50000.00', $result['ladder']['changes']['levels.6']['from']);
        assert_same('60000.00', $result['ladder']['changes']['levels.6']['to']);

        $stmt = db()->prepare("SELECT meta_json FROM audit_log WHERE action = 'team_harvest.ladder.update' AND id > ?");
        $stmt->execute([$auditBefore]);
        $meta = json_decode((string) $stmt->fetchColumn(), true);

        /* MySQL's JSON type sorts object keys, so compare the set, not the
         * insertion order. */
        $beforeKeys = array_keys($meta['before']);
        sort($beforeKeys);

        assert_same(['enabled', 'levels', 'max_levels', 'qualify_directs'], $beforeKeys);
        assert_count(10, $meta['before']['levels']);
        assert_count(10, $meta['after']['levels']);
        assert_same('50000.00', $meta['before']['levels'][6]);
        assert_same('60000.00', $meta['after']['levels'][6]);
        assert_same(['levels.6'], array_keys($meta['changes']));

        /* A knobs-only save has no level change in the payload. */
        $result = $apply(['team_harvest_max_levels' => '8']);

        assert_same(['max_levels'], array_keys($result['ladder']['changes']));
    },

    'P20B-T22 no other card can write the ladder and the amounts are not settings keys' => function () use ($sectionMethod, $teamKeys): void {
        /* The Team Harvest configuration is on the Commissions card only. */
        foreach (['general', 'shop', 'accounts', 'payouts', 'windows', 'fulfilment', 'transfers'] as $section) {
            foreach (array_merge($sectionMethod('sectionKeys', $section), $sectionMethod('sectionToggles', $section)) as $key) {
                assert_false(str_starts_with($key, 'team_harvest_'), $section . ' owns ' . $key);
            }
        }

        /* Every one of the ten amounts is in the catalogue, and the seed's
         * baseline is never validated or rendered. */
        assert_same(12, count($teamKeys()), 'two knobs + ten amounts');
        assert_true(in_array('team_harvest_qualify_directs', $teamKeys(), true));
        assert_true(in_array('team_harvest_max_levels', $teamKeys(), true));
        assert_true(in_array('team_harvest_amounts.1', $teamKeys(), true));
        assert_true(in_array('team_harvest_amounts.10', $teamKeys(), true));
        assert_same(['team_harvest_enabled'], array_values(array_filter(
            $GLOBALS['__p20bToggles'],
            static fn (string $key): bool => str_starts_with($key, 'team_harvest_')
        )));

        $rules = $sectionMethod('sectionRules', 'commissions');
        assert_false(array_key_exists('team_harvest_ledger_since', $rules), 'the baseline is never a field');

        /* The ladder is a table, not ten settings rows. */
        assert_same(
            0,
            (int) db()->query("SELECT COUNT(*) FROM settings WHERE key_name LIKE 'team_harvest\\_amounts%'")->fetchColumn(),
            'the amounts are not settings'
        );

        /* The four-state strip's order is a contract (plan §6 Rule 7). */
        assert_same(
            ['unseeded', 'seeded_off', 'seeded_on', 'backlog'],
            AdminSettingsController::TEAM_HARVEST_STATES
        );
    },

    'P20B-T24 a card that does not post the amount fields never clears the ladder' => function () use ($posted, $storedLadder, $reference): void {
        test_signed_in_as(test_user('p20b_partial_admin', 'admin'));

        /* The rest of the card as an older rendered form would post it: every
         * other key present, the ten amount fields absent. */
        $partial = $posted();

        foreach (array_keys($partial) as $key) {
            if (str_starts_with((string) $key, TeamHarvestLevel::AMOUNT_FIELD . '.')) {
                unset($partial[$key]);
            }
        }

        $partial['team_harvest_enabled'] = '1';

        $controller = new AdminSettingsController();
        $result     = $controller->applySection('commissions', $partial);

        assert_same([], $result['errors']);
        assert_same('1', setting('team_harvest_enabled'));
        assert_same($reference(), $storedLadder(), 'the ladder survived the partial post');
        assert_same(['enabled'], array_keys($result['ladder']['changes']), 'only the switch moved; no amount did');
        assert_same($result['ladder']['before']['levels'], $result['ladder']['after']['levels'], 'the omitted amounts are read back as stored');

        /* An explicit empty array *is* a submission: it clears the ladder. */
        $partial['team_harvest_enabled']                 = '0';
        $partial[TeamHarvestLevel::AMOUNT_FIELD]         = [];

        $cleared = $controller->applySection('commissions', $partial);

        assert_same([], $cleared['errors'], 'the switch is off, so an all-zero ladder is legal');
        assert_same(array_fill(1, 10, 0.0), $storedLadder(), 'an explicit empty submission clears all ten rows');
        assert_same(0.0, TeamHarvestLevel::total());

        /* Ten rows still exist: cleared, never deleted. */
        assert_same(10, (int) db()->query('SELECT COUNT(*) FROM team_harvest_levels')->fetchColumn());
    },
];
