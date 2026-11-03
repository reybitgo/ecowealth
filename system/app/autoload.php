<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — class autoloader (plan §3.6)
 *
 *  Global classes (no namespaces in this build) are loaded from
 *  app/Core, app/Models, app/Services and app/Controllers by class
 *  name: `Router` → app/Core/Router.php.
 * ============================================================== */

spl_autoload_register(static function (string $class): void {
    static $dirs = null;

    if ($dirs === null) {
        $dirs = [
            __DIR__ . '/Core/',
            __DIR__ . '/Models/',
            __DIR__ . '/Services/',
            __DIR__ . '/Controllers/',
        ];
    }

    $class = ltrim($class, '\\');

    /* Namespaced classes belong to a library, not to this app. */
    if ($class === '' || str_contains($class, '\\')) {
        return;
    }

    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});
