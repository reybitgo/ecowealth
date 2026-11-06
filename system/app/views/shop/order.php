<?php
declare(strict_types=1);

/**
 * One order — status, items, delivery, payment, proof attempts, shipment
 * and history (plan §5.3–5.7, Phases 6–8; reference views/member/shop_order.php).
 *
 * Phase 7 adds the manual-payment loop. Phase 8 adds the fulfilment half:
 * packing/shipped/delivered/completed states, the courier + tracking card,
 * the "confirm receipt" action and the printable invoice link.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $events
 * @var bool $can_cancel
 * @var list<array<string, mixed>> $proofs
 * @var array<string, mixed>|null $latest_proof
 * @var array{open:bool, reason:string, deadline:?string, used:int, left:int, max:int} $window
 * @var array<string, mixed>|null $shipment
 * @var array<string, mixed>|null $invoice
 * @var bool $can_confirm
 * @var int $auto_days
 */

$status   = (string) $order['status'];
$orderId  = (int) $order['id'];
$deadline = $order['payment_deadline'] !== null ? (string) $order['payment_deadline'] : '';
$overdue  = $deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < time();

$proofs       = $proofs ?? [];
$latestProof  = $latest_proof ?? ($proofs === [] ? null : $proofs[count($proofs) - 1]);
$window       = $window ?? [
    'open'     => false,
    'reason'   => 'status',
    'deadline' => null,
    'used'     => count($proofs),
    'left'     => 0,
    'max'      => 3,
];
$windowOpen   = (bool) ($window['open'] ?? false);
$windowDeadline = $window['deadline'] ?? null;
$isManual     = (string) $order['payment_method'] !== 'ewallet';
$attemptsLeft = (int) ($window['left'] ?? 0);

$shipment    = $shipment ?? null;
$invoice     = $invoice ?? null;
$canConfirm  = (bool) ($can_confirm ?? false);
$autoDays    = (int) ($auto_days ?? FulfilmentService::autoCompleteDays());
$hasInvoice  = $invoice !== null || Order::isPaid($order);
$invoiceUrl  = url('orders/' . $orderId . '/invoice');
$tracking    = $shipment === null ? '' : trim((string) ($shipment['tracking_number'] ?? ''));
$courier     = $shipment === null ? '' : trim((string) ($shipment['courier'] ?? ''));

/* Proof status labels read better in this context than the global map. */
$proofBadge = static fn (string $proofStatus): string => status_badge($proofStatus, [
    'pending'  => ['Awaiting review', 'info'],
    'verified' => ['Verified', 'success'],
    'rejected' => ['Rejected', 'danger'],
]);

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
<?php if ($hasInvoice): ?>
                  <a href="<?= e($invoiceUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-label-primary mt-3">
                    <i class="icon-base bx bx-receipt me-1"></i> View / print invoice
                  </a>
<?php endif; ?>
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
                          <h5 class="mb-1">
<?php if ($status === 'paid'): ?>
                            Payment received
<?php elseif ($status === 'packing'): ?>
                            Packing your order
<?php else: ?>
                            Ready to ship
<?php endif; ?>
                          </h5>
                          <p class="text-body-secondary mb-1">
<?php if ($status === 'paid'): ?>
                            Thank you! We are preparing your order for delivery.
<?php elseif ($status === 'packing'): ?>
                            We are picking and packing your items.
<?php else: ?>
                            Your order is packed and waiting for the courier to collect it.
<?php endif; ?>
                          </p>
                          <p class="text-body-secondary small mb-0">
                            The courier and the tracking number appear here as soon as the order ships.
                          </p>
                        </div>
                      </div>
