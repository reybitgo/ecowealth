<?php
declare(strict_types=1);

/* ==============================================================
 *  PayoutTest — withdrawal requests, the hold and the queue
 *  (plan §5.9, §7 Phase 13; cases P13-T1 … P13-T13)
 *
 *  Every case runs inside the harness transaction, so the users it
 *  creates and the money it moves are rolled back afterwards (the
 *  concurrency case commits on purpose and cleans up by hand).
 *
 *  The service is exercised directly — the same calls the controllers
 *  make — so a failure here is a rule failure, not a rendering one.
 * ============================================================== */

$makeMember = static function (string $role = 'member'): array {
    return test_user('po_' . bin2hex(random_bytes(4)), $role);
};

/** Put money in a wallet the way a commission would (withdrawable). */
$fund = static function (int $userId, string $amount, bool $withdrawable = true): void {
    (new WalletService())->credit($userId, $amount, 'commission', null, 'Payout test funding', $withdrawable);
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

/** The payout settings every case starts from. */
$defaults = [
    'min_payout'               => '500',
    'service_fee_gcash'        => '0',
    'service_fee_maya'         => '0',
    'service_fee_usdt_trc20'   => '5',
    'service_fee_usdt_bep20'   => '5',
    'usdt_rate'                => '0',
    'usdt_trc20_gas_fee'       => '2.50',
    'usdt_bep20_gas_fee'       => '0.05',
    'payout_gcash_enabled'     => '1',
    'payout_maya_enabled'      => '1',
    'payout_usdt_trc20_enabled' => '1',
    'payout_usdt_bep20_enabled' => '1',
];

$gcash = '09171234567';
$trc20 = 'T' . str_repeat('1', 33);
$bep20 = '0x' . str_repeat('a', 40);

/** @return array<string, mixed>|null */
$payoutRow = static function (int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM payout_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
};

/** @return list<array<string, mixed>> */
$payoutRows = static function (): array {
    return db()->query('SELECT * FROM payout_requests ORDER BY id')->fetchAll();
};

/**
 * @return list<array<string, mixed>> the ledger legs of one request
 *
 * Includes an unlinked payout refund (ref_id = NULL) by its note, the
 * same way PayoutService::reconcile() finds one.
 */
$legs = static function (int $payoutId): array {
    $stmt = db()->prepare(
        "SELECT * FROM ewallet_ledger
          WHERE (ref_id = ? AND ref_type IN ('payout','refund'))
             OR (ref_type = 'refund' AND note LIKE ?)
          ORDER BY id"
    );
    $stmt->execute([$payoutId, 'Payout request #' . $payoutId . ' %']);

    return $stmt->fetchAll();
};

/** @return array{balance:float, withdrawable:float} */
$buckets = static function (int $userId): array {
    $stmt = db()->prepare('SELECT ewallet_balance, withdrawable_balance FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: ['ewallet_balance' => 0, 'withdrawable_balance' => 0];

    return [
        'balance'      => round((float) $row['ewallet_balance'], 2),
        'withdrawable' => round((float) $row['withdrawable_balance'], 2),
    ];
};

$service = static fn (): PayoutService => new PayoutService();

return [
    /* ---------- P13-T1: the happy path ---------------------------- */

    'P13-T1 a valid request holds the amount and queues a pending row' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow, $legs, $buckets): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRow, $legs, $buckets): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            $result = (new PayoutService())->request($memberId, 'gcash', $gcash, '800.00');

            assert_same('pending', $result['status']);
            assert_same(800.0, $result['amount']);
            assert_same(0.0, $result['fee_amount']);
            assert_same(800.0, $result['net_amount']);

            /* The hold came out of BOTH buckets — a payout is withdrawable
             * money — and the wallet still reconciles. */
            assert_same(['balance' => 1200.0, 'withdrawable' => 1200.0], $buckets($memberId));

            $row = $payoutRow((int) $result['payout_id']);

            assert_not_null($row);
            assert_same($memberId, (int) $row['user_id']);
            assert_same('800.00', number_format((float) $row['amount'], 2, '.', ''));
            assert_same('gcash', $row['payout_method']);
            assert_same($gcash, $row['payout_account']);
            assert_same('pending', $row['status']);
            assert_null($row['processed_by']);
            assert_null($row['processed_at']);

            /* One ledger row: the debit under ref_type = 'payout'. */
            $legRows = $legs((int) $result['payout_id']);
            assert_count(1, $legRows);
            assert_same('debit', $legRows[0]['type']);
            assert_same('payout', $legRows[0]['ref_type']);
            assert_same('800.00', number_format((float) $legRows[0]['amount'], 2, '.', ''));
            assert_same('1200.00', number_format((float) $legRows[0]['withdrawable_after'], 2, '.', ''));
            assert_contains('Payout request #' . (int) $result['payout_id'], (string) $legRows[0]['note']);

            $audit = db()->query(
                "SELECT * FROM audit_log WHERE action = 'payout.request' AND subject_id = " . (int) $result['payout_id']
            )->fetch();
            assert_not_null($audit, 'a request is audited');
            assert_same($memberId, (int) $audit['actor_id']);

            assert_true((new WalletService())->reconcile()['ok'], 'the wallet reconciles after the hold');
            assert_true((new PayoutService())->reconcile()['ok'], 'the request reconciles with its hold');
        });
    },

    /* ---------- P13-T2: the minimum ------------------------------- */

    'P13-T2 an amount below min_payout is refused and writes nothing' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRows, $buckets): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRows, $buckets, $withSettings): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '1000.00');

            $before = count($payoutRows());

            $error = assert_throws(
                static fn () => (new PayoutService())->request($memberId, 'gcash', $gcash, '499.99'),
                PayoutException::class
            );

            assert_same('minimum', $error->reason);
            assert_contains(money(500), $error->getMessage());
            assert_same($before, count($payoutRows()), 'a refused request writes no row');
            assert_same(['balance' => 1000.0, 'withdrawable' => 1000.0], $buckets($memberId));

            /* A zero floor opens the funnel: the same amount is then fine. */
            $withSettings(['min_payout' => '0'], function () use ($memberId, $gcash): void {
                $result = (new PayoutService())->request($memberId, 'gcash', $gcash, '499.99');

                assert_same(499.99, $result['amount']);
            });
        });
    },

    /* ---------- P13-T3: the withdrawable bucket ------------------- */

    'P13-T3 an amount above the withdrawable bucket is refused even when the balance covers it' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRows, $buckets): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRows, $buckets): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '600.00');            /* withdrawable */
            $fund($memberId, '900.00', false);     /* non-withdrawable */

            assert_same(['balance' => 1500.0, 'withdrawable' => 600.0], $buckets($memberId));

            $before = count($payoutRows());

            $error = assert_throws(
                static fn () => (new PayoutService())->request($memberId, 'gcash', $gcash, '1000.00'),
                PayoutException::class
            );

            assert_same('withdrawable', $error->reason);
            assert_contains(money(600), $error->getMessage());
            assert_same($before, count($payoutRows()));
            assert_same(['balance' => 1500.0, 'withdrawable' => 600.0], $buckets($memberId));

            /* Exactly the withdrawable part fits; the locked money stays. */
            (new PayoutService())->request($memberId, 'gcash', $gcash, '600.00');

            assert_same(['balance' => 900.0, 'withdrawable' => 0.0], $buckets($memberId));
        });
    },

    /* ---------- P13-T4: one open request -------------------------- */

    'P13-T4 a second request while one is open is refused' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRows): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRows): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '3000.00');

            $service = new PayoutService();
            $first   = $service->request($memberId, 'gcash', $gcash, '500.00');

            $error = assert_throws(
                static fn () => $service->request($memberId, 'gcash', $gcash, '500.00'),
                PayoutException::class
            );

            assert_same('open_request', $error->reason);
            assert_contains('pending', $error->getMessage());
            assert_same(1, count($payoutRows()));

            /* An approved request still holds the money: still refused. */
            $admin = test_user('po_admin_' . bin2hex(random_bytes(4)), 'admin');
            $service->approve((int) $first['payout_id'], (int) $admin['id']);

            $approvedRefusal = assert_throws(
                static fn () => $service->request($memberId, 'gcash', $gcash, '500.00'),
                PayoutException::class
            );

            assert_same('open_request', $approvedRefusal->reason);
            assert_contains('approved', $approvedRefusal->getMessage());

            /* Once it completes, the member may ask again. */
            $service->complete((int) $first['payout_id'], (int) $admin['id']);

            $second = $service->request($memberId, 'gcash', $gcash, '500.00');

            assert_same('pending', $second['status']);
            assert_same(2, count($payoutRows()));
        });
    },

    /* ---------- P13-T5: the service fee --------------------------- */

    'P13-T5 the service fee percentage is taken from the amount and stored' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow, $buckets): void {
        $withSettings(['service_fee_gcash' => '2.5'] + $defaults, function () use ($makeMember, $fund, $gcash, $payoutRow, $buckets): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            $result = (new PayoutService())->request($memberId, 'gcash', $gcash, '1000.00');

            assert_same(2.5, $result['fee_pct']);
            assert_same(25.0, $result['fee_amount'], '2.5% of 1000');
            assert_same(975.0, $result['net_amount']);

            /* The whole gross is held — the fee is company money, not a
             * second debit. */
            assert_same(['balance' => 1000.0, 'withdrawable' => 1000.0], $buckets($memberId));

            $row = $payoutRow((int) $result['payout_id']);

            assert_same('2.50', number_format((float) $row['service_fee_pct'], 2, '.', ''));
            assert_same('25.00', number_format((float) $row['service_fee_amount'], 2, '.', ''));

            /* A rounding case: 3% of 550.55 = 16.5165 → 16.52. */
            setting_set('service_fee_gcash', '3');
            setting_cache_clear();

            $rounded = (new PayoutService())->quote('gcash', '550.55');

            assert_same(16.52, $rounded['fee_amount']);
            assert_same(534.03, $rounded['net_amount']);
            assert_same(round(550.55 - 16.52, 2), $rounded['net_amount']);
        });
    },

    /* ---------- P13-T6: USDT -------------------------------------- */

    'P13-T6 a USDT request converts at the server-side rate and stores the rate and gas' => function () use ($makeMember, $fund, $withSettings, $defaults, $trc20, $payoutRow, $legs): void {
        $withSettings(['usdt_rate' => '56', 'service_fee_usdt_trc20' => '5'] + $defaults, function () use ($makeMember, $fund, $trc20, $payoutRow, $legs): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '5000.00');

            /* amount 1000 → fee 50 → net 950 → (950 − 2.5 × 56) / 56. */
            $expectedUsdt = round((950 - (2.5 * 56)) / 56, 4);

            $result = (new PayoutService())->request($memberId, 'usdt_trc20', $trc20, '1000.00');

            assert_true($result['usdt']);
            assert_same(56.0, $result['usdt_rate']);
            assert_same(2.5, $result['usdt_gas_fee']);
            assert_same(50.0, $result['fee_amount']);
            assert_same(950.0, $result['net_amount']);
            assert_same($expectedUsdt, $result['usdt_amount']);
            assert_same(14.4643, $result['usdt_amount'], 'the documented formula');

            $row = $payoutRow((int) $result['payout_id']);

            assert_same('56.0000', number_format((float) $row['usdt_rate'], 4, '.', ''));
            assert_same('2.5000', number_format((float) $row['usdt_gas_fee'], 4, '.', ''));
            assert_same('14.4643', number_format((float) $row['usdt_amount'], 4, '.', ''));
            assert_same($trc20, $row['payout_account']);

            /* The rate is a snapshot: changing the setting later does not
             * rewrite a queued request. */
            setting_set('usdt_rate', '60');
            setting_cache_clear();

            $again = $payoutRow((int) $result['payout_id']);
            assert_same('56.0000', number_format((float) $again['usdt_rate'], 4, '.', ''), 'the stored rate is a snapshot');

            /* The peso hold is unchanged: still 1000, one debit. */
            $legRows = $legs((int) $result['payout_id']);
            assert_count(1, $legRows);
            assert_same('1000.00', number_format((float) $legRows[0]['amount'], 2, '.', ''));
        });
    },

    'P13-T6 a USDT request is refused while usdt_rate is unset' => function () use ($makeMember, $fund, $withSettings, $defaults, $trc20, $payoutRows): void {
        $withSettings(['usdt_rate' => '0'] + $defaults, function () use ($makeMember, $fund, $trc20, $payoutRows): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            $before = count($payoutRows());

            $error = assert_throws(
                static fn () => (new PayoutService())->request($memberId, 'usdt_trc20', $trc20, '1000.00'),
                PayoutException::class
            );

            assert_same('rate', $error->reason);
            assert_same($before, count($payoutRows()), 'no request and no hold without a rate');

            /* A peso channel still works. */
            $result = (new PayoutService())->request($memberId, 'gcash', '09171234567', '1000.00');
            assert_same('gcash', $result['method']);
        });
    },

    /* ---------- P13-T7: approve ----------------------------------- */

    'P13-T7 approve records the staff account and the moment' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow, $buckets): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRow, $buckets): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('po_approver_' . bin2hex(random_bytes(4)), 'admin');

            $fund($memberId, '2000.00');

            $service = new PayoutService();
            $result  = $service->request($memberId, 'gcash', $gcash, '800.00');

            $approved = $service->approve((int) $result['payout_id'], (int) $admin['id'], 'Checked against the ledger');

            assert_same('approved', $approved['status']);
            assert_false($approved['refunded'], 'approving moves no money');

            $row = $payoutRow((int) $result['payout_id']);

            assert_same('approved', $row['status']);
            assert_same((int) $admin['id'], (int) $row['processed_by']);
            assert_not_null($row['processed_at']);
            assert_same('Checked against the ledger', $row['admin_note']);

            /* No wallet movement on approval: the hold was the debit. */
            assert_same(['balance' => 1200.0, 'withdrawable' => 1200.0], $buckets($memberId));

            $audit = db()->query(
                "SELECT * FROM audit_log WHERE action = 'payout.approve' AND subject_id = " . (int) $result['payout_id']
            )->fetch();
            assert_same((int) $admin['id'], (int) $audit['actor_id']);
            assert_same('admin', $audit['actor_role']);
        });
    },

    /* ---------- P13-T8: complete ---------------------------------- */

    'P13-T8 complete settles the request without a second debit' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow, $legs, $buckets): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRow, $legs, $buckets): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('po_settler_' . bin2hex(random_bytes(4)), 'admin');

            $fund($memberId, '2000.00');

            $service  = new PayoutService();
            $result   = $service->request($memberId, 'gcash', $gcash, '800.00');
            $payoutId = (int) $result['payout_id'];

            $service->approve($payoutId, (int) $admin['id']);

            $afterApprove = $buckets($memberId);

            $completed = $service->complete($payoutId, (int) $admin['id'], 'Sent 2026-11-10');

            assert_same('completed', $completed['status']);
            assert_false($completed['refunded']);

            $row = $payoutRow($payoutId);

            assert_same('completed', $row['status']);
            assert_same((int) $admin['id'], (int) $row['processed_by']);
            assert_same('Sent 2026-11-10', $row['admin_note']);

            /* Exactly one debit, ever; the buckets did not move again. */
            $legRows = $legs($payoutId);
            assert_count(1, $legRows);
            assert_same('debit', $legRows[0]['type']);
            assert_same($afterApprove, $buckets($memberId), 'completion moves no money');

            assert_true((new WalletService())->reconcile()['ok']);
            assert_true((new PayoutService())->reconcile()['ok']);
        });
    },

    /* ---------- P13-T9: reject + refund -------------------------- */

    'P13-T9 reject refunds the held amount exactly once and records the reason' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow, $legs, $buckets): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRow, $legs, $buckets): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('po_rejecter_' . bin2hex(random_bytes(4)), 'admin');

            $fund($memberId, '600.00', true);
            $fund($memberId, '400.00', false);

            $service  = new PayoutService();
            $result   = $service->request($memberId, 'gcash', $gcash, '500.00');
            $payoutId = (int) $result['payout_id'];

            assert_same(['balance' => 500.0, 'withdrawable' => 100.0], $buckets($memberId));

            $rejected = $service->reject($payoutId, (int) $admin['id'], 'Account name mismatch');

            assert_same('rejected', $rejected['status']);
            assert_true($rejected['refunded']);

            /* Both buckets are back exactly where they were, and the split
             * survived: 600 withdrawable + 400 locked. */
            assert_same(['balance' => 1000.0, 'withdrawable' => 600.0], $buckets($memberId));

            $row = $payoutRow($payoutId);

            assert_same('rejected', $row['status']);
            assert_same('Account name mismatch', $row['admin_note']);
            assert_same((int) $admin['id'], (int) $row['processed_by']);

            $legRows = $legs($payoutId);
            assert_count(2, $legRows);
            assert_same('debit', $legRows[0]['type']);
            assert_same('credit', $legRows[1]['type']);
            assert_same('refund', $legRows[1]['ref_type']);
            assert_same('500.00', number_format((float) $legRows[1]['amount'], 2, '.', ''));
            assert_contains('rejected', (string) $legRows[1]['note']);

            /* A second rejection is refused by the status guard, and the
             * ledger's idempotency key would refuse the refund anyway. */
            $again = assert_throws(
                static fn () => $service->reject($payoutId, (int) $admin['id'], 'second try'),
                PayoutException::class
            );

            assert_same('status', $again->reason);
            assert_same(['balance' => 1000.0, 'withdrawable' => 600.0], $buckets($memberId), 'no double refund');
            assert_count(2, $legs($payoutId));

            assert_true((new WalletService())->reconcile()['ok']);
            assert_true((new PayoutService())->reconcile()['ok']);
        });
    },

    'P13-T9 a rejection without a reason is refused' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRow): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('po_noreason_' . bin2hex(random_bytes(4)), 'admin');

            $fund($memberId, '1000.00');

            $service  = new PayoutService();
            $result   = $service->request($memberId, 'gcash', $gcash, '500.00');
            $payoutId = (int) $result['payout_id'];

            $error = assert_throws(
                static fn () => $service->reject($payoutId, (int) $admin['id'], '   '),
                PayoutException::class
            );

            assert_same('reason', $error->reason);
            assert_same('pending', $payoutRow($payoutId)['status'], 'the request is untouched');
        });
    },

    /* ---------- P13-T9: the shop-refund id collision --------------- */

    'P13-T9 a refund is still paid back when an order refund already uses that id' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $buckets, $legs): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $buckets, $legs): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('po_collide_' . bin2hex(random_bytes(4)), 'admin');

            $fund($memberId, '2000.00');

            $service  = new PayoutService();
            $result   = $service->request($memberId, 'gcash', $gcash, '500.00');
            $payoutId = (int) $result['payout_id'];

            /* Phase 7's order refunds share the `refund` ref_type and their
             * ids come from a different sequence: pretend this member's
             * order #<payoutId> was refunded first. */
            (new WalletService())->refund(
                $memberId,
                '10.00',
                $payoutId,
                'Refund — order EW-COLLIDE cancelled',
                10.0
            );

            $beforeReject = $buckets($memberId);

            $service->reject($payoutId, (int) $admin['id'], 'Account closed');

            /* The held 500 came back on top of the order's 10. */
            assert_same(
                ['balance' => round($beforeReject['balance'] + 500, 2), 'withdrawable' => round($beforeReject['withdrawable'] + 500, 2)],
                $buckets($memberId),
                'the payout refund was not mistaken for the order refund'
            );

            /* The order refund stayed linked; the payout refund went in
             * unlinked and named its request. */
            $rows = $legs($payoutId);
            assert_count(3, $rows, 'the hold, the order refund and the payout refund');

            $orderRefund = null;
            $payoutRefund = null;

            foreach ($rows as $row) {
                if ((string) $row['ref_type'] !== 'refund') {
                    continue;
                }

                if ((int) $row['user_id'] !== $memberId) {
                    continue;
                }

                if (str_starts_with((string) $row['note'], 'Payout request #' . $payoutId)) {
                    $payoutRefund = $row;
                } elseif (str_contains((string) $row['note'], 'EW-COLLIDE')) {
                    $orderRefund = $row;
                }
            }

            assert_not_null($orderRefund);
            assert_not_null($payoutRefund, 'the payout refund exists');
            assert_same($payoutId, (int) $orderRefund['ref_id'], 'the order refund keeps its id');
            assert_null($payoutRefund['ref_id'], 'the payout refund is unlinked');
            assert_same('500.00', number_format((float) $payoutRefund['amount'], 2, '.', ''));

            assert_true((new WalletService())->reconcile()['ok']);
            assert_true((new PayoutService())->reconcile()['ok'], 'the unlinked refund still reconciles');
        });
    },

    /* ---------- P13-T10: who may process -------------------------- */

    'P13-T10 a member cannot process a payout, and nobody can process their own' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRow): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            $service  = new PayoutService();
            $result   = $service->request($memberId, 'gcash', $gcash, '500.00');
            $payoutId = (int) $result['payout_id'];

            /* A plain member is not staff. */
            $notStaff = assert_throws(
                static fn () => $service->approve($payoutId, $memberId),
                PayoutException::class
            );

            assert_same('admin', $notStaff->reason);

            /* An admin who requested their own payout cannot settle it. */
            $admin   = $makeMember('admin');
            $adminId = (int) $admin['id'];

            $fund($adminId, '2000.00');

            $own      = $service->request($adminId, 'gcash', $gcash, '600.00');
            $ownId    = (int) $own['payout_id'];

            $ownApprove = assert_throws(
                static fn () => $service->approve($ownId, $adminId),
                PayoutException::class
            );

            assert_same('self', $ownApprove->reason);
            assert_same('pending', $payoutRow($ownId)['status']);

            $ownReject = assert_throws(
                static fn () => $service->reject($ownId, $adminId, 'no'),
                PayoutException::class
            );

            assert_same('self', $ownReject->reason);

            /* A different admin can process it. */
            $other = test_user('po_other_admin_' . bin2hex(random_bytes(4)), 'admin');
            $service->approve($ownId, (int) $other['id']);

            assert_same('approved', $payoutRow($ownId)['status']);

            /* A superadmin is staff too. */
            $superadmin = $makeMember('superadmin');
            $service->complete($ownId, (int) $superadmin['id']);

            assert_same('completed', $payoutRow($ownId)['status']);

            /* A missing request is a 404-shaped refusal, not a crash. */
            $missing = assert_throws(
                static fn () => $service->approve(999999, (int) $other['id']),
                PayoutException::class
            );

            assert_same('not_found', $missing->reason);
        });
    },

    /* ---------- P13-T11: the status guards ----------------------- */

    'P13-T11 double approve and out-of-order decisions are refused' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRow): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('po_guard_' . bin2hex(random_bytes(4)), 'admin');
            $adminId  = (int) $admin['id'];

            $fund($memberId, '3000.00');

            $service  = new PayoutService();
            $result   = $service->request($memberId, 'gcash', $gcash, '500.00');
            $payoutId = (int) $result['payout_id'];

            /* Complete before approve is refused. */
            $early = assert_throws(
                static fn () => $service->complete($payoutId, $adminId),
                PayoutException::class
            );

            assert_same('status', $early->reason);
            assert_contains('pending', $early->getMessage());

            $service->approve($payoutId, $adminId);

            /* Approving twice is refused. */
            $twice = assert_throws(
                static fn () => $service->approve($payoutId, $adminId),
                PayoutException::class
            );

            assert_same('status', $twice->reason);
            assert_contains('approved', $twice->getMessage());

            $service->complete($payoutId, $adminId);

            /* Completing (or approving) a completed request is refused. */
            foreach (['complete', 'approve'] as $action) {
                $after = assert_throws(
                    static fn () => $service->{$action}($payoutId, $adminId),
                    PayoutException::class
                );

                assert_same('status', $after->reason);
                assert_contains('completed', $after->getMessage());
            }

            /* Rejecting a completed request is refused too. */
            $reject = assert_throws(
                static fn () => $service->reject($payoutId, $adminId, 'too late'),
                PayoutException::class
            );

            assert_same('status', $reject->reason);

            /* The row kept one processed_by and one status transition. */
            $row = $payoutRow($payoutId);

            assert_same('completed', $row['status']);
            assert_same($adminId, (int) $row['processed_by']);
        });
    },

    /* ---------- P13-T12: reconciliation -------------------------- */

    'P13-T12 reconciliation matches every request to its hold and refund' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $trc20): void {
        $withSettings(['usdt_rate' => '50'] + $defaults, function () use ($makeMember, $fund, $gcash, $trc20): void {
            $admin   = test_user('po_recon_' . bin2hex(random_bytes(4)), 'admin');
            $adminId = (int) $admin['id'];

            $a = $makeMember();
            $b = $makeMember();
            $c = $makeMember();

            $fund((int) $a['id'], '2000.00');
            $fund((int) $b['id'], '2000.00');
            $fund((int) $c['id'], '2000.00');

            $service = new PayoutService();

            $completed = $service->request((int) $a['id'], 'gcash', $gcash, '600.00');
            $service->approve((int) $completed['payout_id'], $adminId);
            $service->complete((int) $completed['payout_id'], $adminId);

            $rejected = $service->request((int) $b['id'], 'gcash', $gcash, '500.00');
            $service->reject((int) $rejected['payout_id'], $adminId, 'Closed account');

            $pending = $service->request((int) $c['id'], 'usdt_trc20', $trc20, '700.00');

            $clean = $service->reconcile();

            assert_true($clean['ok'], 'three well-formed requests reconcile');
            assert_same(3, $clean['checked']);
            assert_same([], $clean['mismatches']);
            assert_same(700.0, $clean['held'], 'the pending request is the only money still held');

            /* Deleting the refund leg breaks the rejected request's
             * reconciliation — and is reported, not repaired. */
            db()->prepare(
                "DELETE FROM ewallet_ledger WHERE ref_type = 'refund' AND ref_id = ?"
            )->execute([(int) $rejected['payout_id']]);

            $broken = $service->reconcile();

            assert_false($broken['ok']);
            $kinds = array_column($broken['mismatches'], 'kind');
            assert_true(in_array('refund', $kinds, true), 'the refund leg is checked');

            /* A ledger leg whose request vanished is an orphan, not a
             * money error (account deletion cascades requests away). */
            db()->prepare('DELETE FROM payout_requests WHERE id = ?')->execute([(int) $pending['payout_id']]);

            $orphaned = $service->reconcile();

            assert_true($orphaned['orphans'] >= 1, 'the dangling hold is counted');
        });
    },

    /* ---------- P13-T13: the admin queue ------------------------- */

    'P13-T13 the queue filters, counts and totals agree with the rows' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash): void {
        $withSettings(['service_fee_gcash' => '2'] + $defaults, function () use ($makeMember, $fund, $gcash): void {
            $admin   = test_user('po_queue_' . bin2hex(random_bytes(4)), 'admin');
            $adminId = (int) $admin['id'];

            $alice = $makeMember();
            $bob   = $makeMember();
            $carol = $makeMember();

            foreach ([$alice, $bob, $carol] as $member) {
                $fund((int) $member['id'], '5000.00');
            }

            $service = new PayoutService();

            /* alice: completed 1000; bob: rejected 500; carol: pending 800. */
            $aliceRow = $service->request((int) $alice['id'], 'gcash', $gcash, '1000.00');
            $service->approve((int) $aliceRow['payout_id'], $adminId);
            $service->complete((int) $aliceRow['payout_id'], $adminId);

            $bobRow = $service->request((int) $bob['id'], 'gcash', $gcash, '500.00');
            $service->reject((int) $bobRow['payout_id'], $adminId, 'Test rejection');

            $carolRow = $service->request((int) $carol['id'], 'gcash', $gcash, '800.00');

            /* Counts per status. */
            $counts = Payout::statusCounts();

            assert_same(1, $counts['pending']);
            assert_same(0, $counts['approved']);
            assert_same(1, $counts['completed']);
            assert_same(1, $counts['rejected']);
            assert_same(3, $counts['all']);

            /* Totals: pending 800, completed 1000 (net 980 at 2%), one
             * rejection of 500. */
            $totals = Payout::totals();

            assert_same(800.0, $totals['pending_amount']);
            assert_same(1000.0, $totals['completed_amount']);
            assert_same(980.0, $totals['completed_net']);
            assert_same(20.0, $totals['fees_earned']);
            assert_same(500.0, $totals['rejected_amount']);
            assert_same(800.0, $totals['held_amount']);
            assert_same(1, $totals['held']);

            /* The status filter narrows both the rows and their totals. */
            $pendingFilters = Payout::normaliseFilters(['status' => 'pending']);
            $pendingPager   = new Paginator(10);

            assert_same(1, Payout::queueCount($pendingFilters));
            $rows = Payout::queue($pendingFilters, $pendingPager);
            assert_count(1, $rows);
            assert_same((string) $carol['username'], $rows[0]['member_username']);

            $pendingTotals = Payout::queueTotals($pendingFilters);
            assert_same(1, $pendingTotals['rows']);
            assert_same(800.0, $pendingTotals['amount']);
            assert_same(16.0, $pendingTotals['fees']);
            assert_same(784.0, $pendingTotals['net']);

            /* The search filter matches the member and the payout account. */
            $byName = Payout::normaliseFilters(['q' => (string) $bob['username']]);

            assert_same(1, Payout::queueCount($byName));
            assert_same(3, Payout::queueCount(Payout::normaliseFilters(['q' => $gcash])), 'every request paid to that number');

            /* A bogus status falls back to "all". */
            $bogus = Payout::normaliseFilters(['status' => 'settled']);

            assert_same('', $bogus['status']);
            assert_same(3, Payout::queueCount($bogus));

            /* The member's own figures. */
            $aliceTotals = Payout::memberTotals((int) $alice['id']);

            assert_same(1, $aliceTotals['requests']);
            assert_same(1000.0, $aliceTotals['requested']);
            assert_same(1000.0, $aliceTotals['paid_total']);
            assert_same(980.0, $aliceTotals['net_total']);
            assert_same(20.0, $aliceTotals['fees_total']);
            assert_same(0.0, $aliceTotals['held']);

            $carolTotals = Payout::memberTotals((int) $carol['id']);

            assert_same(800.0, $carolTotals['held'], 'an open request is money still held');

            assert_not_null(Payout::openFor((int) $carol['id']));
            assert_null(Payout::openFor((int) $alice['id']), 'a completed request is closed');
        });
    },

    /* ---------- Method and account rules ------------------------- */

    'the channel must exist and be switched on' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRows): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRows, $withSettings): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            $before = count($payoutRows());

            $unknown = assert_throws(
                static fn () => (new PayoutService())->request($memberId, 'palawan', '09170000000', '600.00'),
                PayoutException::class
            );

            assert_same('method', $unknown->reason);

            $withSettings(['payout_gcash_enabled' => '0'], function () use ($memberId, $gcash): void {
                $off = assert_throws(
                    static fn () => (new PayoutService())->request($memberId, 'gcash', $gcash, '600.00'),
                    PayoutException::class
                );

                assert_same('method_disabled', $off->reason);
                assert_contains('GCash', $off->getMessage());
            });

            assert_same($before, count($payoutRows()), 'neither refusal wrote a row');
        });
    },

    'the payout account is required and validated like the profile form' => function () use ($makeMember, $fund, $withSettings, $defaults, $trc20, $bep20, $payoutRow, $payoutRows): void {
        $withSettings(['usdt_rate' => '50'] + $defaults, function () use ($makeMember, $fund, $trc20, $bep20, $payoutRow, $payoutRows): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '5000.00');

            $before = count($payoutRows());

            $missing = assert_throws(
                static fn () => (new PayoutService())->request($memberId, 'gcash', '', '600.00'),
                PayoutException::class
            );

            assert_same('account', $missing->reason);
            assert_contains('GCash', $missing->getMessage());

            $badPhone = assert_throws(
                static fn () => (new PayoutService())->request($memberId, 'gcash', '12345', '600.00'),
                PayoutException::class
            );

            assert_same('account', $badPhone->reason);

            $badTrc20 = assert_throws(
                static fn () => (new PayoutService())->request($memberId, 'usdt_trc20', '0xnot-a-trc20-address', '600.00'),
                PayoutException::class
            );

            assert_same('account', $badTrc20->reason);

            $badBep20 = assert_throws(
                static fn () => (new PayoutService())->request($memberId, 'usdt_bep20', $trc20, '600.00'),
                PayoutException::class
            );

            assert_same('account', $badBep20->reason, 'a TRC20 address is not a BEP20 address');

            assert_same($before, count($payoutRows()), 'no invalid account was stored');

            /* A phone number typed the way people type it is normalised. */
            $service  = new PayoutService();
            $admin    = test_user('po_account_' . bin2hex(random_bytes(4)), 'admin');
            $ok       = $service->request($memberId, 'gcash', '0917 123 4567', '600.00');

            assert_same('09171234567', $ok['account']);
            assert_same('09171234567', $payoutRow((int) $ok['payout_id'])['payout_account']);

            /* Settle it so the next channel can be tried (one request at
             * a time is the rule P13-T4 pins). */
            $service->approve((int) $ok['payout_id'], (int) $admin['id']);
            $service->complete((int) $ok['payout_id'], (int) $admin['id']);

            /* The BEP20 form is accepted too. */
            $bep = $service->request($memberId, 'usdt_bep20', $bep20, '600.00');

            assert_same($bep20, $bep['account']);
        });
    },

    'the note is trimmed, collapsed and capped' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash, $payoutRow): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash, $payoutRow): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $admin    = test_user('po_note_' . bin2hex(random_bytes(4)), 'admin');

            $fund($memberId, '2000.00');

            $service  = new PayoutService();
            $result   = $service->request($memberId, 'gcash', $gcash, '600.00');
            $payoutId = (int) $result['payout_id'];

            $service->reject($payoutId, (int) $admin['id'], "  Not   the\taccount\nholder  ");

            assert_same('Not the account holder', $payoutRow($payoutId)['admin_note']);

            $long = str_repeat('x', 600);
            $other = $service->request($memberId, 'gcash', $gcash, '600.00');

            $service->approve((int) $other['payout_id'], (int) $admin['id'], $long);

            assert_same(500, strlen((string) $payoutRow((int) $other['payout_id'])['admin_note']));
        });
    },

    /* ---------- The quote is the one calculation ----------------- */

    'the quote reports both buckets of a request and refuses a swallowed net' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash): void {
        $withSettings(['service_fee_gcash' => '100'] + $defaults, function () use ($makeMember, $fund, $gcash): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];

            $fund($memberId, '2000.00');

            $service = new PayoutService();
            $quote   = $service->quote('gcash', '700.00');

            assert_same(100.0, $quote['fee_pct']);
            assert_same(700.0, $quote['fee_amount']);
            assert_same(0.0, $quote['net_amount']);

            $error = assert_throws(
                static fn () => $service->request($memberId, 'gcash', $gcash, '700.00'),
                PayoutException::class
            );

            assert_same('amount', $error->reason, 'a fee that leaves nothing is refused');
            assert_same(2000.0, (new WalletService())->balance($memberId));
        });
    },

    'the options a page renders know when a request is possible' => function () use ($makeMember, $fund, $withSettings, $defaults, $gcash): void {
        $withSettings($defaults, function () use ($makeMember, $fund, $gcash): void {
            $member   = $makeMember();
            $memberId = (int) $member['id'];
            $service  = new PayoutService();

            /* An empty wallet: nothing to take out yet. */
            $empty = $service->optionsFor(Wallet::account($memberId));

            assert_false($empty['can_request']);
            assert_same(0.0, $empty['withdrawable']);
            assert_same(500.0, $empty['min']);
            assert_count(4, $empty['methods']);

            /* Below the floor: still refused, and the page can say why. */
            $fund($memberId, '499.00');

            $below = $service->optionsFor(Wallet::account($memberId));

            assert_false($below['can_request']);
            assert_same(499.0, $below['withdrawable']);

            /* At the floor: the form opens. */
            $fund($memberId, '1.00');

            $ready = $service->optionsFor(Wallet::account($memberId));

            assert_true($ready['can_request']);
            assert_same(500.0, $ready['withdrawable']);
            assert_same(500.0, $ready['max_amount']);

            /* With an open request the form closes again. */
            $service->request($memberId, 'gcash', $gcash, '500.00');

            $open = $service->optionsFor(Wallet::account($memberId));

            assert_false($open['can_request']);
            assert_not_null($open['open']);
            assert_same('pending', $open['open']['status']);
        });
    },

    /* ---------- Concurrency -------------------------------------- */

    'a second request cannot hold money the first one already took' => function () use ($withSettings, $defaults, $gcash): void {
        /* No floor: the second submitter must reach the lock/one-open-
         * request guard, not be refused for being below a minimum first. */
        $withSettings(['min_payout' => '0'] + $defaults, function () use ($gcash): void {
            $pdo = db();

            /* This case commits on purpose (two real connections), so it
             * cleans up its own rows instead of relying on the rollback. */
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            $member   = test_user('po_race_' . bin2hex(random_bytes(4)));
            $memberId = (int) $member['id'];

            (new WalletService())->credit($memberId, '1000.00', 'commission', null, 'Race funding');

            $connectionA = test_pdo(['db' => DB_NAME]);
            $connectionB = test_pdo(['db' => DB_NAME]);
            $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $serviceA = new PayoutService($connectionA);
                $serviceB = new PayoutService($connectionB);

                /* A holds the member row inside its own transaction. */
                $connectionA->beginTransaction();

                $resultA = $serviceA->request($memberId, 'gcash', $gcash, '700.00');

                assert_same(700.0, $resultA['amount']);
                assert_same(300.0, $resultA['withdrawable_after']);

                /* B queues on the same row and times out. */
                $blocked = assert_throws(
                    static fn () => $serviceB->request($memberId, 'gcash', $gcash, '700.00'),
                    PDOException::class
                );

                assert_contains('1205', (string) $blocked->getCode() . ' ' . $blocked->getMessage());

                $connectionA->commit();

                /* Now B sees the open request — one request, one hold. */
                $error = assert_throws(
                    static fn () => $serviceB->request($memberId, 'gcash', $gcash, '100.00'),
                    PayoutException::class
                );

                assert_same('open_request', $error->reason);

                $stmt = db()->prepare('SELECT ewallet_balance, withdrawable_balance FROM users WHERE id = ?');
                $stmt->execute([$memberId]);
                $row = $stmt->fetch();

                assert_same(300.0, round((float) $row['ewallet_balance'], 2), 'exactly one hold landed');
                assert_same(300.0, round((float) $row['withdrawable_balance'], 2));

                $count = db()->prepare('SELECT COUNT(*) FROM payout_requests WHERE user_id = ?');
                $count->execute([$memberId]);
                assert_same(1, (int) $count->fetchColumn(), 'the blocked request wrote nothing');

                assert_true((new PayoutService())->reconcile()['ok'], 'payouts reconcile after the race');
            } finally {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$memberId]);
                $pdo->prepare("DELETE FROM audit_log WHERE action LIKE 'payout.%' AND subject_type = 'payout'")->execute();
            }
        });
    },
];
