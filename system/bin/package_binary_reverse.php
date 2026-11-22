#!/usr/bin/env php
<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — package binary activation reversal (Phase 23C-S4)
 *
 *  The only correction path for a package-binary payment (D-11).
 *  Dry run by default: prints what would change, writes nothing.
 *  --apply performs the reversal in one transaction:
 *
 *      void credited awards, best-effort clawback (binary_reversal),
 *      record reversed_amount and shortfall, return consumed pair
 *      points with pair_return rows, negate team volume with
 *      reversal rows, recompute the activation stamp, audit.
 *
 *  Idempotent: a second run finds the awards voided and the reversal
 *  rows present and changes nothing.
 *
 *      php bin/package_binary_reverse.php --activation=42          # dry run
 *      php bin/package_binary_reverse.php --activation=42 --apply  # perform
 *      php bin/package_binary_reverse.php --activation=42 --apply --reason="Duplicate approval"
 *      php bin/package_binary_reverse.php --activation=42 --apply --json
 *      php bin/package_binary_reverse.php --help
 *
 *  Exit codes: 0 done · 1 refused / not clean · 2 runtime error · 2 bad flags
 * ============================================================== */

$root = dirname(__DIR__);

require_once $root . '/app/config.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/autoload.php';
require_once $root . '/app/database.php';

date_default_timezone_set(APP_TIMEZONE);

$isCli = PHP_SAPI === 'cli';

/* --------------------------------------------------------------
 *  Options
 * -------------------------------------------------------------- */

/**
 * @return array{
 *   activation:int, apply:bool, json:bool, help:bool, quiet:bool,
 *   reason:string
 * }
 */
function package_binary_reverse_options(bool $isCli): array
{
    $options = [
        'activation' => 0,
        'apply'      => false,
        'json'       => false,
        'help'       => false,
        'quiet'      => false,
        'reason'     => 'Activation reversed',
    ];

    if ($isCli) {
        $parsed = getopt('', ['activation:', 'apply', 'json', 'help', 'quiet', 'reason:']);

        if (!is_array($parsed)) {
            return $options;
        }

        if (isset($parsed['activation']) && is_string($parsed['activation'])) {
            $options['activation'] = max(0, (int) $parsed['activation']);
        }

        $options['apply']  = isset($parsed['apply']);
        $options['json']   = isset($parsed['json']);
        $options['help']   = isset($parsed['help']);
        $options['quiet']  = isset($parsed['quiet']);

        if (isset($parsed['reason']) && is_string($parsed['reason'])) {
            $options['reason'] = trim($parsed['reason']);
        }

        return $options;
    }

    $options['activation'] = max(0, (int) ($_GET['activation'] ?? 0));
    $options['apply']      = isset($_GET['apply']);
    $options['json']       = isset($_GET['json']);
    $options['help']       = isset($_GET['help']);
    $options['quiet']      = isset($_GET['quiet']);
    $options['reason']     = trim((string) ($_GET['reason'] ?? 'Activation reversed'));

    return $options;
}

function package_binary_reverse_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth package-binary activation reversal (Phase 23C-S4)',
        '',
        'Usage: php bin/package_binary_reverse.php [flags]',
        '',
        '  --activation=ID  the activation to reverse (required)',
        '  --apply          perform the reversal (default: dry run, writes nothing)',
        '  --reason=<text>  audit/reversal reason',
        '  --json           machine-readable output',
        '  --quiet          no stdout (the log still records the run)',
        '  --help           this text',
        '',
        'Exit codes: 0 done · 1 refused / not clean · 2 runtime error',
        '',
        'The reversal is support-only: there is no in-app route (D-11).',
    ]);
}

/* --------------------------------------------------------------
 *  Output helpers
 * -------------------------------------------------------------- */

/** @param list<string> $lines @param array<string, mixed> $payload */
function package_binary_reverse_emit(bool $isCli, array $options, array $lines, array $payload): void
{
    if ($isCli && $options['quiet']) {
        return;
    }

    if ($options['json']) {
        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;
        return;
    }

    echo implode(PHP_EOL, $lines) . PHP_EOL;
}

/* --------------------------------------------------------------
 *  Run
 * -------------------------------------------------------------- */

$options = package_binary_reverse_options($isCli);

