<?php
declare(strict_types=1);

/**
 * One order — status, items, delivery, payment and history
 * (plan §5.3–5.6, Phase 6; reference views/member/shop_order.php).
 *
 * Phase 6 shows the order and lets the member cancel an unpaid one.
 * Payment proofs (Phase 7) and shipment tracking (Phase 8) hook into
 * the same page without changing this layout.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $events
 * @var bool $can_cancel
 */

$status   = (string) $order['status'];
$deadline = $order['payment_deadline'] !== null ? (string) $order['payment_deadline'] : '';
$overdue  = $deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < time();

$instructions = [];
if ($status === 'pending') {
    $instructions = match ((string) $order['payment_method']) {
        'gcash' => [
            'GCash number' => (string) setting('payment_gcash_number', ''),
            'Account name' => (string) setting('payment_gcash_name', ''),
        ],
        'maya' => [
            'Maya number'  => (string) setting('payment_maya_number', ''),
            'Account name' => (string) setting('payment_maya_name', ''),
        ],
        'bank' => [
            'Bank'           => (string) setting('payment_bank_name', ''),
            'Account name'   => (string) setting('payment_bank_account_name', ''),
            'Account number' => (string) setting('payment_bank_account_number', ''),
        ],
        default => [],
    };
}

$fullAddress = implode(', ', array_filter([
    (string) $order['shipping_address'],
    (string) ($order['shipping_barangay'] ?? ''),
    (string) ($order['shipping_city'] ?? ''),
    (string) ($order['shipping_province'] ?? ''),
    (string) ($order['shipping_postal'] ?? ''),
]));
?>
            <div class="container-xxl flex-grow-1 container-p-y order-detail-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <div class="d-flex align-items-center gap-2 small text-body-secondary mb-1">
                    <a href="<?= e(url('orders')) ?>" class="text-body-secondary">My orders</a>
                    <i class="icon-base bx bx-chevron-right"></i>
                    <span><?= e((string) $order['order_no']) ?></span>
                  </div>
                  <h4 class="mb-1">Order <?= e((string) $order['order_no']) ?></h4>
                  <p class="text-body-secondary mb-0">
                    Placed <?= e(format_datetime((string) $order['created_at'])) ?>
                    · <?= e(payment_method_label((string) $order['payment_method'])) ?>
                  </p>
                </div>
                <div class="text-md-end">
                  <?= status_badge($status) ?>
                  <div class="fs-4 fw-semibold mt-1"><?= e(money((float) $order['total'])) ?></div>
                </div>
              </div>

              <div class="row g-6">
                <div class="col-lg-8">
                  <div class="card mb-6 order-status-card">
                    <div class="card-body">
