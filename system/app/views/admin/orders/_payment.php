<?php
declare(strict_types=1);

/**
 * Admin order page — payment review.
 *
 * Proof attempts, the review decision forms and the state explanation.
 * The card owns forms, so it carries data-live-hold="dirty": the client
 * keeps unsaved input and shows the stale banner instead of replacing it.
 *
 * @var array<string, mixed> $ctx
 */

$order         = $ctx['order'];
$orderId       = (int) $order['id'];
$status        = (string) $order['status'];
$proofs        = $ctx['proofs'];
$latestProof   = $ctx['latest_proof'];
$window        = $ctx['window'];
$isSelf        = (bool) $ctx['is_self'];
$canReview     = (bool) $ctx['can_review'];
$rejectReasons = $ctx['reject_reasons'];

$proofBadge = static fn (string $proofStatus): string => status_badge($proofStatus, [
    'pending'  => ['Awaiting review', 'info'],
    'verified' => ['Verified', 'success'],
    'rejected' => ['Rejected', 'danger'],
]);
?>
                  <div
                    class="card mb-6 order-review-card"
                    data-live-region="order-payment"
                    data-live-hold="dirty"
                  >
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Payment review</h5>
<?php if ($latestProof !== null): ?>
                      <?= $proofBadge((string) $latestProof['status']) ?>
<?php else: ?>
                      <span class="badge bg-label-secondary">
                        <?= (string) $order['payment_method'] === 'ewallet' ? 'No proof required' : 'No proof yet' ?>
                      </span>
<?php endif; ?>
                    </div>

<?php if ($proofs !== []): ?>
                    <div class="table-responsive">
                      <table class="table align-middle mb-0 order-proofs-table">
                        <thead>
                          <tr>
                            <th scope="col">#</th>
                            <th scope="col">Submitted</th>
                            <th scope="col" class="text-end">Claimed</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Status</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($proofs as $proof): ?>
                          <tr>
                            <td class="fw-semibold"><?= (int) $proof['attempt_no'] ?></td>
                            <td class="small text-body-secondary">
                              <div class="text-nowrap"><?= e(format_datetime((string) $proof['created_at'])) ?></div>
<?php if (($proof['transfer_date'] ?? null) !== null && trim((string) $proof['transfer_date']) !== ''): ?>
                              <div class="text-nowrap">
                                Transfer <?= e(format_datetime((string) $proof['transfer_date'] . ' 00:00:00')) ?>
                              </div>
<?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap"><?= e(money((float) ($proof['amount_claimed'] ?? 0))) ?></td>
                            <td class="small">
                              <a href="<?= e(url('admin/proofs/' . (int) $proof['id'])) ?>" target="_blank" rel="noopener"
                                 class="fw-medium" title="Open this proof in a new tab">
                                <i class="icon-base bx bx-show me-1"></i><?= e(trim((string) ($proof['reference_no'] ?? '')) !== '' ? (string) $proof['reference_no'] : 'View proof') ?>
                              </a>
                            </td>
                            <td>
                              <?= $proofBadge((string) $proof['status']) ?>
<?php if (trim((string) ($proof['reject_reason'] ?? '')) !== ''): ?>
                              <div class="small text-body-secondary mt-1"><?= e((string) $proof['reject_reason']) ?></div>
<?php endif; ?>
<?php if ((int) ($proof['reviewed_by'] ?? 0) > 0): ?>
                              <div class="small text-body-secondary mt-1">
                                by <?= e((string) ($proof['reviewer_username'] ?? 'admin')) ?> · <?= e(format_datetime((string) ($proof['reviewed_at'] ?? ''))) ?>
                              </div>
<?php endif; ?>
                            </td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php endif; ?>

                    <div class="card-body">
<?php if ($canReview): ?>
                      <p class="text-body-secondary small">
                        Compare the proof with the order total before you verify. A rejected proof releases the
                        reserved stock and gives the member a correction window; the order is never paid twice.
                      </p>

                      <div class="row g-4">
                        <div class="col-xl-6">
                          <div class="order-review-panel order-review-verify">
                            <h6 class="mb-1 text-success"><i class="icon-base bx bx-check-shield me-1"></i> Verify payment</h6>
                            <p class="text-body-secondary small mb-3">The amount must match the order total exactly.</p>
                            <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/transition')) ?>"
                                  class="row g-3"
                                  data-confirm
                                  data-confirm-title="Verify this payment?"
                                  data-confirm-text="The order will move to Paid and the reserved stock will be committed."
                                  data-confirm-button="Verify payment"
                                  data-confirm-cancel="Not yet">
                              <?= csrf_field() ?>
                              <input type="hidden" name="to" value="paid" />
                              <div class="col-sm-6">
                                <label class="form-label" for="verify-amount">Amount received</label>
                                <input type="text" inputmode="decimal" name="amount_received" id="verify-amount"
                                       class="form-control <?= invalid_class('amount_received') ?>"
                                       value="<?= old('amount_received', money_amount((float) $order['total'])) ?>" required />
<?php if (has_error('amount_received')): ?>
                                <div class="invalid-feedback"><?= e(error_for('amount_received')) ?></div>
