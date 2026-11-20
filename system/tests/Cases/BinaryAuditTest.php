<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryAuditTest — Phase 22H-S2 (plan §12, §16.10)
 *
 *  Proves `bin/binary_audit.php` and `BinaryService::audit()`/`auditFix()`
 *  on a committed scratch schema (the subprocess cannot see the harness's
 *  rolled-back transaction, exactly like UnilevelReleaseJobTest).
 *
 *  Covers: clean check exit 0, seeded drift exit 1, cache-only --fix,
 *  missing pair consumption, stamped-without-volume, broken path/depth,
 *  JSON output with stable keys, and read-only --check.
 *
 *  Run this group alone with:  php tests/run.php BinaryAudit
 * ============================================================== */

$appRoot = dirname(__DIR__, 2);

/** A scratch schema with the full product-binary schema and settings. */
$scratch = static function () use ($appRoot): array {
    [$pdo, $database] = test_scratch_database('tool_a');

    test_run_sql_file($pdo, $appRoot . '/database/schema.sql');
    test_run_sql_file($pdo, $appRoot . '/database/seeds/001_settings.sql');

    $pdo->exec("UPDATE settings SET value = '7' WHERE key_name = 'binary_hold_days'");
    $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'binary_enabled'");
    $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'binary_release_enabled'");
    $pdo->exec("UPDATE settings SET value = '1000' WHERE key_name = 'binary_pair_points'");
    $pdo->exec("UPDATE settings SET value = '10.000' WHERE key_name = 'binary_pair_pct'");
    $pdo->exec("UPDATE settings SET value = '5.000' WHERE key_name = 'binary_self_pct'");
    $pdo->exec("UPDATE settings SET value = '20' WHERE key_name = 'binary_daily_pair_cap'");
    $pdo->exec("UPDATE settings SET value = '1' WHERE key_name = 'binary_requires_active'");

    return [$pdo, $database];
};

/**
 * One company root plus a three-level chain, with volume, awards and a
 * delivered order — enough to exercise every audit check.
 *
 * @return array{
 *   root:int, admin:int, buyer:int, upline:int, top:int,
 *   order:int, selfAward:int, pairAward:int, volumeId:int
 * }
 */
