<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageBinaryHealthTest — Phase 23F Operations S2
 *  (plan §10 Phase 23F — Operations, slice 23F-S2)
 *
 *  Proves `HealthService` exposes the three package-binary checks
 *  (readiness, escrow, totals) and that they pass clean, warn on
 *  0-point packages and fail on drift; and that the runbook's
 *  package-binary commands run as written.
 *
 *  Runs HealthService in-process against the test database; fixture
 *  rows are created inside the case's rolled-back transaction.
 *
 *  Run this group alone with:  php tests/run.php PackageBinaryHealth
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/* One company root, one placed buyer under it, and one binary-enabled
 * package worth 1,000 points. All rows are committed on $pdo. */
$fixture = static function (PDO $pdo): array {
    $pdo->exec(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active)
         VALUES ('p23fh_root', 'p23fh_root@test.local', 'x', 'Root', 'admin', 'active', 1)"
    );
    $rootId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active)
         VALUES ('p23fh_buyer', 'p23fh_buyer@test.local', 'x', 'Buyer', 'member', 'active', 1)"
    );
    $buyerId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
         VALUES (?, NULL, NULL, ?, 0, '', 'root', NOW(), ?)"
    )->execute([$rootId, $rootId, $rootId]);

    $pdo->prepare(
        "INSERT INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
         VALUES (?, ?, 'right', ?, 1, 'R', 'placed', NOW(), ?)"
    )->execute([$buyerId, $rootId, $rootId, $rootId]);

    $pdo->exec(
        "INSERT INTO packages (name, slug, price, product_value, binary_enabled, binary_points,
                               direct_referral_bonus, status, sort_order)
         VALUES ('PK Test', 'pk-test', 1000.00, 1000.00, 1, 1000, 0, 'active', 1)"
    );
    $pkgId = (int) $pdo->lastInsertId();

    /* The global switch must be on for the readiness check to pass. */
    $pdo->exec(
        "INSERT INTO settings (key_name, value) VALUES
          ('package_binary_enabled', '1')
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );

    return ['root' => $rootId, 'buyer' => $buyerId, 'pkg' => $pkgId];
};

/* Approve one activation of the package for the buyer (raw SQL). */
$approveActivation = static function (PDO $pdo, int $userId, int $packageId): int {
    $pdo->prepare(
        "INSERT INTO activations
           (user_id, package_id, amount, payment_method, status, commission_processed_at, created_at)
         VALUES (?, ?, '1000.00', 'ewallet', 'approved', NOW(), NOW())"
    )->execute([$userId, $packageId]);
    return (int) $pdo->lastInsertId();
};

/* Place the package's points as one team row at the root and stamp the
 * activation exactly as the package engine would. */
$placePackageVolume = static function (PDO $pdo, int $aid, int $rootId, int $buyerId): void {
    $pdo->prepare(
        "INSERT INTO binary_volume
           (node_user_id, side, kind, points, order_id, order_item_id, buyer_id, award_id,
            source, activation_id, note)
         VALUES (?, 'right', 'team', 1000, NULL, NULL, ?, NULL, 'activation', ?, 'test volume')"
    )->execute([$rootId, $buyerId, $aid]);

    $pdo->prepare(
        "UPDATE binary_nodes
           SET right_points = 1000, right_credited = 1000, updated_at = NOW()
         WHERE user_id = ?"
    )->execute([$rootId]);

    $pdo->prepare(
        "UPDATE activations
           SET binary_processed_at = NOW(), binary_points = 1000, binary_total = 0.00
         WHERE id = ?"
    )->execute([$aid]);
};

/** Run HealthService::checks() against the current test database. */
$healthChecks = static function (): array {
    $appRoot = dirname(__DIR__, 2);
    require_once $appRoot . '/app/autoload.php';
    $service = new \HealthService();
    return $service->checks();
};

/** Find one section by key. */
$sectionOf = static function (array $sections, string $key): ?array {
    foreach ($sections as $section) {
        if ((string) ($section['key'] ?? '') === $key) {
            return $section;
        }
    }
    return null;
};

/** Run a CLI command with ECO_DB_NAME pointing at the test database. */
$runCli = static function (string $command): array {
    $appRoot = dirname(__DIR__, 2);
    $database = db()->query('SELECT DATABASE()')->fetchColumn();
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);
    try {
        exec($command . ' 2>&1', $lines, $code);
    } finally {
        if ($previous === false) {
            putenv('ECO_DB_NAME');
        } else {
            putenv('ECO_DB_NAME=' . $previous);
        }
    }
    return ['exit' => $code, 'output' => implode("\n", $lines)];
};

