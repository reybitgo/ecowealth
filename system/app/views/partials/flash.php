<?php
declare(strict_types=1);

/**
 * Pending flash messages, rendered once at the top of the content area.
 * Included by app/layout/app_header.php for every routed page.
 */

$flash_html = flash_render();

if ($flash_html !== ''): ?>
            <div class="container-xxl pt-4">
              <?= $flash_html ?>
            </div>
<?php endif; ?>
