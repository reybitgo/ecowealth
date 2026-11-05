<?php
declare(strict_types=1);

/* ==============================================================
 *  WalletTest — dual bucket, ledger, top-ups, reconciliation
 *  (plan §4.3–4.4, §5.8, §7 Phase 5; tests P5-T1 … P5-T11)
 *
 *  Every case runs inside the harness transaction and rolls back, so
 *  it may create accounts and money freely. P5-T6 is the exception: a
 *  row lock is only observable across connections, so it commits its
 *  fixtures and deletes them in `finally`.
 * ============================================================== */

/** A fresh member with an empty wallet. */
$makeMember = static function (string $role = 'member'): array {
    return test_user('w_' . bin2hex(random_bytes(4)), $role);
};

$wallet = static fn (?PDO $pdo = null): WalletService => new WalletService($pdo);

/** One ledger row for a user (newest first). */
$ledgerRows = static function (int $userId): array {
    $stmt = db()->prepare('SELECT * FROM ewallet_ledger WHERE user_id = ? ORDER BY id ASC');
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
};

$money = static fn (mixed $value): string => number_format((float) $value, 2, '.', '');

return [
    /* ---------- P5-T1 / P5-T2: credits ---------------------------- */

    'P5-T1 a withdrawable credit raises both buckets and writes one ledger row' => function () use ($makeMember, $wallet, $ledgerRows, $money): void {
        $member = $makeMember();

        $result = $wallet()->credit((int) $member['id'], '1000.00', 'commission', 501, 'Level 1 commission');

        assert_same(1000.0, $result['balance_after']);
        assert_same(1000.0, $result['withdrawable_after']);
        assert_same(false, $result['replayed']);
        assert_same(1000.0, $wallet()->balance((int) $member['id']));
        assert_same(1000.0, $wallet()->withdrawable((int) $member['id']));

        $rows = $ledgerRows((int) $member['id']);
        assert_count(1, $rows);
        assert_same('credit', $rows[0]['type']);
        assert_same('1000.00', $money($rows[0]['amount']));
        assert_same('1000.00', $money($rows[0]['balance_after']));
        assert_same('1000.00', $money($rows[0]['withdrawable_after']));
        assert_same('commission', $rows[0]['ref_type']);
        assert_same(501, (int) $rows[0]['ref_id']);
        assert_same('Level 1 commission', $rows[0]['note']);
    },

    'P5-T2 a non-withdrawable credit raises the total only' => function () use ($makeMember, $wallet, $ledgerRows, $money): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '1000.00', 'commission', 601, 'Earnings');
        $wallet()->credit($id, '500.00', 'topup', 602, 'Admin credit', false);

        assert_same(1500.0, $wallet()->balance($id));
        assert_same(1000.0, $wallet()->withdrawable($id));

        $rows = $ledgerRows($id);
        assert_count(2, $rows);
        assert_same('1500.00', $money($rows[1]['balance_after']));
        assert_same('1000.00', $money($rows[1]['withdrawable_after']), 'the withdrawable bucket is untouched');
    },

    /* ---------- P5-T3 / P5-T4: debits ----------------------------- */

    'P5-T3 debitInternal spends non-withdrawable money first' => function () use ($makeMember, $wallet, $ledgerRows, $money): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '1000.00', 'commission', 701, 'Earnings');
        $wallet()->credit($id, '500.00', 'topup', 702, 'Admin credit', false);

        $result = $wallet()->debitInternal($id, '700.00', 'shop_order', 703, 'Order EW-1');

        assert_same(800.0, $result['balance_after']);
        assert_same(800.0, $result['withdrawable_after'], '500 non-withdrawable + 200 withdrawable spent');
        assert_same(800.0, $wallet()->balance($id));
        assert_same(800.0, $wallet()->withdrawable($id));

        $rows = $ledgerRows($id);
        $last = $rows[count($rows) - 1];
        assert_same('debit', $last['type']);
        assert_same('shop_order', $last['ref_type']);
        assert_same('800.00', $money($last['balance_after']));
        assert_same('800.00', $money($last['withdrawable_after']));
    },

    'P5-T4 a debit larger than the total is refused and writes nothing' => function () use ($makeMember, $wallet, $ledgerRows): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '100.00', 'commission', 801, 'Earnings');
        $before = $ledgerRows($id);

        $error = assert_throws(
            static fn () => $wallet()->debit($id, '150.00', 'payout', 802, 'Payout hold'),
            WalletException::class
        );

        assert_same('insufficient', $error->reason);
        assert_contains('Insufficient wallet balance', $error->getMessage());
        assert_same(100.0, $wallet()->balance($id), 'the total is unchanged');
        assert_same(100.0, $wallet()->withdrawable($id), 'the withdrawable bucket is unchanged');
        assert_same($before, $ledgerRows($id), 'the refused debit wrote no ledger row');
    },

    'P5-T4 a withdrawable debit cannot touch locked money' => function () use ($makeMember, $wallet): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '1000.00', 'commission', 811, 'Earnings');
        $wallet()->credit($id, '500.00', 'topup', 812, 'Admin credit', false);

        $error = assert_throws(
            static fn () => $wallet()->debit($id, '1200.00', 'payout', 813, 'Payout hold'),
            WalletException::class
        );

        assert_same('withdrawable', $error->reason);
        assert_same(1500.0, $wallet()->balance($id));
        assert_same(1000.0, $wallet()->withdrawable($id));

        /* The same amount is payable when it may spend the locked bucket. */
        $ok = $wallet()->debitInternal($id, '1200.00', 'shop_order', 814, 'Order');
        assert_same(300.0, $ok['balance_after']);
        assert_same(300.0, $ok['withdrawable_after'], '500 locked + 700 withdrawable spent, 300 of each left');
    },

    /* ---------- P5-T5: idempotency -------------------------------- */

    'P5-T5 replaying a system movement returns the original row' => function () use ($makeMember, $wallet, $ledgerRows): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $first  = $wallet()->credit($id, '250.00', 'commission', 901, 'Direct referral');
        $second = $wallet()->credit($id, '250.00', 'commission', 901, 'Direct referral');

        assert_same(false, $first['replayed']);
        assert_same(true, $second['replayed']);
        assert_same($first['ledger_id'], $second['ledger_id']);
        assert_same(250.0, $wallet()->balance($id), 'money moved exactly once');
        assert_count(1, $ledgerRows($id));

        /* A different movement kind under the same reference is a new row. */
        $wallet()->credit($id, '25.00', 'refund', 901, 'Refund');
        assert_count(2, $ledgerRows($id));
        assert_same(275.0, $wallet()->balance($id));

        /* Debits are just as idempotent. */
        $wallet()->debitInternal($id, '100.00', 'shop_order', 902, 'Order');
        $replay = $wallet()->debitInternal($id, '100.00', 'shop_order', 902, 'Order');
        assert_same(true, $replay['replayed']);
        assert_same(175.0, $wallet()->balance($id), 'the replayed debit did not charge twice');
    },

    'P5-T5 movements without a reference id are never collapsed' => function () use ($makeMember, $wallet, $ledgerRows): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '10.00', 'adjustment', null, 'Manual adjustment');
        $wallet()->credit($id, '10.00', 'adjustment', null, 'Manual adjustment');

        assert_same(20.0, $wallet()->balance($id));
        assert_count(2, $ledgerRows($id), 'manual adjustments are always new rows');
    },

    /* ---------- P5-T6: concurrency -------------------------------- */

    'P5-T6 concurrent debits cannot overdraw the wallet' => function (): void {
        $pdo = db();

        /* The second connection only sees committed rows. */
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $member = test_user('w_race_' . bin2hex(random_bytes(4)));
        $id     = (int) $member['id'];

        (new WalletService())->credit($id, '1000.00', 'commission', 1001, 'Starting balance');

        $connectionA = test_pdo(['db' => DB_NAME]);
        $connectionB = test_pdo(['db' => DB_NAME]);
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $serviceA = new WalletService($connectionA);
            $serviceB = new WalletService($connectionB);

            /* A debits ₱700 inside its own transaction: the account row is
             * locked and A's movement is not visible to B yet. */
            $connectionA->beginTransaction();
            $resultA = $serviceA->debitInternal($id, '700.00', 'shop_order', 1002, 'Order A');
            assert_same(300.0, $resultA['balance_after']);

            /* B queues on the same account row and times out. */
            $blocked = assert_throws(
                static fn () => $serviceB->debitInternal($id, '700.00', 'shop_order', 1003, 'Order B'),
                PDOException::class
            );
            assert_contains('1205', (string) $blocked->getCode() . ' ' . $blocked->getMessage());

            $connectionA->commit();

            /* Once A lands, B sees ₱300 and refuses — no negative bucket. */
            $error = assert_throws(
                static fn () => $serviceB->debitInternal($id, '700.00', 'shop_order', 1003, 'Order B'),
                WalletException::class
            );
            assert_same('insufficient', $error->reason);

            $balance = (new WalletService())->balance($id);
            $rows    = db()->prepare('SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = ?');
            $rows->execute([$id]);

            assert_same(300.0, $balance, 'exactly one debit landed');
            assert_same(2, (int) $rows->fetchColumn(), 'one opening credit + one debit');
            assert_true($balance >= 0, 'the total never goes negative');

            $buckets = db()->prepare('SELECT ewallet_balance, withdrawable_balance FROM users WHERE id = ?');
            $buckets->execute([$id]);
            $row = $buckets->fetch();
            assert_true((float) $row['withdrawable_balance'] <= (float) $row['ewallet_balance'] + 0.00001);
        } finally {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    },

    /* ---------- P5-T7: admin top-up ------------------------------- */

    'P5-T7 an admin top-up credits the non-withdrawable bucket and audits it' => function () use ($makeMember, $wallet, $ledgerRows, $money): void {
        $admin  = test_user('w_topup_admin', 'admin');
        $member = $makeMember();
        $id     = (int) $member['id'];

        $result = $wallet()->adminTopUp((int) $admin['id'], $id, '250.50', 'Welcome credit');

        assert_same(250.5, $wallet()->balance($id));
        assert_same(0.0, $wallet()->withdrawable($id), 'top-ups are never withdrawable');

        $topup = db()->query('SELECT * FROM ewallet_admin_topups WHERE id = ' . (int) $result['topup_id'])->fetch();
        assert_same((int) $admin['id'], (int) $topup['admin_id']);
        assert_same($id, (int) $topup['recipient_id']);
        assert_same('250.50', $money($topup['amount']));
        assert_same('Welcome credit', $topup['note']);

        $rows = $ledgerRows($id);
        assert_count(1, $rows);
        assert_same('topup', $rows[0]['ref_type']);
        assert_same((int) $result['topup_id'], (int) $rows[0]['ref_id']);
        assert_same('250.50', $money($rows[0]['balance_after']));
        assert_same('0.00', $money($rows[0]['withdrawable_after']));
        assert_contains('Admin top-up by @' . $admin['username'], (string) $rows[0]['note']);

        /* The audit trail the plan asks for (P5-T7). */
        $audit = db()->query("SELECT * FROM audit_log WHERE action = 'wallet.topup' AND subject_id = {$id} ORDER BY id DESC LIMIT 1")->fetch();
        assert_not_null($audit, 'the top-up must leave an audit row');
        assert_same((int) $admin['id'], (int) $audit['actor_id']);
        assert_same('admin', $audit['actor_role']);
        $meta = json_decode((string) $audit['meta_json'], true);
        assert_same('250.50', (string) $meta['amount']);
        assert_same((int) $result['topup_id'], (int) $meta['topup_id']);

        /* The member sees it in their statement. */
        $statement = $wallet()->statement($id, [], 1);
        assert_count(1, $statement['rows']);
        assert_same('topup', $statement['rows'][0]['ref_type']);
        assert_same(250.5, $statement['totals']['credits']);
    },

    'P5-T7 a non-admin cannot top up and a rejected top-up writes nothing' => function () use ($makeMember, $wallet): void {
        $member  = $makeMember();
        $other   = $makeMember();
        $id      = (int) $member['id'];
        $otherId = (int) $other['id'];

        $error = assert_throws(
            static fn () => $wallet()->adminTopUp($otherId, $id, '50.00', 'Nope'),
            WalletException::class
        );
        assert_same('forbidden', $error->reason);

        $amount = assert_throws(
            static fn () => $wallet()->adminTopUp((int) test_user('w_topup_admin', 'admin')['id'], $id, '0', 'Zero'),
            WalletException::class
        );
        assert_same('amount', $amount->reason);

        assert_same(0.0, $wallet()->balance($id));
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM ewallet_admin_topups WHERE recipient_id = ' . $id)->fetchColumn());
        assert_same(0, (int) db()->query('SELECT COUNT(*) FROM ewallet_ledger WHERE user_id = ' . $id)->fetchColumn());
    },

    /* ---------- P5-T8: schema drift ------------------------------- */

    'P5-T8 an unknown ref_type is refused before any write' => function () use ($makeMember, $wallet, $ledgerRows): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $credit = assert_throws(
            static fn () => $wallet()->credit($id, '10.00', 'not_a_movement', 1),
            WalletException::class
        );
        assert_same('ref_type', $credit->reason);

        $debit = assert_throws(
            static fn () => $wallet()->debitInternal($id, '10.00', 'not_a_movement', 1),
            WalletException::class
        );
        assert_same('ref_type', $debit->reason);

        assert_same(0.0, $wallet()->balance($id));
        assert_same([], $ledgerRows($id));
    },

    'P5-T8 the PHP ref_type list matches the live DB enum exactly' => function (): void {
        $column = db()->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'ref_type'")->fetch();
        assert_not_null($column, 'the ledger table must exist');

        preg_match_all("/'([^']+)'/", (string) $column['Type'], $matches);
        $dbValues = $matches[1];

        $php = WalletService::REF_TYPES;
        sort($dbValues);
        sort($php);

        assert_same($dbValues, $php, 'WalletService::REF_TYPES and the enum must agree in both directions');
    },

    /* ---------- P5-T9: statement filters -------------------------- */

    'P5-T9 the statement filters, paginates and totals exactly' => function () use ($makeMember, $wallet, $money): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '1000.00', 'commission', 2001, 'Direct referral');
        $wallet()->credit($id, '500.00', 'commission', 2002, 'Level 2 referral');
        $wallet()->credit($id, '200.00', 'topup', 2003, 'Welcome credit', false);
        $wallet()->debitInternal($id, '150.00', 'shop_order', 2004, 'Order EW-2401');
        $wallet()->debitInternal($id, '50.00', 'registration', 2005, 'Registration code');

        /* Spread the rows over dates so the range filter has something to do. */
        db()->exec("UPDATE ewallet_ledger SET created_at = '2026-01-05 09:00:00' WHERE user_id = {$id} AND ref_id = 2001");
        db()->exec("UPDATE ewallet_ledger SET created_at = '2026-01-10 09:00:00' WHERE user_id = {$id} AND ref_id = 2002");
        db()->exec("UPDATE ewallet_ledger SET created_at = '2026-02-01 09:00:00' WHERE user_id = {$id} AND ref_id IN (2003, 2004, 2005)");

        /* Pagination: two rows per page. */
        $page1 = $wallet()->statement($id, [], 1, 2);
        $page2 = $wallet()->statement($id, [], 2, 2);
        assert_count(2, $page1['rows']);
        assert_count(2, $page2['rows']);
        assert_same(5, $page1['pager']->total);
        assert_same(3, $page1['pager']->pages);
        assert_same(1700.0, $page1['totals']['credits']);
        assert_same(200.0, $page1['totals']['debits']);
        assert_same(1500.0, $page1['totals']['net']);
        assert_not_same((int) $page1['rows'][0]['id'], (int) $page2['rows'][0]['id']);

        /* Type filter. */
        $credits = $wallet()->statement($id, ['type' => 'credit']);
        assert_count(3, $credits['rows']);
        assert_same(1700.0, $credits['totals']['credits']);

        /* Source filter. */
        $commissions = $wallet()->statement($id, ['ref_type' => 'commission']);
        assert_count(2, $commissions['rows']);
        assert_same(1500.0, $commissions['totals']['credits']);

        /* Date range (inclusive). */
        $january = $wallet()->statement($id, ['from' => '2026-01-01', 'to' => '2026-01-31']);
        assert_count(2, $january['rows']);
        assert_same(1500.0, $january['totals']['credits']);

        /* Note search, including the "no wildcard" escape. */
        $welcome = $wallet()->statement($id, ['q' => 'welcome']);
        assert_count(1, $welcome['rows']);
        $none    = $wallet()->statement($id, ['q' => '%']);
        assert_count(0, $none['rows'], 'a bare % must be escaped, not match everything');

        /* Running balance = the balance recorded on the last row. */
        assert_same('1000.00', $money($wallet()->statement($id, [], 3, 2)['rows'][0]['balance_after']));
    },

    'P5-T9 invalid statement filters are ignored, never trusted' => function (): void {
        $filters = Wallet::normaliseFilters([
            'type'     => 'steal',
            'ref_type' => 'not_a_movement',
            'from'     => '2026-02-31',
            'to'       => 'not-a-date',
            'q'        => '  hello  ',
        ]);

        assert_same('', $filters['type']);
        assert_same('', $filters['ref_type']);
        assert_same('', $filters['from'], 'an impossible date is dropped');
        assert_same('', $filters['to']);
        assert_same('hello', $filters['q']);
    },

    /* ---------- P5-T10: immutability ------------------------------ */

    'P5-T10 the ledger has no update or delete path' => function (): void {
        /* 1. No updated_at column (the row is immutable by schema). */
        assert_false(
            db()->query("SHOW COLUMNS FROM ewallet_ledger LIKE 'updated_at'")->fetch() !== false,
            'ewallet_ledger must not carry updated_at'
        );

        /* 2. No ON UPDATE clause anywhere on the table. */
        foreach (db()->query('SHOW COLUMNS FROM ewallet_ledger')->fetchAll() as $column) {
            assert_not_contains('on update', strtolower((string) $column['Extra']));
        }

        /* 3. No trigger rewrites a ledger row. */
        $triggers = db()->query(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS
              WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'ewallet_ledger'"
        )->fetchColumn();
        assert_same(0, (int) $triggers);

        /* 4. No application code updates or deletes a ledger row. */
        $offenders = [];
        $root      = dirname(__DIR__, 2) . '/app/';
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/(UPDATE\s+ewallet_ledger|DELETE\s+FROM\s+ewallet_ledger)/i', $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        assert_same([], $offenders, 'immutability: no UPDATE/DELETE may target ewallet_ledger');

        /* 5. The models expose no edit/delete entry point either. */
        foreach ([WalletService::class, Wallet::class] as $class) {
            foreach (['update', 'delete', 'destroy', 'setBalance'] as $method) {
                assert_false(
                    method_exists($class, $method),
                    $class . ' must not expose ' . $method . '()'
                );
            }
        }
    },

    /* ---------- P5-T11: reconciliation ---------------------------- */

    'P5-T11 reconciliation is clean for a correct ledger' => function () use ($makeMember, $wallet): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '1000.00', 'commission', 3001, 'Earnings');
        $wallet()->credit($id, '250.00', 'topup', 3002, 'Top-up', false);
        $wallet()->debitInternal($id, '400.00', 'shop_order', 3003, 'Order');
        $wallet()->debit($id, '100.00', 'payout', 3004, 'Payout hold');

        $report = $wallet()->reconcile();

        assert_same(true, $report['ok'], 'reconciliation must be clean: ' . json_encode($report['mismatches']));
        assert_same([], $report['mismatches']);
        assert_same([], $report['violations']);
        assert_true($report['checked'] >= 1);
        assert_same(750.0, $wallet()->balance($id));
        assert_same(750.0, $wallet()->withdrawable($id));
    },

    'P5-T11 reconciliation catches a balance written outside the ledger' => function () use ($makeMember, $wallet): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '500.00', 'commission', 3101, 'Earnings');

        /* Bypass WalletService on purpose — exactly what reconciliation is for. */
        db()->exec('UPDATE users SET ewallet_balance = 999.99 WHERE id = ' . $id);

        $report = $wallet()->reconcile();
        assert_same(false, $report['ok']);

        $kinds = array_column(
            array_filter($report['mismatches'], static fn (array $row): bool => (int) $row['user_id'] === $id),
            'kind'
        );

        assert_true(in_array('balance_sum', $kinds, true), 'the ledger sum must disagree');
        assert_true(in_array('balance_snapshot', $kinds, true), 'the latest balance_after must disagree');

        /* A broken dual bucket is reported as an invariant violation. */
        db()->exec('UPDATE users SET ewallet_balance = 100.00, withdrawable_balance = 500.00 WHERE id = ' . $id);

        $report = $wallet()->reconcile();
        $violations = array_filter(
            $report['violations'],
            static fn (array $row): bool => (int) $row['user_id'] === $id
        );

        assert_count(1, $violations, 'withdrawable > total must be reported');
    },

    'P5-T11 reconciliation catches a missing ledger and a bad withdrawable' => function () use ($makeMember, $wallet): void {
        $unbacked = $makeMember();
        db()->exec('UPDATE users SET ewallet_balance = 50.00 WHERE id = ' . (int) $unbacked['id']);

        $report = $wallet()->reconcile();
        $kinds  = array_column(
            array_filter(
                $report['mismatches'],
                static fn (array $row): bool => (int) $row['user_id'] === (int) $unbacked['id']
            ),
            'kind'
        );
        assert_true(in_array('missing_ledger', $kinds, true));

        $member = $makeMember();
        $id     = (int) $member['id'];
        $wallet()->credit($id, '300.00', 'commission', 3201, 'Earnings');
        db()->exec('UPDATE users SET withdrawable_balance = 299.99 WHERE id = ' . $id);

        $report = $wallet()->reconcile();
        $kinds  = array_column(
            array_filter($report['mismatches'], static fn (array $row): bool => (int) $row['user_id'] === $id),
            'kind'
        );
        assert_true(in_array('withdrawable_snapshot', $kinds, true));
    },
];
