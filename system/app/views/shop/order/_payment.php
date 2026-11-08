<?php
declare(strict_types=1);

/**
 * Member order — payment summary card.
 *
 * @var array<string, mixed> $order
 * @var bool   $has_invoice
 * @var string $invoice_url
 */
?>
                  <div class="card mb-6" data-live-region="member-order-payment">
                    <div class="card-header"><h5 class="mb-0">Payment</h5></div>
                    <div class="card-body">
                      <div class="d-flex justify-content-between py-1">
                        <span class="text-body-secondary">Method</span>
                        <span class="fw-medium"><?= e(payment_method_label((string) $order['payment_method'])) ?></span>
                      </div>
                      <div class="d-flex justify-content-between py-1">
                        <span class="text-body-secondary">Subtotal</span>
                        <span><?= e(money((float) $order['subtotal'])) ?></span>
                      </div>
                      <div class="d-flex justify-content-between py-1">
                        <span class="text-body-secondary">Shipping</span>
                        <span><?= (float) $order['shipping_fee'] > 0 ? e(money((float) $order['shipping_fee'])) : 'Free' ?></span>
                      </div>
                      <div class="d-flex justify-content-between pt-2 mt-1 border-top fw-semibold">
                        <span>Total</span>
                        <span><?= e(money((float) $order['total'])) ?></span>
                      </div>
<?php if (trim((string) ($order['payment_reference'] ?? '')) !== ''): ?>
                      <div class="d-flex justify-content-between py-1 mt-2 small">
                        <span class="text-body-secondary">Reference</span>
                        <span class="fw-medium"><?= e((string) $order['payment_reference']) ?></span>
                      </div>
<?php endif; ?>
<?php if ($order['paid_at'] !== null): ?>
                      <div class="small text-body-secondary mt-2">Paid <?= e(format_datetime((string) $order['paid_at'])) ?></div>
<?php endif; ?>
<?php if ($has_invoice): ?>
                      <a href="<?= e($invoice_url) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-label-primary w-100 mt-3">
                        <i class="icon-base bx bx-receipt me-1"></i> View / print invoice
                      </a>
<?php endif; ?>
                    </div>
                  </div>
