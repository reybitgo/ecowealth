<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — binary tree backfill (Phase 22C, plan §7)
 *
 *  Places every member who still has no binary node, in ascending id,
 *  under the nearest already-placed sponsor ancestor's first free slot
 *  (breadth-first, left before right, shallow before deep). A member
 *  whose sponsor chain has no placed ancestor goes under the company
 *  root's first free slot.
 *
 *  Idempotent: only touches state = 'floating' rows. Never moves an
 *  already-placed node, never deletes a node.
 *
 *      php bin/binary_place_tree.php          # report only (default)
 *      php bin/binary_place_tree.php --apply  # place and write
 *      php bin/binary_place_tree.php --dry-run # same as default
 *      php bin/binary_place_tree.php --limit=50
 *      php bin/binary_place_tree.php --json   # machine-readable
 *
 *  No new route: this is a CLI tool, consistent with D-18.
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

function binary_place_tree_options(bool $isCli): array
{
    $options = [
        'apply'    => false,
        'dry-run'  => true,
        'limit'    => null,
        'json'     => false,
        'help'     => false,
    ];

    if ($isCli) {
        $parsed = getopt('', ['apply', 'dry-run', 'limit:', 'json', 'help']);

        if (!is_array($parsed)) {
            return $options;
        }

        if (isset($parsed['apply'])) {
            $options['apply'] = true;
            $options['dry-run'] = false;
        }

        if (isset($parsed['dry-run'])) {
            $options['dry-run'] = true;
            $options['apply'] = false;
        }

        if (isset($parsed['limit'])) {
            $options['limit'] = (int) $parsed['limit'];
        }

        $options['json']  = isset($parsed['json']);
        $options['help']  = isset($parsed['help']);

        return $options;
    }

    foreach (['apply', 'dry-run', 'json', 'help'] as $flag) {
        $value = $_GET[$flag] ?? null;

        if ($value !== null
            && !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off', ''], true)) {
            $options[$flag] = true;
        }
    }

    if (isset($_GET['limit'])) {
        $options['limit'] = (int) $_GET['limit'];
    }

    return $options;
}

function binary_place_tree_usage(): string
{
    return implode(PHP_EOL, [
        'EcoWealth binary tree backfill (Phase 22C)',
        '',
        'Usage: php bin/binary_place_tree.php [flags]',
        '',
        '  --apply        place members and write rows (default: report only)',
        '  --dry-run      report what would be placed, change nothing',
        '  --limit=N      process at most N unplaced members',
        '  --json         machine-readable output',
        '  --help         this text',
        '',
        'Without --apply the tool only reports its plan and exits 0.',
    ]);
}

$options = binary_place_tree_options($isCli);

if ($options['help']) {
    echo binary_place_tree_usage();
    exit(0);
}

try {
    $service = new BinaryPlacementService();
    $result  = $service->backfill($options['apply'], $options['limit']);

    if ($options['json']) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'Binary tree backfill' . PHP_EOL;
        echo str_repeat('-', 40) . PHP_EOL;
        echo sprintf('  mode:        %s' . PHP_EOL, $options['apply'] ? 'apply' : 'check (dry run)');
        echo sprintf('  placed:      %d' . PHP_EOL, $result['placed']);
        echo sprintf('  root:        #%d' . PHP_EOL, $result['root']);
        echo sprintf('  max depth:   %d' . PHP_EOL, $result['max_depth']);
        echo sprintf('  floating:    %d' . PHP_EOL, $result['floating']);
        echo sprintf('  dry run:     %s' . PHP_EOL, $result['dry_run'] ? 'yes' : 'no');

        if ($result['levels'] !== []) {
            echo '  by depth:' . PHP_EOL;
            foreach ($result['levels'] as $depth => $count) {
                echo sprintf('    depth %d: %d' . PHP_EOL, $depth, $count);
            }
        }

        echo PHP_EOL;
        echo ($options['apply']
            ? 'Placement complete. Run --check to confirm nothing changes on a second pass.'
            : 'This was a dry run. Add --apply to place members.')
            . PHP_EOL;
    }

    exit(0);
} catch (Throwable $e) {
    $message = '[ecowealth] binary_place_tree: ' . $e->getMessage();

    if ($options['json']) {
        echo json_encode(['ok' => false, 'error' => $message]) . PHP_EOL;
    } else {
        error_log($message);
        fwrite(STDERR, $message . PHP_EOL);
    }

    exit(1);
}
