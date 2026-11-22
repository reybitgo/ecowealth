<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageBinaryAuditTest — Phase 23F Operations S1
 *  (plan §10 Phase 23F — Operations, slice 23F-S1)
 *
 *  Proves `bin/binary_audit.php` on the package (activation) stream:
 *  activation FK/source integrity (INV-P1), stamp-to-volume
 *  reconciliation (INV-P5), package awards never pending (INV-P2),
 *  pair consumption (INV-5), activation totals (INV-P4) and
 *  disabled-package points (INV-P8). --fix stays cache-only.
 *
 *  Runs the real binary_audit.php command against a scratch schema
 *  (the subprocess cannot see the harness's rolled-back transaction).
 *
 *  Run this group alone with:  php tests/run.php PackageBinaryAudit
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/** A scratch schema with the Phase 22 binary schema plus the 23A
 *  activation-source amendment, and the binary settings resolved. */
$scratch = static function () use ($appRoot): array {
    [$pdo, $database] = test_scratch_database('tool_b');

    test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
    test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

    /* Binary engine settings the audit path resolves. */
    $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'binary_enabled'");
    $pdo->exec("UPDATE settings SET value = '1000' WHERE key_name = 'binary_pair_points'");
    $pdo->exec("UPDATE settings SET value = '10.000' WHERE key_name = 'binary_pair_pct'");
    $pdo->exec("UPDATE settings SET value = '20' WHERE key_name = 'binary_daily_pair_cap'");

    /* Package-binary settings (the fresh schema does not carry the 23A
     * settings rows). */
    $pdo->exec(
        "INSERT INTO settings (key_name, value) VALUES
          ('package_binary_enabled',            '1'),
          ('package_binary_requires_package',   '1'),
          ('package_binary_ledger_since',       DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );

    return [$pdo, $database];
};

/* One company root, one placed buyer under it, and one binary-enabled
 * package worth 1,000 points. All rows are committed on the scratch
 * connection so the audit subprocess can read them. */
$fixture = static function (PDO $pdo): array {
    $pdo->exec(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active)
         VALUES ('p23fa_root', 'p23fa_root@test.local', 'x', 'Root', 'admin', 'active', 1)"
    );
    $rootId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active)
         VALUES ('p23fa_buyer', 'p23fa_buyer@test.local', 'x', 'Buyer', 'member', 'active', 1)"
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

    return ['root' => $rootId, 'buyer' => $buyerId, 'pkg' => $pkgId];
};

/* Approve one activation of the package for the buyer (raw SQL: the
 * audit reads the row directly, no service writer is involved). */
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

/* One throw-away log folder per case. */
$logDir = static function (): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecowealth-pba-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);
    return $dir;
};

$cleanup = static function (string $dir): void {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
};

/** Run the real binary_audit.php command against a scratch schema. */
$runAudit = static function (string $database, array $arguments = [], ?string $dir = null) use ($appRoot): array {
    $previous = getenv('ECO_DB_NAME');
    putenv('ECO_DB_NAME=' . $database);

    try {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($appRoot . '/bin/binary_audit.php');

        if ($dir !== null) {
            $command .= ' --log-dir=' . escapeshellarg($dir);
        }

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $lines, $code);
    } finally {
        if ($previous === false) {
            putenv('ECO_DB_NAME');
        } else {
            putenv('ECO_DB_NAME=' . $previous);
        }
    }

    $output = implode("\n", $lines);
    $json   = json_decode($output, true);

    return ['exit' => $code, 'output' => $output, 'json' => is_array($json) ? $json : null];
};

/** First drift matching a kind, or null. */
$driftOf = static function (array $drifts, string $kind): ?array {
    foreach ($drifts as $drift) {
        if ((string) ($drift['kind'] ?? '') === $kind) {
            return $drift;
        }
    }
    return null;
};

return [

/* --------------------------------------------------------------
 *  P23F-T1: clean check on a package fixture exits 0
 * -------------------------------------------------------------- */

'P23F-T1 binary_audit --check on a clean package fixture exits 0' => function () use ($scratch, $fixture, $approveActivation, $placePackageVolume, $runAudit, $logDir, $cleanup): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);
    $aid = $approveActivation($pdo, $data['buyer'], $data['pkg']);
    $placePackageVolume($pdo, $aid, $data['root'], $data['buyer']);

    $dir = $logDir();
    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(0, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(true, $run['json']['ok'], 'audit should be clean: ' . json_encode($run['json']['drifts'] ?? []));
    assert_same(0, (int) $run['json']['drift']);
    assert_same('check', $run['json']['audit']);
    assert_same('cli', $run['json']['trigger']);
},

/* --------------------------------------------------------------
 *  P23F-T2: an activation award whose activation was deleted
 * -------------------------------------------------------------- */

