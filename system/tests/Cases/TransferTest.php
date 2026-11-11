<?php
declare(strict_types=1);

/* ==============================================================
 *  TransferTest — member-to-member wallet transfers (plan §5.8,
 *  §7 Phase 12; cases P12-T1 … P12-T12)
 *
 *  Every case runs inside the harness transaction, so the users it
 *  creates and the money it moves are rolled back afterwards (the
 *  concurrency case commits on purpose and cleans up by hand).
 *
 *  The service is exercised directly — the same call the controller
 *  makes — so a failure here is a rule failure, not a rendering one.
 * ============================================================== */

$makeMember = static function (string $role = 'member', string $password = 'Member@123'): array {
    return test_user('tr_' . bin2hex(random_bytes(4)), $role, $password);
};

/** Put money in a wallet the way a commission would (withdrawable). */
$fund = static function (int $userId, string $amount, bool $withdrawable = true): void {
    (new WalletService())->credit($userId, $amount, 'commission', null, 'Transfer test funding', $withdrawable);
};

/** @return array<string, mixed>|null */
$transferRow = static function (int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM ewallet_transfers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
};

/** @return list<array<string, mixed>> */
$transferRows = static function (): array {
    return db()->query('SELECT * FROM ewallet_transfers ORDER BY id')->fetchAll();
};

/** @return list<array<string, mixed>> */
$transferLedger = static function (int $transferId): array {
    $stmt = db()->prepare(
        "SELECT * FROM ewallet_ledger WHERE ref_type = 'transfer' AND ref_id = ? ORDER BY id"
    );
    $stmt->execute([$transferId]);

    return $stmt->fetchAll();
};

