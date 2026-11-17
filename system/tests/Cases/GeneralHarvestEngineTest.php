<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestEngineTest — Phase 21D slice S1 (plan §6, §12.5,
 *  §14.2, §14.5, §14.7)
 *
 *  Proves the credited award path on its own, before the settlement
 *  engine wires registration:
 *
 *      P21D-T8   an award's wallet row — one General Harvest bonus
 *                credit, ref_type = 'general_harvest', ref_id =
 *                the award id, withdrawable bucket up by the amount
 *      P21D-T9   an award row — status credited, ledger_id set,
 *                credited_at/created_at set, amount = the ladder amount
 *      P21D-T28  duplicate (seq, level) insert — PDOException 23000
 *
 *  Each case runs inside the harness's rolled-back transaction, so the
 *  award and the credit commit together or vanish together (§14.7).
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestEngine
 * ============================================================== */

$fixtures = require __DIR__ . '/../fixtures/general_harvest.php';

$clean  = $fixtures['clean'];
$seed   = $fixtures['seedMarker'];
$switch = $fixtures['switch'];
$arm    = $fixtures['arm'];

return [

    /* ==========================================================
     *  P21D-T8 / T9 — create() + linkLedger() write an award and its
     *  wallet movement atomically (21D-S1)
     * ========================================================== */

    'P21D-T8 an award carries one General Harvest wallet credit, ref_id = the award id' => function () use (
        $clean,
        $seed,
        $switch,
        $arm
    ): void {
        $clean();

        /* Arm at N = 0: the board is armed but no baseline row exists, so
         * the credited path below writes on a clean (seq, level) key. The
         * settlement engine in 21D-S2 owns the baseline check; here we
         * prove the write primitives in isolation. */
        $seed();
        $result = $arm();
        $switch(true);

        assert_true((bool) $result['armed'], 'the board is armed at N = 0');
        assert_same(0, (int) $result['n'], 'N0 is zero on an empty queue');

        $holder = test_user('p21d_s1_t8');

        /* Node 0 level 1 completes at N = 4; nothing was baselined for it,
         * so a credited row is legal here. */
        $snapshot = [
            'user_id'     => (int) $holder['id'],
            'level'       => 1,
            'threshold_n' => 4,
            'n_at_award'  => 4,
            'amount'      => 2000.00,
            'credited_at' => date('Y-m-d H:i:s'),
        ];

        $written = GeneralHarvestAward::writeCredited(0, $snapshot);

        assert_true(array_key_exists('award_id', $written), 'writeCredited returns the award id');
        assert_true(array_key_exists('ledger_id', $written), 'writeCredited returns the ledger id');
        assert_true($written['award_id'] > 0, 'a positive award id');
        assert_true($written['ledger_id'] > 0, 'a positive ledger id');

        /* P21D-T9 — the award row: credited, linked, snapshotted. */
        $row = GeneralHarvestAward::find((int) $written['award_id']);

        assert_not_null($row, 'the award row exists');
        assert_same(0, (int) $row['seq'], 'node 0');
        assert_same(1, (int) $row['level'], 'level 1');
        assert_same('credited', (string) $row['status'], 'a credited row');
        assert_same((int) $written['ledger_id'], (int) $row['ledger_id'], 'ledger_id names the movement');
        assert_same(4, (int) $row['threshold_n'], 'the closed-form threshold');
        assert_same(4, (int) $row['n_at_award'], 'the queue length at award');
        assert_same(2000.0, round((float) $row['amount'], 2), 'the ladder amount, snapshotted');
        assert_not_null($row['credited_at'], 'credited_at is set');
        assert_not_null($row['created_at'], 'created_at is set');

        /* P21D-T8 — the wallet row: one General Harvest credit, ref_id =
         * the award id, withdrawable bucket up by the amount. */
        $ledger = db()->prepare(
            "SELECT id, user_id, type, amount, ref_type, ref_id, note
               FROM ewallet_ledger
              WHERE ref_type = 'general_harvest' AND ref_id = ?
               LIMIT 1"
        );
        $ledger->execute([(int) $written['award_id']]);
        $movement = $ledger->fetch();

        assert_not_null($movement, 'the wallet movement exists');
        assert_same((int) $holder['id'], (int) $movement['user_id'], 'the node holder is credited');
        assert_same('credit', (string) $movement['type'], 'a credit movement');
        assert_same('general_harvest', (string) $movement['ref_type'], 'the General Harvest ref type');
        assert_same((int) $written['award_id'], (int) $movement['ref_id'], 'ref_id is the award id');
        assert_same(2000.0, round((float) $movement['amount'], 2), 'the same amount as the award');

        $balance = db()->prepare(
            'SELECT withdrawable_balance FROM users WHERE id = ? LIMIT 1'
        );
        $balance->execute([(int) $holder['id']]);
        assert_same(2000.0, round((float) $balance->fetchColumn(), 2), 'the withdrawable bucket rose by the award');
    },

    'P21D-T9 a second award for the same node writes its own movement' => function () use (
        $clean,
        $seed,
        $switch,
        $arm
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $holder = test_user('p21d_s1_t9');

        $first = GeneralHarvestAward::writeCredited(0, [
            'user_id'     => (int) $holder['id'],
            'level'       => 1,
            'threshold_n' => 4,
            'n_at_award'  => 4,
            'amount'      => 2000.00,
            'credited_at' => date('Y-m-d H:i:s'),
        ]);

        /* A second level for the same node: distinct award and movement. */
        $second = GeneralHarvestAward::writeCredited(0, [
            'user_id'     => (int) $holder['id'],
            'level'       => 2,
            'threshold_n' => 13,
            'n_at_award'  => 13,
            'amount'      => 4000.00,
            'credited_at' => date('Y-m-d H:i:s'),
        ]);

        assert_not_same($first['award_id'], $second['award_id'], 'two different awards');
        assert_not_same($first['ledger_id'], $second['ledger_id'], 'two different movements');

        $rows = GeneralHarvestAward::forMember((int) $holder['id']);
        assert_count(2, $rows, 'the member holds both levels');

        $ledger = db()->query(
            "SELECT COUNT(*) FROM ewallet_ledger
              WHERE ref_type = 'general_harvest' AND ref_id IN ({$first['award_id']}, {$second['award_id']})"
        )->fetchColumn();
        assert_same(2, (int) $ledger, 'one movement per award');
    },

    /* ==========================================================
     *  P21D-T28 — the unique anchor holds (21D-S1)
     * ========================================================== */

    'P21D-T28 a duplicate (seq, level) insert is refused with PDOException 23000' => function () use (
        $clean,
        $seed,
        $switch,
        $arm
    ): void {
        $clean();

        $seed();
        $arm();
        $switch(true);

        $holder = test_user('p21d_s1_t28');
        $now    = date('Y-m-d H:i:s');

        GeneralHarvestAward::writeCredited(0, [
            'user_id'     => (int) $holder['id'],
            'level'       => 1,
            'threshold_n' => 4,
            'n_at_award'  => 4,
            'amount'      => 2000.00,
            'credited_at' => $now,
        ]);

        /* Re-deciding the same block (same seq, same level): the UNIQUE
         * (seq, level) anchor rejects it. */
        $exception = assert_throws(
            static fn () => GeneralHarvestAward::writeCredited(0, [
                'user_id'     => (int) $holder['id'],
                'level'       => 1,
                'threshold_n' => 4,
                'n_at_award'  => 4,
                'amount'      => 2000.00,
                'credited_at' => $now,
            ]),
            PDOException::class,
            'the duplicate insert throws'
        );

        assert_same('23000', $exception->getCode(), 'the unique-violation SQLSTATE');
    },

    /* ==========================================================
     *  S1 manifest — the write primitives ship with 21D
     * ========================================================== */

    'P21D-S1 the 21D write primitives exist on GeneralHarvestAward' => function (): void {
        foreach ([
            'create',
            'linkLedger',
            'writeCredited',
            'levelMap',
            'paidLevels',
            'gridFor',
        ] as $method) {
            assert_true(method_exists(GeneralHarvestAward::class, $method), 'GeneralHarvestAward::' . $method);
        }
    },

];
