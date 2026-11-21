<?php
declare(strict_types=1);

/* ==============================================================
 *  ParityTest — P1-T12 (plan §2.5, §3.3)
 *
 *  The reference system exposes 98 `?page=` values. Each one is
 *  mapped in app/routes_inventory.php to a route, a standalone
 *  page, a CLI job, or an explicit drop/defer.
 *
 *  This case fails when:
 *    • the 98-page inventory is lost, duplicated or miscounted;
 *    • a page marked Port has no target;
 *    • a route due in the current phase (APP_PHASE) is missing;
 *    • a route from a later phase is already registered;
 *    • a registered route belongs to no inventory entry at all.
 * ============================================================== */

$inventory  = require dirname(__DIR__, 2) . '/app/routes_inventory.php';
$pages      = $inventory['pages'] ?? [];
$extensions = $inventory['extensions'] ?? [];

$liveRoutes = static function (): array {
    $router = new Router(require dirname(__DIR__, 2) . '/app/routes.php');
    $live   = [];

    foreach ($router->routes() as $route) {
        $live[$route['method'] . ' ' . $route['path']] = $route;
    }

    return $live;
};

$dueRouteTargets = static function () use ($pages, $extensions): array {
    $due = [];

    foreach ($pages as $row) {
        if (($row['disposition'] ?? '') !== 'port' || (int) ($row['phase'] ?? 0) > APP_PHASE) {
            continue;
        }

        foreach ($row['targets'] ?? [] as $target) {
            if ($target[0] === 'ROUTE') {
                $due[$target[1] . ' ' . $target[2]] = $row['page'];
            }
        }
    }

    foreach ($extensions as $extension) {
        if ((int) $extension['phase'] <= APP_PHASE) {
            $due[$extension['method'] . ' ' . $extension['path']] = 'extension';
        }
    }

    ksort($due);

    return $due;
};

return [
    'P1-T12 inventory holds the 98 reference pages exactly once' => function () use ($pages): void {
        assert_count(98, $pages, 'reference page count');

        $names = array_map(static fn (array $row): string => (string) $row['page'], $pages);
        assert_count(98, array_unique($names), 'unique reference page names');

        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));
        assert_same([], $duplicates, 'duplicated reference pages');
    },

    'P1-T12 dispositions total 67 port · 8 defer · 23 drop' => function () use ($pages): void {
        $counts = ['port' => 0, 'defer' => 0, 'drop' => 0];

        foreach ($pages as $row) {
            $disposition = (string) ($row['disposition'] ?? '');
            assert_true(isset($counts[$disposition]), 'unknown disposition: ' . $disposition);
            $counts[$disposition]++;
        }

        assert_same(['port' => 67, 'defer' => 8, 'drop' => 23], $counts);
    },

    'P1-T12 port rows carry targets and a valid phase' => function () use ($pages): void {
        foreach ($pages as $row) {
            $name = (string) $row['page'];

            if ($row['disposition'] !== 'port') {
                assert_same([], $row['targets'], $name . ' is not ported but declares targets');
                assert_null($row['phase'], $name . ' is not ported but declares a phase');
                assert_true(trim((string) $row['note']) !== '', $name . ' needs a note');
                continue;
            }

            assert_true(($row['targets'] ?? []) !== [], $name . ' must declare at least one target');
            assert_true(
                is_int($row['phase']) && $row['phase'] >= 1 && $row['phase'] <= 15,
                $name . ' has an invalid phase'
            );
        }
    },

    'P1-T12 every target is well formed and every page target exists' => function () use ($pages): void {
        $public = dirname(__DIR__, 2) . '/public/';

        foreach ($pages as $row) {
            foreach ($row['targets'] ?? [] as $target) {
                assert_count(3, $target, $row['page'] . ' target shape');

                [$kind, $method, $path] = $target;

                switch ($kind) {
                    case 'ROUTE':
                        assert_true(in_array($method, ['GET', 'POST'], true), $row['page'] . ' route method');
                        assert_true(str_starts_with($path, '/'), $row['page'] . ' route path');
                        assert_true(
                            $path === '/' || !str_ends_with($path, '/'),
                            $row['page'] . ' route path must not end with "/"'
                        );
                        break;

                    case 'PAGE':
                        assert_true(
                            is_file($public . $path . '.php'),
                            $row['page'] . ' expects public/' . $path . '.php'
                        );
                        break;

                    case 'CLI':
                        assert_true($path !== '', $row['page'] . ' needs a CLI path');
                        break;

                    default:
                        fail($row['page'] . ' has an unknown target kind: ' . $kind);
                }
            }
        }
    },

    'P1-T12 every route due in this phase is registered' => function () use ($dueRouteTargets, $liveRoutes): void {
        $missing = array_diff_key($dueRouteTargets(), $liveRoutes());
        assert_same([], $missing, 'due routes missing from app/routes.php');
    },

    'P1-T12 no route from a later phase is registered early' => function () use ($pages, $extensions, $liveRoutes): void {
        $future = [];

        foreach ($pages as $row) {
            if (($row['disposition'] ?? '') !== 'port' || (int) ($row['phase'] ?? 0) <= APP_PHASE) {
                continue;
            }

            foreach ($row['targets'] ?? [] as $target) {
                if ($target[0] === 'ROUTE') {
                    $future[$target[1] . ' ' . $target[2]] = $row['page'];
                }
            }
        }

        foreach ($extensions as $extension) {
            if ((int) $extension['phase'] > APP_PHASE) {
                $future[$extension['method'] . ' ' . $extension['path']] = 'extension';
            }
        }

        $early = array_intersect_key($liveRoutes(), $future);
        assert_same([], $early, 'routes registered before their phase');
    },

    'P1-T12 every registered route belongs to the inventory' => function () use ($dueRouteTargets, $liveRoutes): void {
        $orphans = array_diff_key($liveRoutes(), $dueRouteTargets());
        assert_same([], $orphans, 'routes with no inventory entry');
    },

    'P1-T12 the live table has no duplicated method + path' => function () use ($liveRoutes): void {
        $live = $liveRoutes();
        assert_count(count($live), $live, 'duplicate method + path');
    },
];
