<?php
declare(strict_types=1);

/**
 * Admin order page — payment breakdown.
 *
 * Read-only: totals, reference and the deadlines the review window uses.
 *
 * @var array<string, mixed> $ctx
 */

$order      = $ctx['order'];
$status     = (string) $order['status'];
$canCancel  = (bool) $ctx['can_cancel'];
$hasInvoice = (bool) $ctx['has_invoice'];
$invoiceUrl = (string) $ctx['invoice_url'];
?>
                  <div class="card<?= $canCancel ? ' mb-6' : '' ?>" data-live-region="order-payment-summary">
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
<?php if ($order['payment_deadline'] !== null && in_array($status, ['pending', 'payment_review'], true)): ?>
                      <div class="small text-body-secondary mt-1">Pay by <?= e(format_datetime((string) $order['payment_deadline'])) ?></div>
<?php endif; ?>
<?php if ($order['correction_deadline'] !== null && $status === 'payment_failed'): ?>
                      <div class="small text-body-secondary mt-1">Correction by <?= e(format_datetime((string) $order['correction_deadline'])) ?></div>
<?php endif; ?>
<?php if ($hasInvoice): ?>
                      <a href="<?= e($invoiceUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-label-primary w-100 mt-3">
                        <i class="icon-base bx bx-receipt me-1"></i> View / print invoice
                      </a>
<?php endif; ?>
                    </div>
                  </div>
