<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — front controller (plan §3.3)
 *
 *  1. pin APP_BASE_URL (critical for deep URLs and asset paths)
 *  2. bootstrap config → helpers → autoload → database → auth
 *  3. validate a super-login (impersonation) token
 *  4. maintenance gate (before any route work)
 *  5. match the request against app/routes.php
 *  6. guard + CSRF + dispatch (Router)
 *  7. 404 for no match, branded 500 for anything thrown
 *
 *  No business logic here beyond that sequence.
 * ============================================================== */

/* 1. Base URL ---------------------------------------------------------------
 * index.php lives in public/; the browser reaches the app one level up
 * (system/.htaccess forwards /system/x → public/x). detect_app_base_url()
 * therefore yields "/" at a document root or "/ecowealth/system/" inside
 * Laragon's www — which keeps url() correct from deep URLs. */
if (!defined('APP_BASE_URL')) {
    require_once __DIR__ . '/../app/helpers.php';

    define('APP_BASE_URL', detect_app_base_url((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
}

require __DIR__ . '/../app/bootstrap.php';

/* 3–6. Dispatch ------------------------------------------------------------- */
try {
    /* Super-login: a request carrying `?imp=` is validated (TTL, IP/UA,
     * target status, hashed nonce) before any route or gate sees it. An
     * invalid session is destroyed and bounced to /slogin. */
    auth_validate_imp_session();

    MaintenanceGate::handle();

    /** @var list<array{0: string, 1: string, 2: array{0: string, 1: string}, 3?: string}> $routes */
    $routes = require __DIR__ . '/../app/routes.php';
    $router = new Router($routes);

    $match = $router->match(Request::method(), Request::path());

    if ($match === null) {
        View::error(404);
    }

    $router->dispatch($match);
} catch (Throwable $e) {
    error_log(sprintf(
        '[ecowealth] %s in %s:%d — %s',
        $e::class,
        $e->getFile(),
        $e->getLine(),
        $e->getMessage()
    ));

    View::error(500, [
        'detail' => APP_DEBUG ? $e::class . ': ' . $e->getMessage() . "\n\n" . $e->getTraceAsString() : null,
    ]);
}