$fixture = static function (PDO $pdo, string $tag = 'a'): array {
    $users = $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active)
         VALUES (?, ?, 'x', ?, 'member', 'active', 1)"
    );

    /* Company root (admin) — never paid, never a volume recipient. */
    $users->execute(['p22ha_' . $tag . '_admin', 'p22ha_' . $tag . '_a@test.local', 'Admin']);
    $adminId = (int) $pdo->lastInsertId();

    /* The company account row (primary admin). */
    $pdo->exec("UPDATE users SET role = 'admin' WHERE id = " . $adminId);

    /* A chain: root -> upline -> buyer. */
    $users->execute(['p22ha_' . $tag . '_upline', 'p22ha_' . $tag . '_u@test.local', 'Upline']);
    $uplineId = (int) $pdo->lastInsertId();

    $users->execute(['p22ha_' . $tag . '_buyer', 'p22ha_' . $tag . '_b@test.local', 'Buyer']);
    $buyerId = (int) $pdo->lastInsertId();

    /* Node rows: root, upline (right of root), buyer (right of upline). */
    $nodes = $pdo->prepare(
        "INSERT INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
    );

    $nodes->execute([$adminId, null, null, $adminId, 0, '', 'root', $adminId]);
    $nodes->execute([$uplineId, $adminId, 'right', $adminId, 1, 'R', 'placed', $adminId]);
    $nodes->execute([$buyerId,  $uplineId,  'right', $adminId, 2, 'RR', 'placed', $adminId]);

    /* Product with binary on and P.V. 1,000. */
    $pdo->exec(
        "INSERT INTO products (sku, name, slug, price, pv, binary_enabled, stock)
         VALUES ('P22HA-CLI-{$tag}', 'Audit product {$tag}', 'p22ha-release-{$tag}',
                 100.00, 1000.00, 1, 10)"
    );
    $productId = (int) $pdo->lastInsertId();

    /* Delivered, paid order (hold already elapsed). */
    $yesterday = date('Y-m-d H:i:s', time() - 86400 * 10);
    $orders = $pdo->prepare(
        "INSERT INTO shop_orders
           (order_no, user_id, status, payment_method, subtotal, total,
            billing_name, shipping_name, shipping_address, paid_at,
            delivered_at, binary_processed_at, binary_points,
            binary_self_total, binary_pair_total, binary_total, terms_accepted_at)
         VALUES (?, ?, ?, 'ewallet', 100.00, 100.00, 'Buyer', 'Buyer', 'Address',
                 ?, ?, NOW(), 1000, 50.00, 100.00, 150.00, NOW())"
    );
    $orders->execute(['P22HA-' . strtoupper($tag) . '-DUE', $buyerId, 'delivered', $yesterday, $yesterday]);
    $orderId = (int) $pdo->lastInsertId();

    $items = $pdo->prepare(
        "INSERT INTO shop_order_items
           (order_id, product_id, product_name, quantity, unit_price, line_total,
            unit_base, base_total, unit_points, points_total, reservation_state)
         VALUES (?, ?, 'Audit product', 1, 100.00, 100.00, 0, 0, 1000, 1000, 'committed')"
    );
    $items->execute([$orderId, $productId]);
    $itemId = (int) $pdo->lastInsertId();

    /* Volume: buyer's own row (right, 1000), upline's team row (right, 1000). */
    $volume = $pdo->prepare(
        "INSERT INTO binary_volume
           (node_user_id, side, kind, points, order_id, order_item_id, buyer_id, award_id, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $volume->execute([$buyerId,  'right', 'own',   1000, $orderId, $itemId, $buyerId, null, 'test volume']);
    $volume->execute([$uplineId, 'right', 'team',  1000, $orderId, $itemId, $buyerId, null, 'test volume']);

    /* Self award: buyer, 50.00, pending. */
    $awards = $pdo->prepare(
        "INSERT INTO binary_awards
           (kind, order_id, buyer_id, user_id, side, points, pair_count,
            pair_points, rate_pct, amount, status, hold_days, paid_at)
         VALUES ('self', ?, ?, ?, 'right', 1000, 0, 0, 5.000, 50.00, 'pending', 7, NOW())"
    );
    $awards->execute([$orderId, $buyerId, $buyerId]);
    $selfAwardId = (int) $pdo->lastInsertId();

    /* Pair award: upline, 1 pair (1000 pts matched), ₱100.00, pending. */
    $pairAward = $pdo->prepare(
        "INSERT INTO binary_awards
           (kind, order_id, buyer_id, user_id, side, points, pair_count,
            pair_points, rate_pct, amount, status, hold_days, paid_at)
         VALUES ('pair', ?, ?, ?, 'right', 2000, 1, 1000, 10.000, 100.00, 'pending', 7, NOW())"
    );
    $pairAward->execute([$orderId, $buyerId, $uplineId]);
    $pairAwardId = (int) $pdo->lastInsertId();

    /* Pair consumption rows: two negative rows for the upline. */
    $volume->execute([$uplineId, 'left',  'pair', -1000, $orderId, null, $buyerId, $pairAwardId, 'pair consumption']);
    $volume->execute([$uplineId, 'right', 'pair', -1000, $orderId, null, $buyerId, $pairAwardId, 'pair consumption']);

    /* Node caches match the ledger. */
    $pdo->prepare('UPDATE binary_nodes SET left_points = 0, right_points = 1000, left_credited = 0, right_credited = 1000, personal_points = 1000 WHERE user_id = ?')
        ->execute([$buyerId]);
    $pdo->prepare('UPDATE binary_nodes SET left_points = -1000, right_points = 0, left_credited = 0, right_credited = 1000, pairs_paid = 1, points_matched = 2000 WHERE user_id = ?')
        ->execute([$uplineId]);

    return [
        'root'      => $adminId,
        'admin'     => $adminId,
        'buyer'     => $buyerId,
        'upline'    => $uplineId,
        'top'       => $adminId,
        'order'     => $orderId,
        'selfAward' => $selfAwardId,
        'pairAward' => $pairAwardId,
        'volumeId'  => (int) $pdo->lastInsertId(),
    ];
};

/** A throw-away log folder for one case. */
$logDir = static function (): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecowealth-binary-audit-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);

    return $dir;
};

