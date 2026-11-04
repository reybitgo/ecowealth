<?php
declare(strict_types=1);

/**
 * 404 — page not found. Rendered in the plain layout by View::error(404)
 * with HTTP 404 (P1-T4).
 *
 * @var int    $code
 * @var string $heading
 * @var string $message
 */

$support = 'Check the address for typos, or head back to a familiar page below.';

require __DIR__ . '/../partials/error_panel.php';
