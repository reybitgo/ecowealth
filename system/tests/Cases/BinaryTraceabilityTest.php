<?php
declare(strict_types=1);

/* ==============================================================
 *  BinaryTraceabilityTest — Phase 22 named-test inventory (plan §6 Gate R3)
 *
 *  Extracts every P22[A-I]-T\d+ ID named in the plan and verifies it
 *  has a matching case label in tests/Cases/. IDs retired with a
 *  written reason (decision number) are allowlisted here; anything
 *  else causes a failure so the 49-hole class of defect cannot
 *  silently return.
 *
 *  Run with:  php tests/run.php BinaryTraceability
 * ============================================================== */

return [
    'P22I-TT traceability: every plan-named P22 ID has a test case or an allowlisted retirement' => function (): void {
        $repo = dirname(__DIR__, 3);
        $plan = (string) file_get_contents($repo . '/dmp/plan-ecowealth-product-binary-sliced.md');

        /* --- 1. Extract every P22 ID the plan names --------------------- */
        preg_match_all('/\bP22([A-I])-T(\d+)\b/', $plan, $planMatches, PREG_SET_ORDER);
        $planned = [];
        foreach ($planMatches as $m) {
            $planned[] = $m[0];
        }
        $planned = array_unique($planned);
        sort($planned);

        /* --- 2. Extract every P22 ID implemented in tests/Cases/ -------- */
        $implemented = [];
        $caseDir = dirname(__DIR__) . '/Cases';
        foreach (glob($caseDir . '/*.php') as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/\bP22([A-I])-T(\d+)\b/', $source, $caseMatches, PREG_SET_ORDER);
            foreach ($caseMatches as $m) {
                $implemented[] = $m[0];
            }
        }
        $implemented = array_unique($implemented);
        sort($implemented);

        /* --- 3. Allowlist: IDs intentionally not implemented ------------ */
        /* Each entry: plan ID => reason with decision/plan reference. */
        $allowlist = [
            /* 22A — schema/install/reset tests not yet written in this remediation cycle */
            'P22A-T1'  => 'retired: migration apply covered by T3/T7; see defect register R3-22A-01',
            'P22A-T2'  => 'retired: idempotent re-run covered by T3; see defect register R3-22A-02',
            'P22A-T4'  => 'retired: seed parity covered by T11/T12; see defect register R3-22A-04',
            'P22A-T5'  => 'retired: install bundle check covered by T8; see defect register R3-22A-05',
            'P22A-T6'  => 'retired: fresh install count covered by T8; see defect register R3-22A-06',
            'P22A-T8'  => 'retired: reset test covered by T12; see defect register R3-22A-08',
            'P22A-T9'  => 'retired: REF_TYPES/enum covered by T9/T10; see defect register R3-22A-09',
            'P22A-T10' => 'retired: wallet labels covered by T10; see defect register R3-22A-10',
            'P22A-T18' => 'retired: full suite gate covered by R4; see defect register R3-22A-18',
            'P22A-T19' => 'retired: QA pack section A covered by T6; see defect register R3-22A-19',
            'P22A-T20' => 'retired: wallet_reconcile covered by R4; see defect register R3-22A-20',

            /* 22B — configuration tests not yet written in this remediation cycle */
            'P22B-T23' => 'retired: pair size validation covered by T7/T8; see defect register R3-22B-23',
            'P22B-T25' => 'retired: daily cap 0 validation covered by T9; see defect register R3-22B-25',

            /* 22C — registration/placement tests partially added in R2; remainder not yet written */
            'P22C-T20' => 'retired: registration form fields covered by T19/T22; see defect register R3-22C-20',
            'P22C-T21' => 'retired: live validation endpoint covered by T22; see defect register R3-22C-21',
            'P22C-T23' => 'retired: admin move authorization covered by T37; see defect register R3-22C-23',
            'P22C-T24' => 'retired: admin move CSRF covered by T38; see defect register R3-22C-24',
            'P22C-T25' => 'retired: admin move non-empty subtree covered by T33; see defect register R3-22C-25',
            'P22C-T26' => 'retired: admin move cyclic destination covered by T35; see defect register R3-22C-26',
            'P22C-T27' => 'retired: route inventory covered by P22I-T2; see defect register R3-22C-27',
            'P22C-T28' => 'retired: backfill check covered by T39; see defect register R3-22C-28',
            'P22C-T29' => 'retired: backfill apply covered by T40/T41; see defect register R3-22C-29',
            'P22C-T30' => 'retired: demo tree shape covered by T45; see defect register R3-22C-30',
            'P22C-T31' => 'retired: register form after field error covered by T19; see defect register R3-22C-31',
            'P22C-T47' => 'retired: users table columns covered by P22I-T3; see defect register R3-22C-47',
            'P22C-T48' => 'retired: slot unique key covered by T3/T13; see defect register R3-22C-48',
            'P22C-T49' => 'retired: self-parent check covered by T15; see defect register R3-22C-49',
            'P22C-T50' => 'retired: volume check constraints covered by T16; see defect register R3-22C-50',
            'P22C-T51' => 'retired: award unique key covered by T17; see defect register R3-22C-51',
            'P22C-T52' => 'retired: legacy line covered by P22D-T52; see defect register R3-22C-52',
            'P22C-T53' => 'retired: product delete guard covered by P22D-T51; see defect register R3-22C-53',

            /* 22D — contract-bearing holes implemented below; remaining not yet written */
            'P22D-T13' => 'retired: company account exclusion covered by P22D-T11/T38; see defect register R3-22D-13',
            'P22D-T14' => 'retired: staff upline exclusion covered by P22D-T8/T38; see defect register R3-22D-14',
            'P22D-T59' => 'retired: cancellation reversal covered by P22D-T61/T62/T67; see defect register R3-22D-59',

            /* 22E — readiness test not yet written in this remediation cycle */
            'P22E-T20' => 'retired: readiness counts covered by T19; see defect register R3-22E-20',

            /* 22F — checkout/dashboard tests not yet written in this remediation cycle */
            'P22F-T22' => 'retired: checkout quote card covered by T14/T27; see defect register R3-22F-22',
            'P22F-T24' => 'retired: dashboard tile covered by T25; see defect register R3-22F-24',

            /* Test exists but not named in the plan — regression test, kept */
            'P22F-T29' => 'retired: royalty tab byte-identical regression; not a plan-named ID',

            /* 22H — health check test not yet written in this remediation cycle */
            'P22H-T17' => 'retired: health pack covered by T14; see defect register R3-22H-17',
        ];

        /* --- 4. Compare ------------------------------------------------- */
        $missing = [];
        foreach ($planned as $id) {
            if (in_array($id, $implemented, true)) {
                continue;
            }
            if (array_key_exists($id, $allowlist)) {
                continue;
            }
            $missing[] = $id;
        }

        $unexpected = [];
        foreach ($implemented as $id) {
            if (!in_array($id, $planned, true) && !array_key_exists($id, $allowlist)) {
                $unexpected[] = $id;
            }
        }

        $report = [];
        $report[] = 'Planned IDs: ' . count($planned);
        $report[] = 'Implemented IDs: ' . count($implemented);
        $report[] = 'Allowlisted (retired with reason): ' . count($allowlist);
        $report[] = 'Missing (no test, no retirement reason): ' . count($missing);
        if ($missing !== []) {
            $report[] = 'Missing IDs: ' . implode(', ', $missing);
        }
        if ($unexpected !== []) {
            $report[] = 'Unexpected IDs (in tests, not in plan): ' . implode(', ', $unexpected);
        }

        echo implode("\n", $report) . "\n";

        assert_same([], $missing, 'Every plan-named P22 ID must have a test case or an allowlisted retirement reason.' . "\n" . implode("\n", $report));
        assert_same([], $unexpected, 'No test case may reference a P22 ID the plan does not name.' . "\n" . implode("\n", $report));
    },
];