$cleanup = static function (string $dir): void {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
};

/**
 * Run the real binary_audit.php command against a scratch schema.
 *
 * @param list<string> $arguments
 * @return array{exit:int, output:string, json:?array}
 */
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

$status = static function (PDO $pdo, int $awardId): string {
    return (string) $pdo->query('SELECT status FROM binary_awards WHERE id = ' . $awardId)->fetchColumn();
};

$nodeLeft = static function (PDO $pdo, int $userId): int {
    return (int) $pdo->query('SELECT left_points FROM binary_nodes WHERE user_id = ' . $userId)->fetchColumn();
};

$nodeRight = static function (PDO $pdo, int $userId): int {
    return (int) $pdo->query('SELECT right_points FROM binary_nodes WHERE user_id = ' . $userId)->fetchColumn();
};

$volumeCount = static function (PDO $pdo): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM binary_volume')->fetchColumn();
};

$nodeCount = static function (PDO $pdo): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM binary_nodes')->fetchColumn();
};

return [

/* --------------------------------------------------------------
 *  P22H-T8: clean check exits 0
 * -------------------------------------------------------------- */

'P22H-T8 binary_audit --check on a clean fixture exits 0' => function () use ($scratch, $fixture, $runAudit, $logDir, $cleanup): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);

    $dir = $logDir();

    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(0, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(true, $run['json']['ok']);
    assert_same(0, (int) $run['json']['drift']);
    assert_same('check', $run['json']['audit']);
    assert_same('cli', $run['json']['trigger']);
},

'P22H-T9 --check with a corrupted left_points exits 1 and names the node' => function () use ($scratch, $fixture, $runAudit, $logDir, $cleanup): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);

    /* Corrupt the buyer's cache. */
    $pdo->prepare('UPDATE binary_nodes SET left_points = 999999 WHERE user_id = ?')
        ->execute([$data['buyer']]);

    $dir = $logDir();

    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(1, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(false, $run['json']['ok']);
    assert_same(1, (int) $run['json']['drift']);

    $found = false;
    foreach ($run['json']['drifts'] as $drift) {
        if ((int) ($drift['user_id'] ?? 0) === $data['buyer']
            && (string) ($drift['side'] ?? '') === 'left') {
            $found = true;
            assert_same(999999 - 0, (int) $drift['delta']);
        }
    }
    assert_true($found, 'expected a left_points drift naming the buyer');
},

'P22H-T10 --fix repairs the cache; re-check exits 0; awards/volume/wallet untouched' => function () use ($scratch, $fixture, $runAudit, $logDir, $cleanup, $volumeCount, $status): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);

    $beforeVolume = $volumeCount($pdo);

    /* Corrupt the buyer's left_points cache. */
    $pdo->prepare('UPDATE binary_nodes SET left_points = 999999 WHERE user_id = ?')
        ->execute([$data['buyer']]);

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

    /* Volume and award rows untouched. */
    assert_same($beforeVolume, $volumeCount($pdo));
    assert_same('pending', $status($pdo, $data['selfAward']));
    assert_same('pending', $status($pdo, $data['pairAward']));
},

'P22H-T11 --check with a missing pair-consumption row exits 1 and names the award' => function () use ($scratch, $fixture, $runAudit, $logDir, $cleanup): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);

    /* Delete the upline's left pair-consumption row. */
    $pdo->prepare(
        "DELETE FROM binary_volume
          WHERE node_user_id = ? AND kind = 'pair' AND side = 'left' AND award_id = ?"
    )->execute([$data['upline'], $data['pairAward']]);

    $dir = $logDir();

    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(1, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(false, $run['json']['ok']);

    $found = false;
    foreach ($run['json']['drifts'] as $drift) {
        if ((int) ($drift['award'] ?? 0) === $data['pairAward']
            && (string) ($drift['kind'] ?? '') === 'pair_consumption') {
            $found = true;
        }
    }
    assert_true($found, 'expected a pair_consumption drift naming the pair award');
},

