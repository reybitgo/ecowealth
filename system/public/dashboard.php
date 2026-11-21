<?php
declare(strict_types=1);

/* ==============================================================
 *  /dashboard.php — legacy entry point (Phase 9)
 *
 *  The member dashboard became a routed page in Phase 9:
 *
 *      app/routes.php → DashboardController::index
 *      /dashboard     → the routed page (guard: member)
 *
 *  The standalone page that used to live here was removed; this shim
 *  only keeps old bookmarks and external links working by forwarding
 *  to the route. Nothing renders here.
 * ============================================================== */
require __DIR__ . '/../app/bootstrap.php';

redirect('dashboard');