return [

/* --------------------------------------------------------------
 *  P23F-T5: Health pack — three package checks
 * -------------------------------------------------------------- */

'P23F-T5 HealthService exposes the three package-binary checks' => function () use ($fixture, $approveActivation, $placePackageVolume, $healthChecks, $sectionOf): void {
    $pdo = db();
    $data = $fixture($pdo);
    $aid = $approveActivation($pdo, $data['buyer'], $data['pkg']);
    $placePackageVolume($pdo, $aid, $data['root'], $data['buyer']);

    $result = $healthChecks();
    $sections = $result['sections'] ?? [];

    $readiness  = $sectionOf($sections, 'binary_package_readiness');
    $escrow     = $sectionOf($sections, 'binary_package_escrow');
    $totals     = $sectionOf($sections, 'binary_package_totals');

    assert_not_null($readiness,  'binary_package_readiness section is missing');
    assert_not_null($escrow,     'binary_package_escrow section is missing');
    assert_not_null($totals,     'binary_package_totals section is missing');

    assert_true($readiness['ok'],  'readiness should pass: ' . (string) ($readiness['summary'] ?? ''));
    assert_true($escrow['ok'],     'escrow should pass: ' . (string) ($escrow['summary'] ?? ''));
    assert_true($totals['ok'],     'totals should pass: ' . (string) ($totals['summary'] ?? ''));

    assert_contains('Package binary readiness', (string) ($readiness['label'] ?? ''));
    assert_contains('Package binary escrow',    (string) ($escrow['label'] ?? ''));
    assert_contains('Package binary totals',    (string) ($totals['label'] ?? ''));
},

'P23F-T5 HealthService warns on 0-point packages' => function () use ($fixture, $healthChecks, $sectionOf): void {
    $pdo = db();
    $data = $fixture($pdo);

    /* Flip the package to 0 points but keep it enabled. */
    $pdo->prepare('UPDATE packages SET binary_points = 0 WHERE id = ?')
        ->execute([$data['pkg']]);

    $result = $healthChecks();
    $sections = $result['sections'] ?? [];
    $readiness = $sectionOf($sections, 'binary_package_readiness');

    assert_not_null($readiness, 'binary_package_readiness section is missing');
    assert_true($readiness['ok'],  'readiness still ok on a warn: ' . (string) ($readiness['summary'] ?? ''));
    assert_contains('need attention', (string) ($readiness['summary'] ?? ''));
},

'P23F-T5 HealthService fails on package drift' => function () use ($fixture, $approveActivation, $placePackageVolume, $healthChecks, $sectionOf): void {
    $pdo = db();
    $data = $fixture($pdo);
    $aid = $approveActivation($pdo, $data['buyer'], $data['pkg']);
    $placePackageVolume($pdo, $aid, $data['root'], $data['buyer']);

    /* Add a credited pair award naming this activation, then set binary_total
     * to a different value → INV-P4 package_total_mismatch drift. */
    $pdo->prepare(
        "INSERT INTO binary_awards
           (kind, source, activation_id, buyer_id, user_id, side, points,
            pair_count, pair_points, rate_pct, amount, status, paid_at)
         VALUES ('pair', 'activation', ?, ?, ?, NULL, 2000, 1, 1000, 10.000, 100.00, 'credited', NOW())"
    )->execute([$aid, $data['buyer'], $data['root']]);

    $pdo->prepare(
        "UPDATE activations SET binary_total = 999.99 WHERE id = ?"
    )->execute([$aid]);

    $result = $healthChecks();
    $sections = $result['sections'] ?? [];
    $totals = $sectionOf($sections, 'binary_package_totals');

    assert_not_null($totals, 'binary_package_totals section is missing');
    assert_false($totals['ok'], 'totals should fail on drift: ' . (string) ($totals['summary'] ?? ''));
    assert_contains('disagree', (string) ($totals['summary'] ?? ''));
},

/* --------------------------------------------------------------
 *  P23F-T6: Runbook commands — every command runs as written
 * -------------------------------------------------------------- */

'P23F-T6 package_binary_reverse --help runs and exits 0' => function () use ($runCli): void {
    $appRoot = dirname(__DIR__, 2);

    $run = $runCli('"' . PHP_BINARY . '" "' . $appRoot . '/bin/package_binary_reverse.php" --help');

    assert_same(0, $run['exit'], $run['output']);
    assert_contains('--activation=ID', $run['output']);
    assert_contains('dry run', strtolower($run['output']));
},

'P23F-T6 binary_audit --check runs and exits 0 on a clean fixture' => function () use ($fixture, $approveActivation, $placePackageVolume, $runCli): void {
    $appRoot = dirname(__DIR__, 2);
    $pdo = db();
    $data = $fixture($pdo);
    $aid = $approveActivation($pdo, $data['buyer'], $data['pkg']);
    $placePackageVolume($pdo, $aid, $data['root'], $data['buyer']);

    $run = $runCli('"' . PHP_BINARY . '" "' . $appRoot . '/bin/binary_audit.php" --check --json');

    assert_same(0, $run['exit'], $run['output']);
    $json = json_decode($run['output'], true);
    assert_not_null($json, $run['output']);
    assert_same(true, $json['ok'] ?? false, 'audit should be clean: ' . $run['output']);
    assert_same(0, (int) ($json['drift'] ?? -1), $run['output']);
}];
