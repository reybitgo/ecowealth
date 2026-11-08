<?php
declare(strict_types=1);

/**
 * Member order — status card, proof/cancel/confirm footers.
 *
 * The card owns the proof, cancel and confirm forms, so the whole region is
 * held while the member has unsaved input; the page shows its stale banner
 * instead of discarding a typed amount, reference or reason.
 *
 * @var array<string, mixed> $order
 * @var int    $order_id
 * @var string $status
 * @var array{open:bool, reason:string, deadline:?string, used:int, left:int, max:int} $window
 * @var bool   $window_open
 * @var string|null $window_deadline
 * @var bool   $is_manual
 * @var bool   $overdue
 * @var array<string, mixed>|null $latest_proof
 * @var array<string, string> $instructions
 * @var bool   $can_confirm
 * @var bool   $can_cancel
 * @var int    $auto_days
 */

$orderId        = (int) ($order_id ?? $order['id']);
$windowOpen     = (bool) ($window_open ?? false);
$windowDeadline = $window_deadline ?? ($window['deadline'] ?? null);
$isManual       = (bool) ($is_manual ?? ((string) $order['payment_method'] !== 'ewallet'));
$latestProof    = $latest_proof ?? null;
$instructions   = $instructions ?? [];
$canConfirm     = (bool) ($can_confirm ?? false);
$canCancel      = (bool) ($can_cancel ?? false);
$autoDays       = (int) ($auto_days ?? FulfilmentService::autoCompleteDays());
$overdue        = (bool) ($overdue ?? false);
?>
                    <div
                      class="card mb-6 order-status-card"
                      data-live-region="member-order-status"
                      data-live-hold="dirty"
                    >
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
                              <strong><?= e(($courier ?? '') !== '' ? (string) $courier : 'the courier') ?></strong>
                              <?= $order['shipped_at'] !== null ? 'on ' . e(format_datetime((string) $order['shipped_at'])) : '' ?>.
                            </p>
<?php if (($tracking ?? '') !== ''): ?>
                            <p class="mb-1 small">
                              <span class="text-body-secondary">Tracking number:</span>
                              <span class="fw-semibold user-select-all"><?= e((string) $tracking) ?></span>
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
                        <form method="POST" action="<?= e(url('orders/' . $orderId . '/proof')) ?>" enctype="multipart/form-data" class="row g-3 order-proof-form">
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

<?php if ($canCancel): ?>
                      <div class="card-footer">
                        <form
                          method="POST"
                          action="<?= e(url('orders/' . $orderId . '/cancel')) ?>"
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
