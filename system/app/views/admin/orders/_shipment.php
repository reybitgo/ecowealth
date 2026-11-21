<?php
declare(strict_types=1);

/**
 * Admin order page — shipment summary.
 *
 * A shipment appears only after the order ships. The region is always in
 * the DOM (hidden until then) so the feed can reveal it in place instead of
 * trying to insert a region the client does not know yet.
 *
 * @var array<string, mixed> $ctx
 */

$order    = $ctx['order'];
$shipment = $ctx['shipment'];
$courier  = (string) $ctx['courier'];
$tracking = (string) $ctx['tracking'];
?>
<?php if ($shipment === null): ?>
                  <div class="card mb-6" data-live-region="order-shipment" hidden></div>
<?php else: ?>
                  <div class="card mb-6 order-shipment-card" data-live-region="order-shipment">
                    <div class="card-header"><h5 class="mb-0">Shipment</h5></div>
                    <div class="card-body">
                      <div class="d-flex justify-content-between py-1 small">
                        <span class="text-body-secondary">Courier</span>
                        <span class="fw-medium"><?= e($courier !== '' ? $courier : '—') ?></span>
                      </div>
                      <div class="d-flex justify-content-between gap-2 py-1 small">
                        <span class="text-body-secondary">Tracking</span>
                        <span class="fw-medium user-select-all text-end"><?= e($tracking !== '' ? $tracking : '—') ?></span>
                      </div>
                      <div class="d-flex justify-content-between py-1 small">
                        <span class="text-body-secondary">Shipped</span>
                        <span><?= e(format_datetime((string) ($order['shipped_at'] ?? ''))) ?></span>
                      </div>
                      <div class="d-flex justify-content-between py-1 small">
                        <span class="text-body-secondary">Delivered</span>
                        <span><?= e(format_datetime(($shipment['delivered_at'] ?? null) === null ? null : (string) $shipment['delivered_at'])) ?></span>
                      </div>
<?php if (trim((string) ($shipment['notes'] ?? '')) !== ''): ?>
                      <div class="small text-body-secondary border-top mt-2 pt-2">
                        <span class="fw-medium">Notes:</span> <?= e((string) $shipment['notes']) ?>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
<?php endif; ?>
