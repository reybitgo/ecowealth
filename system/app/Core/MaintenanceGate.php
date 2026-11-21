<?php
declare(strict_types=1);

/* ==============================================================
 *  MaintenanceGate — checked by the front controller before any
 *  routing happens (plan §3.3).
 *
 *  The reference system `die()`d an HTML block from inside its
 *  router; the port keeps one tiny class for it. Admin and
 *  superadmin sessions bypass the gate, everyone else gets a
 *  branded 503 with Retry-After, as the plan requires.
 * ============================================================== */

final class MaintenanceGate
{
    public static function handle(): void
    {
        if (!setting_bool('maintenance_mode', false)) {
            return;
        }

        if (is_admin()) {
            return;
        }

        self::respond();
    }

    private static function respond(): never
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 3600');
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
        }

        $file = dirname(__DIR__) . '/views/errors/503.php';

        (static function () use ($file): void {
            require $file;
        })();

        exit;
    }
}