'P22H-T12 --check with an order stamped and no volume exits 1 and names the order' => function () use ($scratch, $fixture, $runAudit, $logDir, $cleanup): void {
    [$pdo, $database] = $scratch();

    /* Reuse fixture setup but strip the volume rows after creation. */
    $data = $fixture($pdo);

    /* Delete all volume for this order but keep the stamp. */
    $pdo->prepare('DELETE FROM binary_volume WHERE order_id = ?')->execute([$data['order']]);

    $dir = $logDir();

    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(1, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(false, $run['json']['ok']);

    $found = false;
    foreach ($run['json']['drifts'] as $drift) {
        if ((int) ($drift['order'] ?? 0) === $data['order']
            && (string) ($drift['kind'] ?? '') === 'stamped_no_volume') {
            $found = true;
        }
    }
    assert_true($found, 'expected a stamped_no_volume drift naming the order');
},

'P22H-T13 --check with a broken path/depth exits 1 and names the node' => function () use ($scratch, $fixture, $runAudit, $logDir, $cleanup): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);

    /* Break the buyer's path (RR -> XX). */
    $pdo->prepare('UPDATE binary_nodes SET path = ? WHERE user_id = ?')
        ->execute(['XX', $data['buyer']]);

    $dir = $logDir();

    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(1, $run['exit'], $run['output']);
    assert_not_null($run['json'], $run['output']);
    assert_same(false, $run['json']['ok']);

    $found = false;
    foreach ($run['json']['drifts'] as $drift) {
        if ((int) ($drift['user_id'] ?? 0) === $data['buyer']
            && (string) ($drift['kind'] ?? '') === 'path_depth') {
            $found = true;
        }
    }
    assert_true($found, 'expected a path_depth drift naming the buyer');
},

