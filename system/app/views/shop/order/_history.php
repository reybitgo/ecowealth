<?php
declare(strict_types=1);

/**
 * Member order — event history card.
 *
 * @var list<array<string, mixed>> $events
 */
?>
                  <div class="card" data-live-region="member-order-history">
                    <div class="card-header"><h5 class="mb-0">History</h5></div>
                    <div class="card-body">
<?= View::partial('order_timeline', ['events' => $events]) ?>
                    </div>
                  </div>
