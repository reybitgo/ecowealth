<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — test runner (plan §8.2)
 *
 *      php tests/run.php                 # whole suite
 *      php tests/run.php Router         # cases whose name matches
 *      php tests/run.php --probe-failure # proves a failure exits 1
 *
 *  Every case file in tests/Cases/ returns:
 *
 *      ['P1-T4 404 page' => function (): void { … }, …]
 *
 *  Each case runs inside a transaction that is rolled back, so a
 *  case may create rows freely. Prints PASS/FAIL and exits non-zero
 *  when anything fails (P1-T7).
 * ============================================================== */

require __DIR__ . '/bootstrap.php';

$filter     = '';
$probeFails = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--probe-failure') {
        $probeFails = true;
        continue;
    }

    $filter = $argument;
}

$files = glob(__DIR__ . '/Cases/*.php') ?: [];
sort($files);

$total    = 0;
$passed   = 0;
$failures = [];

echo "EcoWealth test suite\n";
echo 'PHP ' . PHP_VERSION . ' · schema ' . DB_NAME . ' · phase ' . APP_PHASE . "\n";

foreach ($files as $file) {
    $group = basename($file, '.php');
    $cases = require $file;

    if (!is_array($cases) || $cases === []) {
        echo "\n$group\n  (no cases)\n";
        continue;
    }

    $printed = false;

    foreach ($cases as $label => $case) {
        if (!is_callable($case)) {
            continue;
        }

        if ($filter !== '' && stripos($group . ' ' . $label, $filter) === false) {
            continue;
        }

        if (!$printed) {
            echo "\n$group\n";
            $printed = true;
        }

        $total++;

        try {
            test_run_isolated($case);
            $passed++;
            echo "  \033[32mPASS\033[0m  $label\n";
        } catch (Throwable $e) {
            $failures[] = [$label, $e];
            echo "  \033[31mFAIL\033[0m  $label\n";

            $detail = $e instanceof TestFailure
                ? $e->getMessage()
                : $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();

            foreach (explode("\n", $detail) as $line) {
                echo "          $line\n";
            }
        }
    }
}

if ($probeFails) {
    $total++;

    try {
        assert_true(false, 'intentional probe failure');
    } catch (Throwable $e) {
        $failures[] = ['probe: a failing assertion exits non-zero', $e];
        echo "\nprobe\n  \033[31mFAIL\033[0m  probe: a failing assertion exits non-zero\n";
        echo '          ' . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat('-', 64) . "\n";
printf("Cases: %d   Passed: %d   Failed: %d\n", $total, $passed, count($failures));

if ($failures !== []) {
    echo "\nFailed cases:\n";
    foreach ($failures as [$label]) {
        echo "  - $label\n";
    }

    exit(1);
}

if ($total === 0) {
    echo "No cases matched" . ($filter !== '' ? " \"$filter\"" : '') . ".\n";
    exit(1);
}

echo "All good.\n";
exit(0);
