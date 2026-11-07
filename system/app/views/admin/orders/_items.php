<?php
declare(strict_types=1);

/**
 * Admin order page — ordered items.
 *
 * Read-only: the snapshot lines never change once the order is placed.
 *
 * @var array<string, mixed> $ctx
 */

$order = $ctx['order'];
$lines = $ctx['lines'];
?>
                  <div class="card mb-6" data-live-region="order-items">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Items</h5>
                      <span class="badge bg-label-primary"><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span>
                    </div>
<?= View::partial('order_items', ['order' => $order, 'lines' => $lines]) ?>
                  </div>