<?php if ($status === 'paid' || $status === 'packing' || $status === 'ready_to_ship'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-success"><i class="icon-base bx bx-check-circle"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1">Payment received</h5>
                          <p class="text-body-secondary mb-1">Thank you! We are preparing your order for delivery.</p>
                          <p class="text-body-secondary small mb-0">
                            Courier tracking and the printable invoice arrive with the fulfilment phase.
                          </p>
                        </div>
                      </div>
<?php elseif ($status === 'pending'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-warning"><i class="icon-base bx bx-time-five"></i></span>
                        </span>
                        <div class="flex-grow-1">
                          <h5 class="mb-1">Payment pending</h5>
                          <p class="text-body-secondary mb-3">
                            Send <strong><?= e(money((float) $order['total'])) ?></strong> using
                            <?= e(payment_method_label((string) $order['payment_method'])) ?>.
                          </p>
<?php if ($instructions !== []): ?>
                          <div class="order-payment-instructions mb-3">
<?php foreach ($instructions as $label => $value): ?>
                            <div class="d-flex justify-content-between gap-3 py-1">
                              <span class="text-body-secondary small"><?= e($label) ?></span>
                              <span class="fw-medium small text-end"><?= e($value !== '' ? $value : 'Not configured') ?></span>
                            </div>
<?php endforeach; ?>
                          </div>
<?php endif; ?>
<?php if ($deadline !== ''): ?>
                          <p class="<?= $overdue ? 'text-danger fw-semibold' : 'text-body-secondary' ?> small mb-3">
<?php if ($overdue): ?>
                            <i class="icon-base bx bx-error me-1"></i> The payment window closed on <?= e(format_datetime($deadline)) ?>.
<?php else: ?>
                            <i class="icon-base bx bx-calendar me-1"></i> Pay by <?= e(format_datetime($deadline)) ?>.
<?php endif; ?>
                          </p>
<?php endif; ?>
                          <p class="text-body-secondary small mb-0">
                            Once you have paid, keep your reference number. Payment-proof upload arrives with the
                            next phase; you will be able to attach it here.
                          </p>
                        </div>
                      </div>
<?php elseif ($status === 'payment_review'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-info"><i class="icon-base bx bx-search-alt"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1">We are verifying your payment</h5>
                          <p class="text-body-secondary mb-0">The result appears on this page. You do not need to do anything yet.</p>
                        </div>
                      </div>
<?php elseif ($status === 'payment_failed'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-danger"><i class="icon-base bx bx-error-circle"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1">Payment needs correction</h5>
                          <p class="text-body-secondary mb-0">Please check the payment details and contact support. Re-uploading proof arrives with the next phase.</p>
                        </div>
                      </div>
<?php elseif ($status === 'cancelled'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-secondary"><i class="icon-base bx bx-x-circle"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1">Order cancelled</h5>
                          <p class="text-body-secondary mb-1">
                            Cancelled <?= e(format_datetime((string) ($order['cancelled_at'] ?? ''))) ?>
<?php if (trim((string) ($order['cancelled_reason'] ?? '')) !== ''): ?>
                            — <?= e((string) $order['cancelled_reason']) ?>
<?php endif; ?>
                          </p>
                          <p class="text-body-secondary small mb-0">Any reserved stock has been released. Nothing was charged for a cancelled unpaid order.</p>
                        </div>
                      </div>
<?php else: ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-primary"><i class="icon-base bx bx-package"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></h5>
                          <p class="text-body-secondary mb-0">This order is being processed. Its history is on the right.</p>
                        </div>
                      </div>
<?php endif; ?>
                    </div>

<?php if ($can_cancel): ?>
                    <div class="card-footer">
                      <form method="POST" action="<?= e(url('orders/' . (int) $order['id'] . '/cancel')) ?>" class="d-flex flex-wrap align-items-end gap-2" onsubmit="return confirm('Cancel this order? Any reserved stock will be released.');">
                        <?= csrf_field() ?>
                        <div class="flex-grow-1" style="min-width: 14rem">
                          <label class="form-label small mb-1" for="cancel-reason">Reason <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="reason" id="cancel-reason" maxlength="160" class="form-control form-control-sm" placeholder="Why are you cancelling?" />
                        </div>
                        <button type="submit" class="btn btn-sm btn-label-danger">
                          <i class="icon-base bx bx-x me-1"></i> Cancel order
                        </button>
                      </form>
                    </div>
<?php endif; ?>
                  </div>

                  <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Items</h5>
                      <span class="badge bg-label-primary"><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span>
                    </div>
<?= View::partial('order_items', ['order' => $order, 'lines' => $lines]) ?>
                  </div>
                </div>

                <div class="col-lg-4">
                  <div class="card mb-6">
                    <div class="card-header"><h5 class="mb-0">Delivery</h5></div>
                    <div class="card-body">
                      <div class="fw-semibold"><?= e((string) $order['shipping_name']) ?></div>
<?php if (trim((string) ($order['shipping_phone'] ?? '')) !== ''): ?>
                      <div class="small text-body-secondary"><?= e((string) $order['shipping_phone']) ?></div>
<?php endif; ?>
                      <div class="small text-body-secondary mt-2"><?= e($fullAddress) ?></div>
<?php if (trim((string) ($order['notes_member'] ?? '')) !== ''): ?>
                      <div class="small text-body-secondary mt-3">
                        <span class="fw-medium">Notes:</span> <?= e((string) $order['notes_member']) ?>
                      </div>
<?php endif; ?>
                    </div>
                  </div>

                  <div class="card mb-6">
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
<?php if ($order['paid_at'] !== null): ?>
                      <div class="small text-body-secondary mt-2">Paid <?= e(format_datetime((string) $order['paid_at'])) ?></div>
<?php endif; ?>
                    </div>
                  </div>

                  <div class="card">
                    <div class="card-header"><h5 class="mb-0">History</h5></div>
                    <div class="card-body">
<?= View::partial('order_timeline', ['events' => $events]) ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
