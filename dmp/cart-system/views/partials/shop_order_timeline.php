<?php
/**
 * @file   views/partials/shop_order_timeline.php
 * @brief  Vertical event timeline shared by the member detail page and the
 *         admin fulfilment desk (§5.8) — a buyer and staff read the same
 *         history. Expects: $events (ShopOrderEvent::forOrder()).
 *         payload is NOT rendered; admins get a details block via the desk.
 */
$actorLabel = [
    'customer' => 'You',
    'admin'    => 'Staff',
    'system'   => 'System',
];
?>
<div class="shop-timeline">
  <?php if (empty($events)): ?>
    <div class="text-muted small">No events yet.</div>
  <?php else: ?>
    <?php foreach ($events as $ev): ?>
      <div class="shop-timeline-row d-flex gap-2 pb-3 mb-1 border-bottom">
        <div class="flex-shrink-0">
          <span class="badge bg-<?= e(shop_status_tone((string) $ev['to_status'])) ?>" style="min-width:34px;">&bull;</span>
        </div>
        <div class="flex-grow-1">
          <div style="font-size:.85rem;">
            <?php if (!empty($ev['from_status']) && $ev['from_status'] !== $ev['to_status']): ?>
              <strong><?= e(shop_status_label((string) $ev['from_status'])) ?></strong>
              <span class="text-muted">&rarr;</span>
              <strong><?= e(shop_status_label((string) $ev['to_status'])) ?></strong>
            <?php else: ?>
              <strong><?= e(shop_status_label((string) $ev['to_status'])) ?></strong>
              <span class="text-muted">(unchanged)</span>
            <?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.74rem;">
            <?= e($actorLabel[(string) $ev['actor_type']] ?? (string) $ev['actor_type']) ?>
            <?php if (!empty($ev['reason_code'])): ?>
              &middot; <?= e(str_replace('_', ' ', (string) $ev['reason_code'])) ?>
            <?php endif; ?>
            <?php if (!empty($ev['note'])): ?>
              &middot; <?= e((string) $ev['note']) ?>
            <?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.68rem;"><?= e(fmt_datetime((string) $ev['created_at'])) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