'P23F-T2 --check with a deleted activation exits 1 and names the award' => function () use ($scratch, $fixture, $approveActivation, $placePackageVolume, $runAudit, $logDir, $cleanup, $driftOf): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);
    $aid = $approveActivation($pdo, $data['buyer'], $data['pkg']);
    $placePackageVolume($pdo, $aid, $data['root'], $data['buyer']);

    /* Add a credited package pair award naming the activation. */
    $pdo->prepare(
        "INSERT INTO binary_awards
           (kind, source, activation_id, buyer_id, user_id, side, points,
            pair_count, pair_points, rate_pct, amount, status, paid_at)
         VALUES ('pair', 'activation', ?, ?, ?, NULL, 2000, 1, 1000, 10.000, 100.00, 'credited', NOW())"
    )->execute([$aid, $data['buyer'], $data['root']]);
    $awardId = (int) $pdo->lastInsertId();

    /* Delete the activation while keeping its award (FK cascade off). */
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->prepare('DELETE FROM activations WHERE id = ?')->execute([$aid]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $dir = $logDir();
    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(1, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(false, $run['json']['ok']);

    $drift = $driftOf($run['json']['drifts'], 'package_activation_reference');
    assert_not_null($drift, 'expected a package_activation_reference drift: ' . json_encode($run['json']['drifts'] ?? []));
    assert_same($awardId, (int) ($drift['award'] ?? 0), 'the drift must name the orphaned award');
    assert_same($aid, (int) ($drift['activation'] ?? 0), 'the drift must name the missing activation');
},

/* --------------------------------------------------------------
 *  P23F-T3: a stamped activation with points but no volume rows
 * -------------------------------------------------------------- */

'P23F-T3 --check with a stamped activation and no volume exits 1' => function () use ($scratch, $fixture, $approveActivation, $runAudit, $logDir, $cleanup, $driftOf): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);
    $aid = $approveActivation($pdo, $data['buyer'], $data['pkg']);

    /* Stamp the activation but write no team volume. */
    $pdo->prepare(
        "UPDATE activations
           SET binary_processed_at = NOW(), binary_points = 1000, binary_total = 0.00
         WHERE id = ?"
    )->execute([$aid]);

    $dir = $logDir();
    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(1, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(false, $run['json']['ok']);

    $drift = $driftOf($run['json']['drifts'], 'package_stamped_no_volume');
    assert_not_null($drift, 'expected a package_stamped_no_volume drift: ' . json_encode($run['json']['drifts'] ?? []));
    assert_same($aid, (int) ($drift['activation'] ?? 0), 'the drift must name the stamped activation');
},

/* --------------------------------------------------------------
 *  P23F-T4: a pending package award is a failure, not a warning
 * -------------------------------------------------------------- */

'P23F-T4 --check with a pending package award exits 1' => function () use ($scratch, $fixture, $approveActivation, $runAudit, $logDir, $cleanup, $driftOf): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);
    $aid = $approveActivation($pdo, $data['buyer'], $data['pkg']);

    $pdo->prepare(
        "INSERT INTO binary_awards
           (kind, source, activation_id, buyer_id, user_id, side, points,
            pair_count, pair_points, rate_pct, amount, status, paid_at)
         VALUES ('pair', 'activation', ?, ?, ?, NULL, 2000, 1, 1000, 10.000, 100.00, 'pending', NOW())"
    )->execute([$aid, $data['buyer'], $data['root']]);

    $dir = $logDir();
    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(1, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(false, $run['json']['ok']);

    $drift = $driftOf($run['json']['drifts'], 'package_pending_award');
    assert_not_null($drift, 'expected a package_pending_award drift: ' . json_encode($run['json']['drifts'] ?? []));
    assert_true((int) ($drift['count'] ?? 0) >= 1, 'the drift must report at least one pending award');
},

/* --------------------------------------------------------------
 *  P23F-T7: --fix repairs a cache; re-check clean; volume untouched
 * -------------------------------------------------------------- */

'P23F-T7 --fix repairs the cache, re-check exits 0, volume and awards untouched' => function () use ($scratch, $fixture, $approveActivation, $placePackageVolume, $runAudit, $logDir, $cleanup): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);
    $aid = $approveActivation($pdo, $data['buyer'], $data['pkg']);
    $placePackageVolume($pdo, $aid, $data['root'], $data['buyer']);

    $beforeVolume = (int) $pdo->query('SELECT COUNT(*) FROM binary_volume')->fetchColumn();
    $beforeAwards = (int) $pdo->query("SELECT COUNT(*) FROM binary_awards WHERE source = 'activation'")->fetchColumn();

    /* Corrupt the root's cached right balance. */
    $pdo->prepare('UPDATE binary_nodes SET right_points = 999999 WHERE user_id = ?')
        ->execute([$data['root']]);

    $dir = $logDir();
    try {
        $fix = $runAudit($database, ['--fix', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(0, $fix['exit'], $fix['output']);
    assert_not_null($fix['json'], $fix['output']);
    assert_same(true, $fix['json']['ok']);
    assert_same(0, (int) $fix['json']['drift']);

    /* Re-check: clean. */
    try {
        $check = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(0, $check['exit'], $check['output']);
    assert_same(true, $check['json']['ok']);
    assert_same(0, (int) $check['json']['drift']);

    /* Volume and awards untouched -- --fix touches only node caches. */
    assert_same($beforeVolume, (int) $pdo->query('SELECT COUNT(*) FROM binary_volume')->fetchColumn());
    assert_same($beforeAwards, (int) $pdo->query("SELECT COUNT(*) FROM binary_awards WHERE source = 'activation'")->fetchColumn());
    $stmt = $pdo->prepare('SELECT right_points FROM binary_nodes WHERE user_id = ?');
    $stmt->execute([$data['root']]);
    assert_same(1000, (int) $stmt->fetchColumn());
}];
