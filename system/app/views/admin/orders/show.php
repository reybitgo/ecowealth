<?php
declare(strict_types=1);

/**
 * Admin — one order: member, items, delivery, payment and history
 * (plan §7 Phase 6; reference views/admin/shop_order.php).
 *
 * Read-only in this phase. The member card links the wallet (Phase 5);
 * the next phases add the payment review and fulfilment actions here.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $events
 * @var array<string, mixed>|null $member
 */

$status = (string) $order['status'];
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
                    <a href="<?= e(url('admin/orders')) ?>" class="text-body-secondary">Orders</a>
                    <i class="icon-base bx bx-chevron-right"></i>
                    <span><?= e((string) $order['order_no']) ?></span>
                  </div>
                  <h4 class="mb-1">Order <?= e((string) $order['order_no']) ?></h4>
                  <p class="text-body-secondary mb-0">
                    Placed <?= e(format_datetime((string) $order['created_at'])) ?>
                    · <?= e(payment_method_label((string) $order['payment_method'])) ?>
                  </p>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <?= status_badge($status) ?>
                  <span class="fs-5 fw-semibold"><?= e(money((float) $order['total'])) ?></span>
                  <a href="<?= e(url('admin/orders')) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-arrow-back me-1"></i> Back to orders
                  </a>
                </div>
              </div>

              <div class="row g-6">
                <div class="col-lg-8">
                  <div class="card mb-6">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Items</h5>
                      <span class="badge bg-label-primary"><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span>
                    </div>
<?= View::partial('order_items', ['order' => $order, 'lines' => $lines]) ?>
                  </div>

                  <div class="card">
                    <div class="card-header"><h5 class="mb-0">Status history</h5></div>
                    <div class="card-body">
<?= View::partial('order_timeline', ['events' => $events]) ?>
                    </div>
                  </div>
                </div>

                <div class="col-lg-4">
                  <div class="card mb-6">
                    <div class="card-header"><h5 class="mb-0">Member</h5></div>
                    <div class="card-body">
<?php if ($member === null): ?>
                      <p class="text-body-secondary small mb-0">The account could not be loaded.</p>
<?php else: ?>
                      <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded-circle bg-label-primary"><?= e(initials((string) $member['username'])) ?></span>
                        </span>
                        <div class="min-w-0">
                          <a href="<?= e(url('admin/wallets/' . (int) $member['id'])) ?>" class="fw-semibold text-body">
                            <?= e((string) $member['username']) ?>
                          </a>
                          <div class="small text-body-secondary"><?= e(trim((string) ($member['full_name'] ?? '')) !== '' ? (string) $member['full_name'] : (string) $member['email']) ?></div>
                        </div>
                      </div>
                      <div class="d-flex justify-content-between py-1 small">
                        <span class="text-body-secondary">E-wallet</span>
                        <span class="fw-medium"><?= e(money((float) $member['ewallet_balance'])) ?></span>
                      </div>
                      <div class="d-flex justify-content-between py-1 small">
                        <span class="text-body-secondary">Withdrawable</span>
                        <span><?= e(money((float) $member['withdrawable_balance'])) ?></span>
                      </div>
                      <a href="<?= e(url('admin/wallets/' . (int) $member['id'])) ?>" class="btn btn-sm btn-label-primary w-100 mt-3">
                        <i class="icon-base bx bx-wallet me-1"></i> Open wallet
                      </a>
<?php endif; ?>
                    </div>
                  </div>

                  <div class="card mb-6">
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

                  <div class="card">
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
<?php if ($order['payment_deadline'] !== null && $status === 'pending'): ?>
                      <div class="small text-body-secondary mt-1">Pay by <?= e(format_datetime((string) $order['payment_deadline'])) ?></div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