'P22H-T16 --json on both commands: stable keys, exit codes match Rule 5' => function () use ($scratch, $fixture, $runAudit, $logDir, $cleanup): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);

    $dir = $logDir();

    try {
        $check = $runAudit($database, ['--check', '--json'], $dir);
        $fix   = $runAudit($database, ['--fix', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(0, $check['exit'], $check['output']);
    assert_same(0, $fix['exit'], $fix['output']);

    $expectedCheckKeys = [
        'ok', 'trigger', 'audit', 'drift', 'warning', 'exit_code', 'drifts', 'duration_ms',
    ];
    assert_same($expectedCheckKeys, array_keys($check['json']), 'check payload keys');

    $expectedFixKeys = [
        'ok', 'trigger', 'audit', 'fixed', 'drift', 'exit_code', 'drifts', 'duration_ms',
    ];
    assert_same($expectedFixKeys, array_keys($fix['json']), 'fix payload keys');

    assert_same('cli', $check['json']['trigger']);
    assert_same('check', $check['json']['audit']);
    assert_same('fix',   $fix['json']['audit']);
    assert_same(0, (int) $check['json']['exit_code']);
    assert_same(0, (int) $fix['json']['exit_code']);
},

'P22H-T18 --check changes no data: row counts and balances identical before and after' => function () use ($scratch, $fixture, $runAudit, $logDir, $cleanup, $volumeCount, $nodeCount, $nodeLeft, $nodeRight): void {
    [$pdo, $database] = $scratch();
    $data = $fixture($pdo);

    $beforeVol = $volumeCount($pdo);
    $beforeNode= $nodeCount($pdo);
    $beforeL   = $nodeLeft($pdo, $data['buyer']);
    $beforeR   = $nodeRight($pdo, $data['buyer']);

    $dir = $logDir();

    try {
        $run = $runAudit($database, ['--check', '--json'], $dir);
    } finally {
        $cleanup($dir);
    }

    assert_same(0, $run['exit'], $run['output']);

    assert_same($beforeVol, $volumeCount($pdo));
    assert_same($beforeNode, $nodeCount($pdo));
    assert_same($beforeL,   $nodeLeft($pdo, $data['buyer']));
    assert_same($beforeR,   $nodeRight($pdo, $data['buyer']));
},

/* --------------------------------------------------------------
 *  P22H-T14: health pack
 * -------------------------------------------------------------- */

'P22H-T14 health pack: pass on a clean fixture, warn on zero-PV / unplaced, fail on drift' => function (): void {
    $pdo = db();

    /* Warm the settings cache from the DB, then switch the stream on for
     * this case (both roll back with the case's transaction). */
    setting('binary_enabled');
    setting_set('binary_enabled', '1');

    /* Build on the existing demo tree: one root already exists. Add one
     * placed member and one P.V. product. */
    $rootId = (int) $pdo->query(
        "SELECT user_id FROM binary_nodes WHERE state = 'root' LIMIT 1"
    )->fetchColumn();
    assert_not_null($rootId, 'the demo tree must carry a company root');

    $users = $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, member_status, is_active)
         VALUES (?, ?, 'x', ?, 'member', 'active', 1)"
    );
    $users->execute(['p22ht14_member', 'p22ht14_m@test.local', 'Member']);
    $memberId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO binary_nodes
           (user_id, upline_id, side, root_id, depth, path, state, placed_at, placed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
    )->execute([$memberId, $rootId, 'right', $rootId, 1, 'R', 'placed', $rootId]);

    $pdo->exec(
        "INSERT INTO products (sku, name, slug, price, pv, binary_enabled, stock)
         VALUES ('P22HT14', 'Health product', 'p22ht14', 100.00, 1000.00, 1, 10)"
    );

    /* A clean release log so the release-job check reports pass. */
    (new CronLog(null, 'unilevel_release'))->write(
        'OK',
        'stream=binary trigger=cli limit=200 hold=7 candidates=0 released_orders=0 released_awards=0 amount=0.00 skipped=0 errors=0 duration=5ms ids='
    );

    $sections = static function (PDO $p): array {
        $out = [];
        foreach ((new HealthService())->checks()['sections'] as $section) {
            $out[(string) $section['key']] = $section;
        }
        return $out;
    };

    $byKey = $sections($pdo);

    foreach (['binary_readiness', 'binary_points', 'binary_escrow', 'binary_release_job'] as $key) {
        assert_true(array_key_exists($key, $byKey), $key . ' is a health section');
        assert_true($byKey[$key]['ok'], $key . ' passes on a clean fixture: ' . $byKey[$key]['summary']);
        assert_false(str_contains((string) ($byKey[$key]['summary'] ?? ''), 'warn'), $key . ' does not warn on a clean fixture');
        assert_false(str_contains((string) ($byKey[$key]['summary'] ?? ''), 'fail'), $key . ' does not fail on a clean fixture');
    }

    /* A product flagged for the binary with P.V. 0 → readiness warns. */
    $pdo->exec(
        "INSERT INTO products (sku, name, slug, price, pv, binary_enabled, stock)
         VALUES ('P22HT14-ZERO', 'Zero point product', 'p22ht14-zero', 0.00, 0.00, 1, 10)"
    );
    $byKey = $sections($pdo);
    assert_true($byKey['binary_readiness']['ok']);
    assert_true(str_contains((string) ($byKey['binary_readiness']['summary'] ?? ''), 'warn'), $byKey['binary_readiness']['summary']);

    /* A member with no node → readiness warns. */
    $users->execute(['p22ht14_unplaced', 'p22ht14_u@test.local', 'Unplaced']);
    $byKey = $sections($pdo);
    assert_true($byKey['binary_readiness']['ok']);
    assert_true(str_contains((string) ($byKey['binary_readiness']['summary'] ?? ''), 'warn'), $byKey['binary_readiness']['summary']);

    /* Corrupt a cached balance → points fails. */
    $pdo->prepare('UPDATE binary_nodes SET left_points = 999999 WHERE user_id = ?')
        ->execute([$memberId]);
    $byKey = $sections($pdo);
    assert_false($byKey['binary_points']['ok'], $byKey['binary_points']['summary']);
    assert_true(str_contains((string) ($byKey['binary_points']['summary'] ?? ''), 'fail'), $byKey['binary_points']['summary']);
},
];
