<?php
declare(strict_types=1);

/**
 * Admin order page — the delivery address.
 *
 * The address is frozen on the order, so this card only changes if the
 * order itself is replaced. It stays a region so the side column keeps the
 * same replacement rules as the other cards.
 *
 * @var array<string, mixed> $ctx
 */

$order       = $ctx['order'];
$fullAddress = (string) $ctx['full_address'];
?>
                  <div class="card mb-6" data-live-region="order-delivery">
                    <div class="card-header"><h5 class="mb-0">Delivery</h5></div>
                    <div class="card-body">
                      <div class="fw-semibold"><?= e((string) $order['shipping_name']) ?></div>
<?php if (trim((string) ($order['shipping_phone'] ?? '')) !== ''): ?>
                      <div class="small text-body-secondary"><?= e((string) $order['shipping_phone']) ?></div>
<?php endif; ?>
                      <div class="small text-body-secondary mt-2"><?= e($fullAddress) ?></div>
<?php if (trim((string) ($order['notes_member'] ?? '')) !== ''): ?>
                      <div class="small text-body-secondary mt-3"><span class="fw-medium">Member notes:</span> <?= e((string) $order['notes_member']) ?></div>
<?php endif; ?>
                    </div>
                  </div>
