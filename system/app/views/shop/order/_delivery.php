<?php
declare(strict_types=1);

/**
 * Member order — delivery card (address snapshot + shipment).
 *
 * @var array<string, mixed> $order
 * @var array<string, mixed>|null $shipment
 * @var string $tracking
 * @var string $courier
 * @var string $full_address
 */
?>
                  <div class="card mb-6" data-live-region="member-order-delivery">
                    <div class="card-header"><h5 class="mb-0">Delivery</h5></div>
                    <div class="card-body">
                      <div class="fw-semibold"><?= e((string) $order['shipping_name']) ?></div>
<?php if (trim((string) ($order['shipping_phone'] ?? '')) !== ''): ?>
                      <div class="small text-body-secondary"><?= e((string) $order['shipping_phone']) ?></div>
<?php endif; ?>
                      <div class="small text-body-secondary mt-2"><?= e($full_address) ?></div>
<?php if (trim((string) ($order['notes_member'] ?? '')) !== ''): ?>
                      <div class="small text-body-secondary mt-3">
                        <span class="fw-medium">Notes:</span> <?= e((string) $order['notes_member']) ?>
                      </div>
<?php endif; ?>
<?php if ($shipment !== null): ?>
                      <div class="border-top mt-3 pt-3 small">
                        <div class="fw-medium mb-2">Shipment</div>
<?php if ($courier !== ''): ?>
                        <div class="d-flex justify-content-between py-1">
                          <span class="text-body-secondary">Courier</span>
                          <span class="fw-medium"><?= e($courier) ?></span>
                        </div>
<?php endif; ?>
<?php if ($tracking !== ''): ?>
                        <div class="d-flex justify-content-between gap-2 py-1">
                          <span class="text-body-secondary">Tracking</span>
                          <span class="fw-medium user-select-all text-end"><?= e($tracking) ?></span>
                        </div>
<?php endif; ?>
<?php if ($order['shipped_at'] !== null): ?>
                        <div class="d-flex justify-content-between py-1">
                          <span class="text-body-secondary">Shipped</span>
                          <span><?= e(format_datetime((string) $order['shipped_at'])) ?></span>
                        </div>
<?php endif; ?>
<?php if (($shipment['delivered_at'] ?? null) !== null): ?>
                        <div class="d-flex justify-content-between py-1">
                          <span class="text-body-secondary">Delivered</span>
                          <span><?= e(format_datetime((string) $shipment['delivered_at'])) ?></span>
                        </div>
<?php endif; ?>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
