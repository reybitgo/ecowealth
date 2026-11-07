<?php
declare(strict_types=1);

/**
 * Admin order page — status badge and total.
 *
 * A small live region: another administrator moving the order forward
 * changes the badge without a page reload.
 *
 * @var array<string, mixed> $ctx
 */

$order = $ctx['order'];
$status = (string) $order['status'];
?>
<span class="d-inline-flex align-items-center gap-2" data-live-region="order-status">
  <?= status_badge($status) ?>
  <span class="fs-5 fw-semibold"><?= e(money((float) $order['total'])) ?></span>
</span>