if ($options['help']) {
    echo package_binary_reverse_usage() . PHP_EOL;
    exit(0);
}

if ($options['activation'] <= 0) {
    $lines = [
        'EcoWealth package-binary activation reversal',
        '',
        'Error: --activation=ID is required.',
        '',
        package_binary_reverse_usage(),
    ];
    package_binary_reverse_emit($isCli, $options, $lines, [
        'ok' => false,
        'error' => '--activation=ID is required.',
    ]);
    exit(2);
}

try {
    $service = new PackageBinaryService();

    $dryRun = !$options['apply'];

    if ($dryRun) {
        $result = $service->reverseActivation(
            $options['activation'],
            ['apply' => false, 'reason' => $options['reason']]
        );

        $lines = [
            'EcoWealth package-binary reversal — dry run (no changes)',
            '  activation:   #' . $result['activation_id'],
            '  action:       reverse package-binary awards',
            '  would void:   ' . (int) $result['voided'] . ' award(s)',
            '  would claw:   ' . number_format((float) $result['recovered'], 2, '.', ''),
            '  shortfall:    ' . number_format((float) $result['shortfall'], 2, '.', ''),
            '  pair return:  ' . (int) $result['pair_returned'] . ' point(s)',
            '  volume rev:   ' . (int) $result['points_reversed'] . ' point(s)',
        ];

        if ($result['awards'] !== []) {
            $lines[] = '';
            $lines[] = '  awards:';
            foreach ($result['awards'] as $award) {
                $lines[] = sprintf(
                    '    #%d user %d %s amount %s → recover %s, shortfall %s',
                    (int) $award['award_id'],
                    (int) $award['user_id'],
                    (string) $award['kind'],
                    number_format((float) $award['amount'], 2, '.', ''),
                    number_format((float) $award['would_recover'], 2, '.', ''),
                    number_format((float) $award['would_shortfall'], 2, '.', '')
                );
            }
        }

        $lines[] = '';
        $lines[] = '  Re-run with --apply to perform this reversal.';
        $lines[] = '  Exit: 0';

        $payload = [
            'ok'       => true,
            'dry_run'  => true,
            'applied'  => false,
            'replayed' => (bool) ($result['replayed'] ?? false),
            'result'   => $result,
        ];
    } else {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $result = $service->reverseActivation(
                $options['activation'],
                ['apply' => true, 'reason' => $options['reason']]
            );

            $pdo->commit();

            $verb = (bool) ($result['replayed'] ?? false) ? 'already reversed — no change' : 'reversed';
            $lines = [
                'EcoWealth package-binary reversal — applied',
                '  activation:  #' . $result['activation_id'],
                '  action:      ' . $verb,
                '  voided:      ' . (int) $result['voided'] . ' award(s)',
                '  recovered:   ' . number_format((float) $result['recovered'], 2, '.', ''),
                '  shortfall:   ' . number_format((float) $result['shortfall'], 2, '.', ''),
                '  pair return: ' . (int) $result['pair_returned'] . ' point(s)',
                '  volume rev:  ' . (int) $result['points_reversed'] . ' point(s)',
                '  Exit: 0',
            ];

            $payload = [
                'ok'       => true,
                'dry_run'  => false,
                'applied'  => true,
                'replayed' => (bool) ($result['replayed'] ?? false),
                'result'   => $result,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    package_binary_reverse_emit($isCli, $options, $lines, $payload);
    exit(0);

} catch (PackageBinaryException $e) {
    $lines = [
        'EcoWealth package-binary reversal — refused',
        '  activation: #' . $options['activation'],
        '  reason:     ' . $e->getReason() . ' — ' . $e->getMessage(),
        '  Exit: 1',
    ];
    package_binary_reverse_emit($isCli, $options, $lines, [
        'ok'      => false,
        'error'   => $e->getMessage(),
        'reason'  => $e->getReason(),
    ]);
    exit(1);

} catch (Throwable $e) {
    $lines = [
        'EcoWealth package-binary reversal FAILED',
        '  activation: #' . $options['activation'],
        '  ' . $e->getMessage(),
        '  Exit: 2',
    ];
    package_binary_reverse_emit($isCli, $options, $lines, [
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
    exit(2);
}
