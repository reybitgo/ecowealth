<?php
declare(strict_types=1);

/**
 * 500 — unexpected failure. Technical detail is logged, never shown,
 * unless APP_DEBUG is on (plan §3.7).
 *
 * @var int     $code
 * @var string  $heading
 * @var string  $message
 * @var ?string $detail
 */

$detail  = $detail ?? null;
$support = 'The error has been logged. Please try again in a moment, or return to a familiar page below.';

require __DIR__ . '/../partials/error_panel.php';