<?php endif; ?>
                              </div>
                              <div class="col-sm-6">
                                <label class="form-label" for="verify-reference">Payment reference</label>
                                <input type="text" name="payment_reference" id="verify-reference" maxlength="40"
                                       class="form-control <?= invalid_class('payment_reference') ?>"
                                       value="<?= old('payment_reference', (string) ($latestProof['reference_no'] ?? '')) ?>" required />
<?php if (has_error('payment_reference')): ?>
                                <div class="invalid-feedback"><?= e(error_for('payment_reference')) ?></div>
<?php endif; ?>
                              </div>
                              <div class="col-12">
                                <label class="form-label" for="verify-note">Note <span class="text-body-secondary">(optional)</span></label>
                                <input type="text" name="note" id="verify-note" maxlength="200" class="form-control"
                                       value="<?= old('note') ?>" placeholder="Anything the finance team should know" />
                              </div>
                              <div class="col-12">
                                <button type="submit" class="btn btn-success">
                                  <i class="icon-base bx bx-check me-1"></i> Verify and mark paid
                                </button>
                              </div>
                            </form>
                          </div>
                        </div>

                        <div class="col-xl-6">
                          <div class="order-review-panel order-review-reject">
                            <h6 class="mb-1 text-danger"><i class="icon-base bx bx-x-circle me-1"></i> Reject proof</h6>
                            <p class="text-body-secondary small mb-3">The member sees the reason and can upload a corrected proof.</p>
                            <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/transition')) ?>"
                                  class="row g-3"
                                  data-confirm
                                  data-confirm-title="Reject this payment proof?"
                                  data-confirm-text="The order moves to Payment failed, the reserved stock is released and the member gets a correction window."
                                  data-confirm-button="Reject proof"
                                  data-confirm-cancel="Keep reviewing"
                                  data-confirm-tone="danger">
                              <?= csrf_field() ?>
                              <input type="hidden" name="to" value="payment_failed" />
                              <div class="col-12">
                                <label class="form-label" for="reject-reason">Reason</label>
                                <select name="reason_code" id="reject-reason" class="form-select">
<?php foreach ($rejectReasons as $code => $label): ?>
                                  <option value="<?= e($code) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
                                </select>
                              </div>
                              <div class="col-12">
                                <label class="form-label" for="reject-note">Note <span class="text-body-secondary">(required for “Other”)</span></label>
                                <input type="text" name="note" id="reject-note" maxlength="500" class="form-control"
                                       placeholder="What the member has to correct" />
                              </div>
                              <div class="col-12">
                                <button type="submit" class="btn btn-label-danger">
                                  <i class="icon-base bx bx-x me-1"></i> Reject proof
                                </button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>
<?php elseif ($isSelf): ?>
                      <p class="text-body-secondary small mb-0">
                        Another administrator can verify this payment. Your own order cannot be reviewed here.
                      </p>
<?php elseif ($status === 'payment_review' && $latestProof === null): ?>
                      <div class="alert alert-warning mb-0" role="alert">
                        This order is in payment review but has no payment proof on file. Open the order history
                        and check with the member before you act.
                      </div>
<?php elseif ($status === 'payment_failed'): ?>
                      <p class="text-body-secondary small mb-0">
                        The proof was rejected<?= $latestProof !== null && trim((string) ($latestProof['reject_reason'] ?? '')) !== '' ? ' — ' . e((string) $latestProof['reject_reason']) : '' ?>.
<?php if ($window['deadline'] !== null): ?>
                        The member can re-upload until <?= e(format_datetime((string) $window['deadline'])) ?><?= (int) $window['left'] > 0 ? ' (' . (int) $window['left'] . ' attempt' . ((int) $window['left'] === 1 ? '' : 's') . ' left)' : ', but no attempts are left' ?>.
<?php else: ?>
                        The correction window is closed.
<?php endif; ?>
                      </p>
<?php elseif ($status === 'pending'): ?>
                      <p class="text-body-secondary small mb-0">
                        Waiting for the member to pay and upload a proof<?= $order['payment_deadline'] !== null ? ' by ' . e(format_datetime((string) $order['payment_deadline'])) : '' ?>.
                        The expiry job cancels an unpaid order when that window closes.
                      </p>
<?php elseif ($status === 'paid' || $status === 'packing' || $status === 'ready_to_ship'): ?>
                      <p class="text-body-secondary small mb-0">
                        Payment verified<?= trim((string) ($order['payment_reference'] ?? '')) !== '' ? ' · ref ' . e((string) $order['payment_reference']) : '' ?><?= $order['paid_at'] !== null ? ' · ' . e(format_datetime((string) $order['paid_at'])) : '' ?>.
                        Use the fulfilment panel below to pack and ship it.
                      </p>
<?php elseif (in_array($status, ['shipped', 'delivered', 'completed'], true)): ?>
                      <p class="text-body-secondary small mb-0">
                        Payment verified<?= trim((string) ($order['payment_reference'] ?? '')) !== '' ? ' · ref ' . e((string) $order['payment_reference']) : '' ?><?= $order['paid_at'] !== null ? ' · ' . e(format_datetime((string) $order['paid_at'])) : '' ?>.
                        Fulfilment is complete for this order; its history is below.
                      </p>
<?php else: ?>
                      <p class="text-body-secondary small mb-0">No payment action is available for this order.</p>
<?php endif; ?>
                    </div>
                  </div>
