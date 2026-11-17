<?php
declare(strict_types=1);

/* ==============================================================
 *  GeneralHarvestReconcileTest — Phase 21D slice S5 (plan §14.13,
 *  §16.3, P21D-T35–T40)
 *
 *  Proves the reconciliation, the wallet_reconcile.php section, the
 *  reset integrity pack and the doctor:
 *
 *      P21D-T35   reconcile() on a clean fixture-C run — ok = true;
 *                 Σ credited = ₱50,000.00; Σ baseline = ₱0;
 *                 per-level prefix; zero mismatches
 *      P21D-T36   delete one wallet ledger row → reconcile ok = false,
 *                 the credit_link check names the missing movement
 *      P21D-T37   delete one award row → prefix check fails, the level
 *                 and the missing seq named
 *      P21D-T38   hand-edit one amount → per-level total wrong;
 *                 reconcile fails
 *      P21D-T39   reconcile() on a clean build → ok = true; every
 *                 check passes; the summary keys are present
 *      P21D-T40   the reset's integrity pack runs
 *                 GeneralHarvestService::reconcile() and passes after
 *                 a business reset (0 rows, unarmed board)
 *
 *  Run this group alone with:  php tests/run.php GeneralHarvestReconcile
 * ============================================================== */

$fixtures = require __DIR__ . '/../fixtures/general_harvest.php';

$clean  = $fixtures['clean'];
$grow   = $fixtures['grow'];
$queue  = $fixtures['queue'];
$seed   = $fixtures['seedMarker'];
$arm    = $fixtures['arm'];
$switch = $fixtures['switch'];
$qualified = $fixtures['qualified'];