<?php elseif ($status === 'shipped'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-primary"><i class="icon-base bx bx-package"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1">On the way</h5>
                          <p class="text-body-secondary mb-2">
                            Your order has been handed to
                            <strong><?= e($courier !== '' ? $courier : 'the courier') ?></strong>
                            <?= $order['shipped_at'] !== null ? 'on ' . e(format_datetime((string) $order['shipped_at'])) : '' ?>.
                          </p>
<?php if ($tracking !== ''): ?>
                          <p class="mb-1 small">
                            <span class="text-body-secondary">Tracking number:</span>
                            <span class="fw-semibold user-select-all"><?= e($tracking) ?></span>
                          </p>
<?php endif; ?>
                          <p class="text-body-secondary small mb-0">
                            We will mark the order delivered when it arrives; you confirm receipt afterwards.
                          </p>
                        </div>
                      </div>
<?php elseif ($status === 'delivered'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-success"><i class="icon-base bx bx-home-smile"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1">Delivered</h5>
                          <p class="text-body-secondary mb-1">
                            Delivered
                            <?= $order['delivered_at'] !== null ? 'on ' . e(format_datetime((string) $order['delivered_at'])) : '' ?>.
                            Please confirm you received the order so we can close it.
                          </p>
                          <p class="text-body-secondary small mb-0">
                            If you do nothing, the order completes automatically
                            <?= $autoDays >= 1 ? (int) $autoDays . ' day' . ($autoDays === 1 ? '' : 's') . ' after delivery' : 'once the shop completes it' ?>.
                          </p>
                        </div>
                      </div>
<?php elseif ($status === 'completed'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-success"><i class="icon-base bx bx-check-double"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1">Order complete</h5>
                          <p class="text-body-secondary mb-1">
                            Completed
                            <?= $order['completed_at'] !== null ? 'on ' . e(format_datetime((string) $order['completed_at'])) : '' ?>.
                            Thank you for shopping with <?= e(APP_NAME) ?>.
                          </p>
                          <p class="text-body-secondary small mb-0">
                            Your invoice is available above and from the payment panel.
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
                            Send <strong><?= e(money((float) $order['total'])) ?></strong> to the company's
                            <?= e(payment_method_label((string) $order['payment_method'])) ?> account shown below.
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
                          <p class="text-body-secondary small mb-3">
                            These are the company's receiving details. Your own payout accounts are in
                            <a href="<?= e(url('profile')) ?>">your profile</a>.
                          </p>
<?php endif; ?>
<?php if ($windowDeadline !== null && $windowDeadline !== ''): ?>
                          <p class="<?= $overdue ? 'text-danger fw-semibold' : 'text-body-secondary' ?> small mb-3">
<?php if ($overdue): ?>
                            <i class="icon-base bx bx-error me-1"></i> The payment window closed on <?= e(format_datetime((string) $windowDeadline)) ?>.
<?php else: ?>
                            <i class="icon-base bx bx-calendar me-1"></i> Pay by <?= e(format_datetime((string) $windowDeadline)) ?>.
<?php endif; ?>
                          </p>
<?php endif; ?>
                        </div>
                      </div>
<?php elseif ($status === 'payment_review'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-info"><i class="icon-base bx bx-search-alt"></i></span>
                        </span>
                        <div>
                          <h5 class="mb-1">We are verifying your payment</h5>
                          <p class="text-body-secondary mb-1">The result appears on this page. You do not need to do anything yet.</p>
<?php if ($latestProof !== null): ?>
                          <p class="text-body-secondary small mb-0">
                            Attempt <?= (int) $latestProof['attempt_no'] ?> of <?= (int) $window['max'] ?>
                            · <?= e(money((float) ($latestProof['amount_claimed'] ?? 0))) ?>
<?php if (trim((string) ($latestProof['reference_no'] ?? '')) !== ''): ?>
                            · ref <?= e((string) $latestProof['reference_no']) ?>
<?php endif; ?>
                          </p>
<?php endif; ?>
                        </div>
                      </div>
<?php elseif ($status === 'payment_failed'): ?>
                      <div class="d-flex align-items-start gap-3">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded-circle bg-label-danger"><i class="icon-base bx bx-error-circle"></i></span>
                        </span>
                        <div class="flex-grow-1">
                          <h5 class="mb-1">Payment needs correction</h5>
<?php if ($latestProof !== null && trim((string) ($latestProof['reject_reason'] ?? '')) !== ''): ?>
                          <p class="text-body-secondary mb-2">
                            <span class="fw-medium">Reason:</span> <?= e((string) $latestProof['reject_reason']) ?>
                          </p>
<?php else: ?>
                          <p class="text-body-secondary mb-2">Please check the payment details and upload a corrected proof.</p>
<?php endif; ?>
<?php if ($windowDeadline !== null && $windowDeadline !== ''): ?>
                          <p class="<?= $overdue ? 'text-danger fw-semibold' : 'text-body-secondary' ?> small mb-3">
<?php if ($overdue): ?>
                            <i class="icon-base bx bx-error me-1"></i> The correction window closed on <?= e(format_datetime((string) $windowDeadline)) ?>.
<?php else: ?>
                            <i class="icon-base bx bx-calendar-edit me-1"></i> Correct and re-upload by <?= e(format_datetime((string) $windowDeadline)) ?>.
<?php endif; ?>
                          </p>
<?php endif; ?>
<?php if (!$windowOpen && ($window['reason'] ?? '') === 'attempts'): ?>
                          <p class="text-body-secondary small mb-0">
                            You have used all <?= (int) $window['max'] ?> payment-proof attempts for this order. Please contact support.
                          </p>
<?php endif; ?>
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
                          <p class="text-body-secondary small mb-0">
                            Any reserved stock has been released.
<?php if ((string) $order['payment_method'] === 'ewallet' && $order['paid_at'] !== null): ?>
                            A wallet refund, if owed, appears in your e-wallet statement.
<?php else: ?>
                            Nothing was charged for a cancelled unpaid order.
<?php endif; ?>
                          </p>
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

<?php if ($canConfirm): ?>
                    <div class="card-footer order-confirm-receipt">
                      <h6 class="mb-1">Confirm your receipt</h6>
                      <p class="text-body-secondary small mb-3">
                        Confirm the order arrived and it is closed. The confirmation cannot be undone.
                      </p>
                      <form
                        method="POST"
                        action="<?= e(url('orders/' . $orderId . '/confirm')) ?>"
                        class="d-flex flex-wrap align-items-end gap-2"
                        data-confirm
                        data-confirm-title="Confirm you received this order?"
                        data-confirm-text="The order will be marked complete. This cannot be undone."
                        data-confirm-button="Confirm receipt"
                        data-confirm-cancel="Not yet"
                      >
                        <?= csrf_field() ?>
                        <div class="flex-grow-1" style="min-width: 14rem">
                          <label class="form-label small mb-1" for="confirm-note">Note <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="note" id="confirm-note" maxlength="200" class="form-control form-control-sm"
                                 placeholder="Anything we should know?" />
                        </div>
                        <button type="submit" class="btn btn-sm btn-success">
                          <i class="icon-base bx bx-check-double me-1"></i> Confirm receipt
                        </button>
                      </form>
                    </div>
<?php endif; ?>

<?php if ($isManual && $windowOpen): ?>
                    <div class="card-footer order-proof-upload">
                      <h6 class="mb-1">Upload your payment proof</h6>
                      <p class="text-body-secondary small mb-3">
                        Attach a screenshot or PDF of the transfer, then enter the amount and the reference number.
                        Use a JPG, PNG, WebP or PDF up to 5&nbsp;MB. Attempt <strong><?= (int) $window['used'] + 1 ?></strong>
                        of <?= (int) $window['max'] ?>.
                      </p>
                      <form method="POST" action="<?= e(url('orders/' . (int) $order['id'] . '/proof')) ?>" enctype="multipart/form-data" class="row g-3 order-proof-form">
                        <?= csrf_field() ?>
                        <div class="col-sm-6">
                          <label class="form-label" for="proof-amount">Amount sent</label>
                          <input type="text" inputmode="decimal" name="amount_sent" id="proof-amount"
                                 class="form-control <?= invalid_class('amount_sent') ?>"
                                 value="<?= old('amount_sent', money_amount((float) $order['total'])) ?>"
                                 placeholder="0.00" required />
<?php if (has_error('amount_sent')): ?>
                          <div class="invalid-feedback"><?= e(error_for('amount_sent')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                          <label class="form-label" for="proof-reference">Reference number</label>
                          <input type="text" name="reference_no" id="proof-reference" maxlength="40"
                                 class="form-control <?= invalid_class('reference_no') ?>"
                                 value="<?= old('reference_no') ?>" placeholder="GCash / bank reference" required />
<?php if (has_error('reference_no')): ?>
                          <div class="invalid-feedback"><?= e(error_for('reference_no')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                          <label class="form-label" for="proof-date">Transfer date <span class="text-body-secondary">(optional)</span></label>
                          <input type="date" name="transfer_date" id="proof-date" max="<?= e(date('Y-m-d')) ?>"
                                 class="form-control <?= invalid_class('transfer_date') ?>"
                                 value="<?= old('transfer_date') ?>" />
<?php if (has_error('transfer_date')): ?>
                          <div class="invalid-feedback"><?= e(error_for('transfer_date')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                          <label class="form-label" for="proof-file">Proof file</label>
                          <input type="file" name="proof_image" id="proof-file" accept=".jpg,.jpeg,.png,.webp,.pdf"
                                 class="form-control <?= invalid_class('proof_image') ?>" required />
<?php if (has_error('proof_image')): ?>
                          <div class="invalid-feedback"><?= e(error_for('proof_image')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
                          <span class="small text-body-secondary">
                            <i class="icon-base bx bx-lock-alt me-1"></i> Only the review team can open the file.
                          </span>
                          <button type="submit" class="btn btn-primary">
                            <i class="icon-base bx bx-upload me-1"></i> Submit payment proof
                          </button>
                        </div>
                      </form>
                    </div>
<?php elseif ($isManual && $windowOpen === false && in_array($status, ['pending', 'payment_failed'], true)): ?>
                    <div class="card-footer">
                      <p class="text-body-secondary small mb-0">
<?php if (($window['reason'] ?? '') === 'attempts'): ?>
                        <i class="icon-base bx bx-info-circle me-1"></i> No proof attempts left. Please contact support with your reference number.
<?php elseif ($overdue): ?>
                        <i class="icon-base bx bx-time me-1"></i> The payment window has closed, so a proof can no longer be uploaded. You can cancel this order below and place a new one.
<?php else: ?>
                        <i class="icon-base bx bx-info-circle me-1"></i> A payment proof cannot be uploaded for this order right now.
<?php endif; ?>
                      </p>
                    </div>
<?php endif; ?>

<?php if ($can_cancel): ?>
                    <div class="card-footer">
                      <form
                        method="POST"
                        action="<?= e(url('orders/' . (int) $order['id'] . '/cancel')) ?>"
                        class="d-flex flex-wrap align-items-end gap-2"
                        data-confirm
                        data-confirm-title="Cancel this order?"
                        data-confirm-text="Any reserved stock will be released. This cannot be undone."
                        data-confirm-button="Cancel order"
                        data-confirm-cancel="Keep order"
                        data-confirm-tone="danger"
                      >
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

<?php if ($proofs !== []): ?>
                  <div class="card mb-6 order-proofs-card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Payment attempts</h5>
                      <span class="badge bg-label-secondary"><?= count($proofs) ?> of <?= (int) $window['max'] ?></span>
                    </div>
                    <div class="table-responsive">
                      <table class="table align-middle mb-0 order-proofs-table">
                        <thead>
                          <tr>
                            <th scope="col">#</th>
                            <th scope="col">Submitted</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Status</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($proofs as $proof): ?>
                          <tr>
                            <td class="fw-semibold"><?= (int) $proof['attempt_no'] ?></td>
                            <td class="small text-body-secondary text-nowrap"><?= e(format_datetime((string) $proof['created_at'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(money((float) ($proof['amount_claimed'] ?? 0))) ?></td>
                            <td class="small"><?= e(trim((string) ($proof['reference_no'] ?? '')) !== '' ? (string) $proof['reference_no'] : '—') ?></td>
                            <td>
                              <?= $proofBadge((string) $proof['status']) ?>
<?php if (trim((string) ($proof['reject_reason'] ?? '')) !== ''): ?>
                              <div class="small text-body-secondary mt-1"><?= e((string) $proof['reject_reason']) ?></div>
<?php endif; ?>
                            </td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
<?php endif; ?>

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
<?php if (trim((string) ($order['payment_reference'] ?? '')) !== ''): ?>
                      <div class="d-flex justify-content-between py-1 mt-2 small">
                        <span class="text-body-secondary">Reference</span>
                        <span class="fw-medium"><?= e((string) $order['payment_reference']) ?></span>
                      </div>
<?php endif; ?>
<?php if ($order['paid_at'] !== null): ?>
                      <div class="small text-body-secondary mt-2">Paid <?= e(format_datetime((string) $order['paid_at'])) ?></div>
<?php endif; ?>
<?php if ($hasInvoice): ?>
                      <a href="<?= e($invoiceUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-label-primary w-100 mt-3">
                        <i class="icon-base bx bx-receipt me-1"></i> View / print invoice
                      </a>
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
