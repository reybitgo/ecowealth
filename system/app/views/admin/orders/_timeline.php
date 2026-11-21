<?php
declare(strict_types=1);

/**
 * Admin order page — status history.
 *
 * Read-only: new events appear here after any transition.
 *
 * @var array<string, mixed> $ctx
 */

$events = $ctx['events'];
?>
                  <div class="card" data-live-region="order-timeline">
                    <div class="card-header"><h5 class="mb-0">Status history</h5></div>
                    <div class="card-body">
<?= View::partial('order_timeline', ['events' => $events]) ?>
                    </div>
                  </div>