/** @return array{today:float, week:float} */
$counters = static function (int $userId): array {
    $stmt = db()->prepare('SELECT ewallet_sent_today, ewallet_sent_this_week FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: ['ewallet_sent_today' => 0, 'ewallet_sent_this_week' => 0];

    return [
        'today' => round((float) $row['ewallet_sent_today'], 2),
        'week'  => round((float) $row['ewallet_sent_this_week'], 2),
    ];
};

/** Run a case with temporary settings, restoring them afterwards. */
$withSettings = static function (array $values, callable $case): void {
    $before = [];

    foreach ($values as $key => $value) {
        $before[$key] = (string) setting($key, '');
        setting_set($key, (string) $value);
    }

    setting_cache_clear();

    try {
        $case();
    } finally {
        foreach ($before as $key => $value) {
            setting_set($key, $value);
        }

        setting_cache_clear();
    }
};

/** The transfer settings every case starts from (no fee, roomy limits). */
$defaults = [
    'ewallet_transfer_fee'          => '0',
    'ewallet_min_transfer'          => '50',
    'ewallet_transfer_daily_limit'  => '5000',
    'ewallet_transfer_weekly_limit' => '20000',
];

$wallet = static fn (): WalletService => new WalletService();

return [
    /* ---------- P12-T1: the happy path ---------------------------- */

    'P12-T1 a fee-free transfer moves the amount and writes two ledger rows' => function () use ($makeMember, $fund, $transferRow, $transferLedger, $counters, $withSettings, $defaults, $wallet): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $transferRow, $transferLedger, $counters, $wallet): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];
            $recipientId = (int) $recipient['id'];

            $fund($senderId, '1000.00');

            $result = (new TransferService())->send(
                $senderId,
                (string) $recipient['username'],
                '500.00',
                'Lunch share',
                'Member@123'
            );

            assert_same(500.0, $result['amount']);
            assert_same(0.0, $result['fee']);
            assert_same(500.0, $result['total_debit']);
            assert_same($senderId, $result['sender_id']);
            assert_same($recipientId, $result['recipient_id']);

            assert_same(500.0, $wallet()->balance($senderId), 'sender debited');
            assert_same(500.0, $wallet()->balance($recipientId), 'recipient credited');
            assert_same(0.0, $wallet()->withdrawable($recipientId), 'a transfer is never withdrawable');

            $transfer = $transferRow((int) $result['transfer_id']);
            assert_not_null($transfer);
            assert_same($senderId, (int) $transfer['sender_id']);
            assert_same($recipientId, (int) $transfer['recipient_id']);
            assert_same('500.00', number_format((float) $transfer['amount'], 2, '.', ''));
            assert_same('0.00', number_format((float) $transfer['fee'], 2, '.', ''));
            assert_same('500.00', number_format((float) $transfer['net_amount'], 2, '.', ''));
            assert_same('completed', $transfer['status']);
            assert_same('Lunch share', $transfer['note']);

            $ledger = $transferLedger((int) $result['transfer_id']);
            assert_count(2, $ledger, 'sender debit + recipient credit');
            assert_same('debit', $ledger[0]['type']);
            assert_same($senderId, (int) $ledger[0]['user_id']);
            assert_same('500.00', number_format((float) $ledger[0]['amount'], 2, '.', ''));
            assert_contains('Transfer to @' . $recipient['username'], (string) $ledger[0]['note']);
            assert_contains('Lunch share', (string) $ledger[0]['note']);

            assert_same('credit', $ledger[1]['type']);
            assert_same($recipientId, (int) $ledger[1]['user_id']);
            assert_contains('Transfer from @' . $sender['username'], (string) $ledger[1]['note']);
            assert_same('0.00', number_format((float) $ledger[1]['withdrawable_after'], 2, '.', ''), 'the credit is non-withdrawable');

            assert_same(['today' => 500.0, 'week' => 500.0], $counters($senderId), 'usage tracked on the sender');
            assert_same(['today' => 0.0, 'week' => 0.0], $counters($recipientId), 'the recipient is not limited by receiving');

            $audit = db()->query(
                "SELECT * FROM audit_log WHERE action = 'wallet.transfer' AND subject_id = {$recipientId} ORDER BY id DESC LIMIT 1"
            )->fetch();
            assert_not_null($audit, 'a transfer is audited');
            assert_same($senderId, (int) $audit['actor_id']);
            assert_same((int) $result['transfer_id'], (int) (json_decode((string) $audit['meta_json'], true)['transfer_id'] ?? 0));
        });
    },

    /* ---------- P12-T2: the fee ---------------------------------- */

    'P12-T2 a member pays the fee, which is credited to the primary admin' => function () use ($makeMember, $fund, $transferRow, $transferLedger, $counters, $withSettings, $defaults, $wallet): void {
        $withSettings(['ewallet_transfer_fee' => '10'] + $defaults, function () use ($makeMember, $fund, $transferRow, $transferLedger, $counters, $wallet): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];
            $recipientId = (int) $recipient['id'];
            $adminId   = (int) Member::primaryAdmin()['id'];

            $fund($senderId, '1000.00');

            $adminBefore = $wallet()->balance($adminId);

            $result = (new TransferService())->send(
                $senderId,
                (string) $recipient['username'],
                '300.00',
                '',
                'Member@123'
            );

            assert_same(10.0, $result['fee']);
            assert_same(310.0, $result['total_debit']);

            assert_same(690.0, $wallet()->balance($senderId), 'amount + fee left the sender');
            assert_same(300.0, $wallet()->balance($recipientId), 'the recipient gets the amount, not the debit');
            assert_same(round($adminBefore + 10, 2), $wallet()->balance($adminId), 'the fee is company money');
            assert_same($adminBefore, $wallet()->withdrawable($adminId), 'the fee is non-withdrawable');

            $transfer = $transferRow((int) $result['transfer_id']);
            assert_same('10.00', number_format((float) $transfer['fee'], 2, '.', ''));
            assert_same('300.00', number_format((float) $transfer['net_amount'], 2, '.', ''));

            $ledger = $transferLedger((int) $result['transfer_id']);
            assert_count(3, $ledger, 'sender debit + recipient credit + fee credit');
            assert_same($adminId, (int) $ledger[2]['user_id']);
            assert_same('credit', $ledger[2]['type']);
            assert_same('10.00', number_format((float) $ledger[2]['amount'], 2, '.', ''));
            assert_contains('Transfer fee from @' . $sender['username'], (string) $ledger[2]['note']);
            assert_contains('#' . (int) $result['transfer_id'], (string) $ledger[2]['note']);

            assert_same(['today' => 300.0, 'week' => 300.0], $counters($senderId), 'the limit counts the amount, not the fee');
        });
    },

    /* ---------- P12-T3: the minimum ------------------------------- */

    'P12-T3 an amount below the minimum is refused and writes nothing' => function () use ($makeMember, $fund, $transferRows, $counters, $withSettings, $defaults, $wallet): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $transferRows, $counters, $withSettings, $wallet): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];

            $fund($senderId, '1000.00');

            $before = count($transferRows());

            $error = assert_throws(
                static fn () => (new TransferService())->send(
                    $senderId,
                    (string) $recipient['username'],
                    '49.99',
                    '',
                    'Member@123'
                ),
                TransferException::class
            );

            assert_same('minimum', $error->reason);
            assert_contains(money(50), $error->getMessage());

            assert_same($before, count($transferRows()), 'a refused transfer writes no row');
            assert_same(1000.0, $wallet()->balance($senderId));
            assert_same(0.0, $wallet()->balance((int) $recipient['id']));
            assert_same(['today' => 0.0, 'week' => 0.0], $counters($senderId), 'counters stay untouched');

            /* A zero minimum means "no floor": the same 49.99 is then allowed. */
            $withSettings(['ewallet_min_transfer' => '0'], function () use ($senderId, $recipient): void {
                $result = (new TransferService())->send(
                    $senderId,
                    (string) $recipient['username'],
                    '49.99',
                    '',
                    'Member@123'
                );

                assert_same(49.99, $result['amount']);
            });
        });
    },

    /* ---------- P12-T4: the balance ------------------------------- */

    'P12-T4 a transfer larger than the balance is refused; counters stay put' => function () use ($makeMember, $fund, $transferRows, $counters, $withSettings, $defaults, $wallet): void {
        $withSettings(['ewallet_transfer_fee' => '5'] + $defaults, function () use ($makeMember, $fund, $transferRows, $counters, $wallet): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];

            $fund($senderId, '100.00');

            $before = count($transferRows());

            $error = assert_throws(
                static fn () => (new TransferService())->send(
                    $senderId,
                    (string) $recipient['username'],
                    '100.00',
                    '',
                    'Member@123'
                ),
                TransferException::class
            );

            assert_same('balance', $error->reason);
            assert_contains(money(105), $error->getMessage(), 'the message names amount + fee');

            assert_same($before, count($transferRows()));
            assert_same(100.0, $wallet()->balance($senderId));
            assert_same(['today' => 0.0, 'week' => 0.0], $counters($senderId));

            /* Exactly amount + fee fits. */
            $result = (new TransferService())->send(
                $senderId,
                (string) $recipient['username'],
                '95.00',
                '',
                'Member@123'
            );

            assert_same(100.0, $result['total_debit']);
            assert_same(0.0, $wallet()->balance($senderId));
            assert_same(95.0, $wallet()->balance((int) $recipient['id']));
        });
    },

    /* ---------- P12-T5: self-transfer ----------------------------- */

    'P12-T5 a member cannot transfer to themselves' => function () use ($makeMember, $fund, $transferRows, $withSettings, $defaults, $wallet): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $transferRows, $wallet): void {
            $sender   = $makeMember();
            $senderId = (int) $sender['id'];

            $fund($senderId, '500.00');

            $before = count($transferRows());

            $error = assert_throws(
                static fn () => (new TransferService())->send(
                    $senderId,
                    (string) $sender['username'],
                    '100.00',
                    '',
                    'Member@123'
                ),
                TransferException::class
            );

            assert_same('self', $error->reason);
            assert_same($before, count($transferRows()));
            assert_same(500.0, $wallet()->balance($senderId));
        });
    },

    /* ---------- P12-T6: the password ------------------------------ */

    'P12-T6 a wrong or missing password refuses before anything is written' => function () use ($makeMember, $fund, $transferRows, $counters, $withSettings, $defaults, $wallet): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $transferRows, $counters, $wallet): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];
            $recipientId = (int) $recipient['id'];

            $fund($senderId, '500.00');

            $before = count($transferRows());

            $wrong = assert_throws(
                static fn () => (new TransferService())->send(
                    $senderId,
                    (string) $recipient['username'],
                    '100.00',
                    '',
                    'not-the-password'
                ),
                TransferException::class
            );

            assert_same('password', $wrong->reason);
            assert_contains('password', strtolower($wrong->getMessage()));

            $missing = assert_throws(
                static fn () => (new TransferService())->send(
                    $senderId,
                    (string) $recipient['username'],
                    '100.00',
                    '',
                    ''
                ),
                TransferException::class
            );

            assert_same('password', $missing->reason);
            assert_same($before, count($transferRows()), 'no transfer row from either refusal');
            assert_same(500.0, $wallet()->balance($senderId));
            assert_same(0.0, $wallet()->balance($recipientId));
            assert_same(['today' => 0.0, 'week' => 0.0], $counters($senderId));

            /* The correct password then works: the refusal held no lock. */
            $result = (new TransferService())->send(
                $senderId,
                (string) $recipient['username'],
                '100.00',
                '',
                'Member@123'
            );

            assert_same($before + 1, count($transferRows()));
            assert_same(400.0, $wallet()->balance($senderId));
        });
    },

    /* ---------- P12-T7: the daily limit --------------------------- */

    'P12-T7 the daily limit allows exactly the limit and refuses one peso more' => function () use ($makeMember, $fund, $counters, $withSettings, $defaults, $wallet): void {
        $withSettings(['ewallet_transfer_daily_limit' => '300'] + $defaults, function () use ($makeMember, $fund, $counters, $wallet): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];

            $fund($senderId, '1000.00');

            $service = new TransferService();

            $service->send($senderId, (string) $recipient['username'], '200.00', '', 'Member@123');
            assert_same(200.0, $counters($senderId)['today']);

            /* At the limit: 200 + 100 = 300 is allowed. */
            $service->send($senderId, (string) $recipient['username'], '100.00', '', 'Member@123');
            assert_same(300.0, $counters($senderId)['today']);
            assert_same(700.0, $wallet()->balance($senderId));

            $error = assert_throws(
                static fn () => $service->send(
                    $senderId,
                    (string) $recipient['username'],
                    '50.00',
                    '',
                    'Member@123'
                ),
                TransferException::class
            );

            assert_same('daily_limit', $error->reason);
            assert_contains(money(300), $error->getMessage());
            assert_same(300.0, $counters($senderId)['today'], 'a refused transfer never counts');
            assert_same(700.0, $wallet()->balance($senderId));

            /* Switching the limit off opens the funnel again. */
            setting_set('ewallet_transfer_daily_limit', '0');
            setting_cache_clear();
            $service->send($senderId, (string) $recipient['username'], '50.00', '', 'Member@123');
            assert_same(350.0, $counters($senderId)['today']);
        });
    },

    /* ---------- P12-T8: the weekly limit -------------------------- */

    'P12-T8 the weekly limit is enforced independently of the daily one' => function () use ($makeMember, $fund, $counters, $withSettings, $defaults, $wallet): void {
        $withSettings(['ewallet_transfer_weekly_limit' => '400'] + $defaults, function () use ($makeMember, $fund, $counters, $wallet): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];

            $fund($senderId, '1000.00');

            $service = new TransferService();

            $service->send($senderId, (string) $recipient['username'], '300.00', '', 'Member@123');
            $service->send($senderId, (string) $recipient['username'], '100.00', '', 'Member@123');

            assert_same(400.0, $counters($senderId)['week']);
            assert_same(400.0, $counters($senderId)['today']);

            $error = assert_throws(
                static fn () => $service->send(
                    $senderId,
                    (string) $recipient['username'],
                    '50.00',
                    '',
                    'Member@123'
                ),
                TransferException::class
            );

            assert_same('weekly_limit', $error->reason);
            assert_contains(money(400), $error->getMessage());
            assert_same(400.0, $counters($senderId)['week']);

            /* Daily usage can be reset while the week is still spent. */
            $service->resetLimits(false);
            assert_same(0.0, $counters($senderId)['today']);
            assert_same(400.0, $counters($senderId)['week']);

            $stillWeekly = assert_throws(
                static fn () => $service->send(
                    $senderId,
                    (string) $recipient['username'],
                    '50.00',
                    '',
                    'Member@123'
                ),
                TransferException::class
            );

            assert_same('weekly_limit', $stillWeekly->reason);
        });
    },

    /* ---------- P12-T9: the reset job ----------------------------- */

    'P12-T9 the reset job zeroes daily counters every run and weekly ones on request' => function () use ($makeMember, $fund, $counters, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $counters): void {
            $first    = $makeMember();
            $second   = $makeMember();
            $target   = $makeMember();
            $service  = new TransferService();

            $fund((int) $first['id'], '1000.00');
            $fund((int) $second['id'], '1000.00');

            $service->send((int) $first['id'], (string) $target['username'], '100.00', '', 'Member@123');
            $service->send((int) $second['id'], (string) $target['username'], '250.00', '', 'Member@123');

            assert_same(100.0, $counters((int) $first['id'])['today']);
            assert_same(250.0, $counters((int) $second['id'])['week']);

            /* Daily only: the weekly counters survive. */
            $daily = $service->resetLimits(false);

            assert_true($daily['ok']);
            assert_false($daily['weekly_reset']);
            assert_same(2, $daily['daily_zeroed']);
            assert_same(0, $daily['weekly_zeroed']);
            assert_same(0.0, $counters((int) $first['id'])['today']);
            assert_same(100.0, $counters((int) $first['id'])['week']);
            assert_same(0.0, $counters((int) $second['id'])['today']);
            assert_same(250.0, $counters((int) $second['id'])['week']);

            /* Monday: the weekly counters go too. */
            $weekly = $service->resetLimits(true);

            assert_true($weekly['weekly_reset']);
            assert_same(0, $weekly['daily_zeroed'], 'the daily counters were already zero');
            assert_same(2, $weekly['weekly_zeroed']);
            assert_same(0.0, $counters((int) $first['id'])['week']);
            assert_same(0.0, $counters((int) $second['id'])['week']);

            /* Idempotent: a second run changes nothing. */
            $again = $service->resetLimits(true);

            assert_same(0, $again['daily_zeroed']);
            assert_same(0, $again['weekly_zeroed']);
            assert_true($again['checked'] >= 3);
        });
    },

    /* ---------- P12-T10: concurrency ------------------------------ */

    'P12-T10 a second transfer cannot spend money the first one already took' => function () use ($withSettings, $defaults): void {
        $withSettings($defaults, function (): void {
            $pdo = db();

            /* This case commits on purpose (two real connections), so it
             * cleans up its own rows instead of relying on the rollback. */
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            $sender    = test_user('tr_race_a_' . bin2hex(random_bytes(4)));
            $recipient = test_user('tr_race_b_' . bin2hex(random_bytes(4)));
            $senderId  = (int) $sender['id'];
            $recipientId = (int) $recipient['id'];
            $password  = 'Member@123';

            (new WalletService())->credit($senderId, '1000.00', 'commission', null, 'Race funding');

            $connectionA = test_pdo(['db' => DB_NAME]);
            $connectionB = test_pdo(['db' => DB_NAME]);
            $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $serviceA = new TransferService($connectionA);
                $serviceB = new TransferService($connectionB);

                /* A holds the sender row inside its own transaction. */
                $connectionA->beginTransaction();
                $resultA = $serviceA->send(
                    $senderId,
                    (string) $recipient['username'],
                    '700.00',
                    '',
                    $password
                );

                assert_same(700.0, $resultA['amount']);
                assert_same(300.0, $resultA['balance_after']);

                /* B queues on the same row and times out. */
                $blocked = assert_throws(
                    static fn () => $serviceB->send(
                        $senderId,
                        (string) $recipient['username'],
                        '700.00',
                        '',
                        $password
                    ),
                    PDOException::class
                );

                assert_contains('1205', (string) $blocked->getCode() . ' ' . $blocked->getMessage());

                $connectionA->commit();

                /* Now B sees ₱300 and is refused — never a negative wallet. */
                $error = assert_throws(
                    static fn () => $serviceB->send(
                        $senderId,
                        (string) $recipient['username'],
                        '700.00',
                        '',
                        $password
                    ),
                    TransferException::class
                );

                assert_same('balance', $error->reason);

                $balances = static function () use ($senderId, $recipientId): array {
                    $stmt = db()->prepare(
                        'SELECT id, ewallet_balance, withdrawable_balance FROM users WHERE id IN (?, ?) ORDER BY id'
                    );
                    $stmt->execute([$senderId, $recipientId]);

                    return $stmt->fetchAll();
                };

                $rows = $balances();
                assert_same(300.0, round((float) $rows[0]['ewallet_balance'], 2), 'exactly one debit landed');
                assert_same(700.0, round((float) $rows[1]['ewallet_balance'], 2));

                $transfers = db()->prepare(
                    'SELECT COUNT(*) FROM ewallet_transfers WHERE sender_id = ? AND recipient_id = ?'
                );
                $transfers->execute([$senderId, $recipientId]);
                assert_same(1, (int) $transfers->fetchColumn(), 'the blocked transfer wrote nothing');

                $reconcile = (new TransferService())->reconcile();
                assert_true($reconcile['ok'], 'transfers reconcile after the race');

                /* Both buckets still hold the two-bucket invariant. */
                foreach ($rows as $row) {
                    assert_true((float) $row['withdrawable_balance'] <= (float) $row['ewallet_balance'] + 0.00001);
                    assert_true((float) $row['ewallet_balance'] >= 0);
                }
            } finally {
                $pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$senderId, $recipientId]);
                $pdo->prepare("DELETE FROM audit_log WHERE action = 'wallet.transfer' AND subject_id = ?")
                    ->execute([$recipientId]);
            }
        });
    },

    /* ---------- P12-T11: the buckets ------------------------------ */

    'P12-T11 a transfer spends non-withdrawable money first and credits non-withdrawable money' => function () use ($makeMember, $fund, $withSettings, $defaults, $wallet): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $wallet): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];
            $recipientId = (int) $recipient['id'];

            $fund($senderId, '100.00');          /* withdrawable (earnings) */
            $fund($senderId, '100.00', false);   /* non-withdrawable (top-up) */

            assert_same(100.0, $wallet()->withdrawable($senderId));

            (new TransferService())->send($senderId, (string) $recipient['username'], '150.00', '', 'Member@123');

            assert_same(50.0, $wallet()->balance($senderId));
            assert_same(50.0, $wallet()->withdrawable($senderId), 'the non-withdrawable part was spent first');

            assert_same(150.0, $wallet()->balance($recipientId));
            assert_same(0.0, $wallet()->withdrawable($recipientId), 'the credit is non-withdrawable');

            /* Sending the rest can only come from the withdrawable bucket. */
            (new TransferService())->send($senderId, (string) $recipient['username'], '50.00', '', 'Member@123');

            assert_same(0.0, $wallet()->balance($senderId));
            assert_same(0.0, $wallet()->withdrawable($senderId));
            assert_same(200.0, $wallet()->balance($recipientId));
            assert_same(0.0, $wallet()->withdrawable($recipientId));

            $reconcile = (new WalletService())->reconcile();
            assert_true($reconcile['ok'], 'wallets still reconcile');
        });
    },

    /* ---------- P12-T12: staff ------------------------------------ */

    'P12-T12 staff transfer without a fee or a limit and are attributed to the primary admin' => function () use ($makeMember, $fund, $transferRow, $transferLedger, $counters, $withSettings): void {
        $withSettings([
            'ewallet_transfer_fee'          => '25',
            'ewallet_min_transfer'          => '50',
            'ewallet_transfer_daily_limit'  => '100',
            'ewallet_transfer_weekly_limit' => '100',
        ], function () use ($makeMember, $fund, $transferRow, $transferLedger, $counters): void {
            $superadmin = $makeMember('superadmin');
            $recipient  = $makeMember();

            $primaryAdmin = Member::primaryAdmin();
            assert_not_null($primaryAdmin, 'the fixture needs a primary admin');

            $adminId     = (int) $primaryAdmin['id'];
            $recipientId = (int) $recipient['id'];

            $fund($adminId, '1000.00');

            $wallet = new WalletService();
            $adminBefore = $wallet->balance($adminId);

            $result = (new TransferService())->send(
                (int) $superadmin['id'],
                (string) $recipient['username'],
                '500.00',
                'Staff transfer',
                'Member@123'
            );

            assert_same(0.0, $result['fee'], 'staff pay no fee');
            assert_same(0.0, $result['total_debit'] - $result['amount']);
            assert_same($adminId, $result['sender_id'], 'the money comes from the primary admin wallet');
            assert_same((int) $superadmin['id'], $result['actor_id']);

            assert_same(round($adminBefore - 500, 2), $wallet->balance($adminId), 'the limit did not apply');
            assert_same(500.0, $wallet->balance($recipientId));

            assert_same(['today' => 0.0, 'week' => 0.0], $counters($adminId), 'staff usage is never tracked');

            $transfer = $transferRow((int) $result['transfer_id']);
            assert_same($adminId, (int) $transfer['sender_id'], 'attributed in the transfer log too');
            assert_same('0.00', number_format((float) $transfer['fee'], 2, '.', ''));

            $ledger = $transferLedger((int) $result['transfer_id']);
            assert_count(2, $ledger);
            assert_same($adminId, (int) $ledger[0]['user_id']);

            /* The audit row keeps both identities. */
            $audit = db()->query(
                "SELECT * FROM audit_log WHERE action = 'wallet.transfer' AND subject_id = {$recipientId} ORDER BY id DESC LIMIT 1"
            )->fetch();
            assert_same((int) $superadmin['id'], (int) $audit['actor_id']);
            assert_same('superadmin', $audit['actor_role']);

            $meta = json_decode((string) $audit['meta_json'], true);
            assert_same($adminId, (int) $meta['sender_id']);
            assert_same((int) $superadmin['id'], (int) $meta['actor_id']);
        });
    },

    'an admin transfers without a fee' => function () use ($makeMember, $fund, $counters, $withSettings): void {
        $withSettings(['ewallet_transfer_fee' => '25'] + [
            'ewallet_min_transfer'          => '50',
            'ewallet_transfer_daily_limit'  => '5000',
            'ewallet_transfer_weekly_limit' => '20000',
        ], function () use ($makeMember, $fund, $counters): void {
            $admin     = $makeMember('admin');
            $recipient = $makeMember();
            $adminId   = (int) $admin['id'];

            $fund($adminId, '500.00');

            $result = (new TransferService())->send(
                $adminId,
                (string) $recipient['username'],
                '100.00',
                '',
                'Member@123'
            );

            assert_same(0.0, $result['fee']);
            assert_same(400.0, (new WalletService())->balance($adminId));
            assert_same(['today' => 0.0, 'week' => 0.0], $counters($adminId));
        });
    },

    /* ---------- Recipient rules ----------------------------------- */

    'the recipient must exist, be enabled and not be suspended' => function () use ($makeMember, $fund, $withSettings, $defaults, $wallet): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $wallet): void {
            $sender   = $makeMember();
            $senderId = (int) $sender['id'];
            $fund($senderId, '500.00');

            $service = new TransferService();

            $unknown = assert_throws(
                static fn () => $service->send($senderId, 'nobody_' . bin2hex(random_bytes(4)), '100.00', '', 'Member@123'),
                TransferException::class
            );
            assert_same('recipient', $unknown->reason);

            $empty = assert_throws(
                static fn () => $service->send($senderId, '   ', '100.00', '', 'Member@123'),
                TransferException::class
            );
            assert_same('recipient', $empty->reason);

            $suspended = $makeMember();
            Member::setStatus((int) $suspended['id'], 'suspended');

            $inactive = assert_throws(
                static fn () => $service->send($senderId, (string) $suspended['username'], '100.00', '', 'Member@123'),
                TransferException::class
            );
            assert_same('inactive', $inactive->reason);
            assert_same(500.0, $wallet()->balance($senderId), 'nothing was written');

            /* An admin account (the fixture inserts it as active) may still
             * receive: the rule is "enabled and not suspended". */
            $admin     = $makeMember('admin');
            $result    = $service->send($senderId, (string) $admin['username'], '100.00', '', 'Member@123');

            assert_same((int) $admin['id'], $result['recipient_id']);
            assert_same(100.0, $wallet()->balance((int) $admin['id']));
        });
    },

    'the note travels to the transfer row and both ledger rows' => function () use ($makeMember, $fund, $transferRow, $transferLedger, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $transferRow, $transferLedger): void {
            $sender    = $makeMember();
            $recipient = $makeMember();
            $senderId  = (int) $sender['id'];

            $fund($senderId, '500.00');

            $note   = "Rent   for\tMarch —  with spaces";
            $result = (new TransferService())->send(
                $senderId,
                (string) $recipient['username'],
                '120.00',
                $note,
                'Member@123'
            );

            $transfer = $transferRow((int) $result['transfer_id']);
            assert_same('Rent for March — with spaces', $transfer['note'], 'whitespace is normalised');

            $ledger = $transferLedger((int) $result['transfer_id']);
            assert_contains('Rent for March — with spaces', (string) $ledger[0]['note']);
            assert_contains('Rent for March — with spaces', (string) $ledger[1]['note']);
        });
    },

    'the fee falls back to an unlinked ledger row when the admin is the recipient' => function () use ($makeMember, $fund, $transferLedger, $withSettings, $defaults, $wallet): void {
        $withSettings(['ewallet_transfer_fee' => '10'] + $defaults, function () use ($makeMember, $fund, $transferLedger, $wallet): void {
            $sender       = $makeMember();
            $senderId     = (int) $sender['id'];
            $primaryAdmin = Member::primaryAdmin();
            $adminId      = (int) $primaryAdmin['id'];

            $fund($senderId, '500.00');
            $adminBefore = $wallet()->balance($adminId);

            /* The primary admin is also the recipient: the amount credit and
             * the fee credit must both exist despite the ledger's
             * (user, ref_type, ref_id, type) idempotency key. */
            $result = (new TransferService())->send(
                $senderId,
                (string) $primaryAdmin['username'],
                '100.00',
                '',
                'Member@123'
            );

            assert_same(110.0, $result['total_debit']);
            assert_same(390.0, $wallet()->balance($senderId));
            assert_same(round($adminBefore + 110, 2), $wallet()->balance($adminId));

            $rows = db()->prepare(
                "SELECT type, amount, ref_id, note FROM ewallet_ledger
                  WHERE ref_type = 'transfer' AND user_id = ? AND type = 'credit' ORDER BY id DESC LIMIT 2"
            );
            $rows->execute([$adminId]);
            $credits = $rows->fetchAll();

            assert_count(2, $credits);
            $total = 0.0;
            $unlinked = 0;

            foreach ($credits as $credit) {
                $total += (float) $credit['amount'];

                if ($credit['ref_id'] === null) {
                    $unlinked++;
                    assert_contains('#' . (int) $result['transfer_id'], (string) $credit['note']);
                }
            }

            assert_same(110.0, round($total, 2), 'the amount and the fee were both credited');
            assert_same(1, $unlinked, 'the fee row is unlinked when the admin is the recipient');

            $reconcile = (new TransferService())->reconcile();
            assert_true($reconcile['ok'], 'the fallback still reconciles');
        });
    },

    /* ---------- Reconciliation ------------------------------------ */

    'an orphan transfer leg is reported, not treated as a money error' => function () use ($makeMember, $fund, $withSettings, $defaults): void {
        $withSettings($defaults, function () use ($makeMember, $fund): void {
            $sender    = $makeMember();
            $recipient = $makeMember();

            $fund((int) $sender['id'], '500.00');

            $result = (new TransferService())->send(
                (int) $sender['id'],
                (string) $recipient['username'],
                '100.00',
                '',
                'Member@123'
            );

            $transferId = (int) $result['transfer_id'];

            /* Deleting the account of one party cascades the transfer row
             * away (a production install has no delete path, but the smoke
             * fixtures and manual cleanups delete accounts routinely) while
             * the counterparty's ledger legs legitimately remain. */
            db()->prepare('DELETE FROM ewallet_transfers WHERE id = ?')->execute([$transferId]);

            $reconcile = (new TransferService())->reconcile();

            assert_true($reconcile['ok'], 'orphan legs are not a leg mismatch');
            assert_same(2, $reconcile['orphans'], 'both dangling legs (sender debit + recipient credit) are counted');
            assert_same([], $reconcile['mismatches']);

            /* The wallets still reconcile: the money is in the ledger. */
            assert_true((new WalletService())->reconcile()['ok']);
        });
    },

    'reconciliation matches every transfer to its three ledger legs' => function () use ($makeMember, $fund, $withSettings, $defaults): void {
        $withSettings(['ewallet_transfer_fee' => '5'] + $defaults, function () use ($makeMember, $fund): void {
            $a = $makeMember();
            $b = $makeMember();
            $c = $makeMember();

            $fund((int) $a['id'], '1000.00');
            $fund((int) $b['id'], '1000.00');

            $service = new TransferService();
            $service->send((int) $a['id'], (string) $b['username'], '100.00', 'A to B', 'Member@123');
            $service->send((int) $b['id'], (string) $c['username'], '250.00', 'B to C', 'Member@123');

            $clean = $service->reconcile();

            assert_true($clean['ok'], 'two well-formed transfers reconcile');
            assert_same(2, $clean['checked']);
            assert_same([], $clean['mismatches']);

            /* Tamper with the amount: both legs stop matching. */
            db()->exec('UPDATE ewallet_transfers SET amount = amount + 1 ORDER BY id DESC LIMIT 1');

            $broken = $service->reconcile();

            assert_false($broken['ok']);
            assert_true(count($broken['mismatches']) >= 1);

            $kinds = array_column($broken['mismatches'], 'kind');
            assert_true(in_array('sender_debit', $kinds, true), 'the sender leg is checked');
            assert_true(in_array('recipient_credit', $kinds, true), 'the recipient leg is checked');
        });
    },
];
