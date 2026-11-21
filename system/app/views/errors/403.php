<?php
declare(strict_types=1);

/**
 * 403 — access denied (plan §3.4). Rendered in the plain layout by
 * View::error(403); never a redirect loop.
 *
 * @var int    $code
 * @var string $heading
 * @var string $message
 */

$support = 'This area is reserved for a different role. Sign in with an account that has access, or contact the administrator if you believe this is a mistake.';

require __DIR__ . '/../partials/error_panel.php';