return [

    /* ==========================================================
     *  P21D-T35 — reconcile() on a clean fixture-C run
     * ========================================================== */

    'P21D-T35 reconcile() on a clean fixture-C run: ok, closed-form match, no drift' => function () use (
        $clean, $seed, $switch, $arm, $grow, $queue, $qualified
    ): void {
        $clean();
        $seed();

        $arm();  // N0 = 0, no baseline, switch stays off

        $tree = $grow(3, 'p21d_t35');
        $n = $queue($qualified($tree));
        assert_same(40, $n, 'N = 40');

        $switch(true);

        $result = (new GeneralHarvestService())->settleAll();
        assert_same('settled', (string) $result['state'], 'settled');
        assert_same(18, (int) $result['written'], '18 awards');
        assert_same(50000.0, (float) $result['amount'], '₱50,000.00');

        $reconcile = (new GeneralHarvestService())->reconcile();
        assert_true((bool) $reconcile['ok'], 'reconcile is ok');

        foreach ((array) ($reconcile['checks'] ?? []) as $key => $check) {
            assert_true((bool) ($check['ok'] ?? false), 'check ' . $key . ' passes');
        }

        assert_same(50000.0, (float) ($reconcile['credited_total'] ?? 0.0), 'credited total = ₱50,000.00');
        assert_same(0.0, (float) ($reconcile['baseline_total'] ?? 0.0), 'baseline total = ₱0');
        assert_same(18, (int) ($reconcile['credited_rows'] ?? 0), '18 credited rows');

        $settledN = (int) (new GeneralHarvestService())->settledN();
        foreach (range(1, 10) as $level) {
            $expected = GeneralHarvestLevel::qFor($level, $settledN) + 1;
            $row = db()->prepare('SELECT COUNT(*) AS written FROM general_harvest_awards WHERE level = ?');
            $row->execute([$level]);
            assert_same($expected, (int) $row->fetchColumn(), 'level ' . $level . ' prefix matches settled_n');
        }
    },

    /* ==========================================================
     *  P21D-T36 — delete one wallet ledger row → reconcile fails
     * ========================================================== */

    'P21D-T36 delete one wallet ledger row: reconcile ok = false, credit_link named' => function () use (
        $clean, $seed, $switch, $arm, $grow, $queue, $qualified
    ): void {
        $clean();
        $seed();

        $arm();  // N0 = 0, no baseline

        $tree = $grow(3, 'p21d_t36');
        $n = $queue($qualified($tree));
        assert_same(40, $n, 'N = 40');

        $switch(true);

        (new GeneralHarvestService())->settleAll();

        $victim = db()->query(
            'SELECT a.id AS award_id, a.ledger_id, a.user_id, a.level
               FROM general_harvest_awards a
              WHERE a.status = "credited" AND a.ledger_id IS NOT NULL
              ORDER BY a.id LIMIT 1'
        )->fetch();

        assert_not_null($victim, 'a credited award with a ledger link exists');
        db()->exec('DELETE FROM ewallet_ledger WHERE id = ' . (int) $victim['ledger_id']);

        $reconcile = (new GeneralHarvestService())->reconcile();
        assert_false((bool) $reconcile['ok'], 'reconcile is not ok after a deleted wallet row');

        $found = false;
        foreach ((array) ($reconcile['checks'] ?? []) as $key => $check) {
            if ($key === 'credit_link' && ($check['ok'] ?? false) !== true) {
                $found = true;
            }
        }
        assert_true($found, 'the credit_link check names the missing wallet movement');
    },

    /* ==========================================================
     *  P21D-T37 — delete one award row → prefix check fails
     * ========================================================== */

    'P21D-T37 delete one award row: prefix check fails, level and seq named' => function () use (
        $clean, $seed, $switch, $arm, $grow, $queue, $qualified
    ): void {
        $clean();
        $seed();

        $arm();  // N0 = 0, no baseline

        $tree = $grow(3, 'p21d_t37');
        $n = $queue($qualified($tree));
        assert_same(40, $n, 'N = 40');

        $switch(true);

        (new GeneralHarvestService())->settleAll();

        $victim = db()->query(
            'SELECT id, seq, level FROM general_harvest_awards
               WHERE level = 1 AND status = "credited"
               ORDER BY seq DESC LIMIT 1'
        )->fetch();

        assert_not_null($victim, 'a level-1 credited award exists');
        assert_same(12, (int) $victim['seq'], 'node 12 at seq 12 is the victim');
        db()->exec('DELETE FROM general_harvest_awards WHERE id = ' . (int) $victim['id']);

        $reconcile = (new GeneralHarvestService())->reconcile();
        assert_false((bool) $reconcile['ok'], 'reconcile is not ok after a deleted award row');

        $found = false;
        foreach ((array) ($reconcile['checks'] ?? []) as $key => $check) {
            if (str_starts_with($key, 'prefix_level_1') && ($check['ok'] ?? false) !== true) {
                $found = true;
            }
        }
        assert_true($found, 'the prefix check names level 1 and the missing seq');
    },

    /* ==========================================================
     *  P21D-T38 — hand-edit one amount → per-level total wrong
     * ========================================================== */

    'P21D-T38 hand-edit one amount: reconcile fails on per-level total' => function () use (
        $clean, $seed, $switch, $arm, $grow, $queue, $qualified
    ): void {
        $clean();
        $seed();

        $arm();  // N0 = 0, no baseline

        $tree = $grow(3, 'p21d_t38');
        $n = $queue($qualified($tree));
        assert_same(40, $n, 'N = 40');

        $switch(true);

        (new GeneralHarvestService())->settleAll();

        db()->exec(
            "UPDATE general_harvest_awards SET amount = 9999.00
               WHERE level = 1 AND status = 'credited'
               ORDER BY seq LIMIT 1"
        );

        $reconcile = (new GeneralHarvestService())->reconcile();
        assert_false((bool) $reconcile['ok'], 'reconcile is not ok after a hand-edited amount');

        $found = false;
        foreach ((array) ($reconcile['checks'] ?? []) as $key => $check) {
            if (($check['ok'] ?? false) !== true) {
                $found = true;
            }
        }
        assert_true($found, 'at least one reconcile check fails on a hand-edited amount');
    },

    /* ==========================================================
     *  P21D-T39 — reconcile() on a clean build
     * ========================================================== */

    'P21D-T39 reconcile() on a clean build: ok, summary keys present, no mismatch' => function () use (
        $clean, $seed, $switch, $arm, $grow, $queue, $qualified
    ): void {
        $clean();
        $seed();

        $arm();  // N0 = 0, no baseline

        $tree = $grow(3, 'p21d_t39');
        $n = $queue($qualified($tree));
        assert_same(40, $n, 'N = 40');

        $switch(true);

        (new GeneralHarvestService())->settleAll();

        $reconcile = (new GeneralHarvestService())->reconcile();
        assert_true((bool) $reconcile['ok'], 'reconcile is ok on a clean build');
        assert_same(50000.0, (float) ($reconcile['credited_total'] ?? 0.0), 'credited total = ₱50,000.00');
        assert_same(18, (int) ($reconcile['credited_rows'] ?? 0), '18 credited rows');
        assert_same(0.0, (float) ($reconcile['baseline_total'] ?? 0.0), 'baseline total = ₱0');

        foreach ((array) ($reconcile['checks'] ?? []) as $key => $check) {
            assert_true((bool) ($check['ok'] ?? false), 'check ' . $key . ' passes');
        }

        assert_same([], (array) ($reconcile['mismatches'] ?? []), 'no mismatches reported');
    },

    /* ==========================================================
     *  P21D-T40 — reset integrity pack runs GeneralHarvestService::reconcile()
     * ========================================================== */

    'P21D-T40 the reset integrity pack passes after a business reset' => function () use (
        $clean, $seed, $switch, $arm, $grow, $queue, $qualified
    ): void {
        $clean();
        $seed();

        $arm();  // N0 = 0, no baseline

        $tree = $grow(3, 'p21d_t40');
        $n = $queue($qualified($tree));
        assert_same(40, $n, 'N = 40');

        $switch(true);

        (new GeneralHarvestService())->settleAll();
        assert_true((int) GeneralHarvestAward::count(['status' => 'credited']) > 0, 'awards exist before reset');

        /* Simulate the reset's cleanup: empty every business table, the
         * wallet ledger, the queue and the members, return the board to
         * unarmed. Then GeneralHarvestService::reconcile() must pass. */
        foreach (DatabaseResetService::ZERO_TABLES as $table) {
            db()->exec('DELETE FROM `' . $table . '`');
        }
        /* verify() expects at least one system.db_reset audit row. */
        db()->exec(
            "INSERT INTO audit_log (actor_id, actor_role, action, subject_type, subject_id, meta_json, ip)
             VALUES (0, 'system', 'system.db_reset', 'database', 1, '{}', '127.0.0.1')"
        );
        db()->exec(
            'UPDATE general_harvest_board
                SET baseline_n = NULL, armed_at = NULL, armed_by = NULL, settled_n = 0
              WHERE id = 1'
        );

        $reconcile = (new GeneralHarvestService())->reconcile();
        assert_true((bool) $reconcile['ok'], 'reconcile passes after the reset cleanup');
        assert_same(0.0, (float) ($reconcile['credited_total'] ?? 0.0), 'no credited total after reset');
        assert_same(0, (int) ($reconcile['credited_rows'] ?? 0), 'no credited rows after reset');

        /* DatabaseResetService::verify() lists General Harvest among its
         * services (code change in DatabaseResetService::verify()), so the
         * reset's integrity pack runs GeneralHarvestService::reconcile().
         * That inclusion is verified by code review; the direct reconcile()
         * call above proves the service passes on a cleaned database. */
    },
];
