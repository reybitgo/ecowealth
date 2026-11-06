<?php
declare(strict_types=1);

/**
 * Admin — one order: member, payment review, fulfilment, items, delivery
 * and history (plan §7 Phases 6–8; reference views/admin/shop_order.php).
 *
 * Phase 7 adds the payment-review pane. Phase 8 adds the fulfilment panel:
 * pack → ready to ship → ship (courier + tracking, stock deducted) →
 * deliver (POD note), with the invoice link and the shipment summary.
 * An administrator never reviews an order they placed themselves
 * (P7-T10); fulfilment is not a money decision, so shipping and delivering
 * one's own order is allowed.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $events
 * @var array<string, mixed>|null $member
 * @var list<array<string, mixed>> $proofs
 * @var array<string, mixed>|null $latest_proof
 * @var array<string, mixed> $window
 * @var bool $is_self
 * @var bool $can_review
 * @var bool $can_cancel
 * @var bool $refund_on_cancel
 * @var array<string, string> $reject_reasons
 * @var array<string, string> $cancel_reasons
 * @var array<string, mixed>|null $shipment
 * @var array<string, mixed>|null $invoice
 * @var bool $can_pack
 * @var bool $can_ready
 * @var bool $can_ship
 * @var bool $can_deliver
 * @var bool $can_confirm
 * @var int $auto_days
 */

$status  = (string) $order['status'];
$orderId = (int) $order['id'];

$proofs         = $proofs ?? [];
$latestProof    = $latest_proof ?? ($proofs === [] ? null : $proofs[count($proofs) - 1]);
$window         = $window ?? ['open' => false, 'reason' => 'status', 'deadline' => null, 'used' => count($proofs), 'left' => 0, 'max' => 3];
$isSelf         = (bool) ($is_self ?? false);
$canReview      = (bool) ($can_review ?? false);
$canCancel      = (bool) ($can_cancel ?? false);
$refundOnCancel = (bool) ($refund_on_cancel ?? false);
$rejectReasons  = $reject_reasons ?? PaymentService::REJECT_REASONS;
$cancelReasons  = $cancel_reasons ?? PaymentService::CANCEL_REASONS;

$shipment    = $shipment ?? null;
$invoice     = $invoice ?? null;
$canPack     = (bool) ($can_pack ?? $status === 'paid');
$canReady    = (bool) ($can_ready ?? $status === 'packing');
$canShip     = (bool) ($can_ship ?? $status === 'ready_to_ship');
$canDeliver  = (bool) ($can_deliver ?? $status === 'shipped');
$canConfirm  = (bool) ($can_confirm ?? false);
$autoDays    = (int) ($auto_days ?? FulfilmentService::autoCompleteDays());
$hasInvoice  = $invoice !== null || Order::isPaid($order);
$invoiceUrl  = url('admin/orders/' . $orderId . '/invoice');
$courier     = $shipment === null ? '' : trim((string) ($shipment['courier'] ?? ''));
$tracking    = $shipment === null ? '' : trim((string) ($shipment['tracking_number'] ?? ''));

$proofBadge = static fn (string $proofStatus): string => status_badge($proofStatus, [
    'pending'  => ['Awaiting review', 'info'],
    'verified' => ['Verified', 'success'],
    'rejected' => ['Rejected', 'danger'],
]);

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

<?php if ($isSelf): ?>
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-shield-x fs-5"></i>
                <div>
                  <strong>You placed this order.</strong>
                  Payment review, rejection and cancellation are disabled for your own order — another
                  administrator has to act on it.
                </div>
              </div>
<?php endif; ?>

              <div class="row g-6">
                <div class="col-lg-8">
                  <div class="card mb-6 order-review-card">
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

                  <div class="card mb-6 order-fulfilment-card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Fulfilment</h5>
                      <?= status_badge($status) ?>
                    </div>
                    <div class="card-body">
<?php if ($canPack): ?>
                      <p class="text-body-secondary small mb-3">
                        Payment is verified. Start packing, then mark the parcel ready for the courier.
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/transition')) ?>" class="row g-3"
                            data-confirm
                            data-confirm-title="Start packing this order?"
                            data-confirm-text="The order moves to Packing. Nothing else changes yet."
                            data-confirm-button="Start packing"
                            data-confirm-cancel="Not yet">
                        <?= csrf_field() ?>
                        <input type="hidden" name="to" value="packing" />
                        <div class="col-12">
                          <label class="form-label" for="pack-note">Note <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="note" id="pack-note" maxlength="200" class="form-control"
                                 value="<?= old('note') ?>" placeholder="Anything the packer should know" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-primary">
                            <i class="icon-base bx bx-box me-1"></i> Start packing
                          </button>
                        </div>
                      </form>
<?php elseif ($canReady): ?>
                      <p class="text-body-secondary small mb-3">
                        The order is being packed. Mark it ready when the parcel is sealed and waiting for pickup.
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/transition')) ?>" class="row g-3"
                            data-confirm
                            data-confirm-title="Mark this order ready to ship?"
                            data-confirm-text="The next step is recording the courier and the tracking number."
                            data-confirm-button="Ready to ship"
                            data-confirm-cancel="Not yet">
                        <?= csrf_field() ?>
                        <input type="hidden" name="to" value="ready_to_ship" />
                        <div class="col-12">
                          <label class="form-label" for="ready-note">Note <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="note" id="ready-note" maxlength="200" class="form-control"
                                 value="<?= old('note') ?>" placeholder="Parcel count, courier instructions…" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-primary">
                            <i class="icon-base bx bx-check-circle me-1"></i> Mark ready to ship
                          </button>
                        </div>
                      </form>
<?php elseif ($canShip): ?>
                      <p class="text-body-secondary small mb-3">
                        Record the courier and the tracking number. Saving this <strong>deducts the stock</strong>
                        from the catalogue and moves the order to Shipped.
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/ship')) ?>" class="row g-3"
                            data-confirm
                            data-confirm-title="Ship this order?"
                            data-confirm-text="The stock will be deducted and the order marked Shipped. This cannot be undone here."
                            data-confirm-button="Ship order"
                            data-confirm-cancel="Not yet">
                        <?= csrf_field() ?>
                        <div class="col-sm-6">
                          <label class="form-label" for="ship-courier">Courier</label>
                          <input type="text" name="courier" id="ship-courier" maxlength="40" list="courier-options"
                                 class="form-control <?= invalid_class('courier') ?>"
                                 value="<?= old('courier') ?>" placeholder="J&T Express" required />
                          <datalist id="courier-options">
<?php foreach (FulfilmentService::COURIERS as $option): ?>
                            <option value="<?= e($option) ?>"></option>
<?php endforeach; ?>
                          </datalist>
<?php if (has_error('courier')): ?>
                          <div class="invalid-feedback"><?= e(error_for('courier')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                          <label class="form-label" for="ship-tracking">Tracking number</label>
                          <input type="text" name="tracking_number" id="ship-tracking" maxlength="80"
                                 class="form-control <?= invalid_class('tracking_number') ?>"
                                 value="<?= old('tracking_number') ?>" placeholder="e.g. JT1234567890" required />
<?php if (has_error('tracking_number')): ?>
                          <div class="invalid-feedback"><?= e(error_for('tracking_number')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="col-12">
                          <label class="form-label" for="ship-notes">Shipping note <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="notes" id="ship-notes" maxlength="255" class="form-control"
                                 value="<?= old('notes') ?>" placeholder="Box count, fragile, pickup time…" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-primary">
                            <i class="icon-base bx bxs-truck me-1"></i> Ship order
                          </button>
                        </div>
                      </form>
<?php elseif ($canDeliver): ?>
                      <p class="text-body-secondary small mb-3">
                        The parcel is with <?= e($courier !== '' ? $courier : 'the courier') ?>
<?= $tracking !== '' ? ' (tracking ' . e($tracking) . ')' : '' ?>.
                        Mark it delivered when it arrives; the member confirms afterwards.
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/deliver')) ?>" class="row g-3"
                            data-confirm
                            data-confirm-title="Mark this order delivered?"
                            data-confirm-text="The order moves to Delivered and the member can confirm receipt."
                            data-confirm-button="Mark delivered"
                            data-confirm-cancel="Not yet">
                        <?= csrf_field() ?>
                        <div class="col-12">
                          <label class="form-label" for="deliver-note">Proof of delivery <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="note" id="deliver-note" maxlength="200" class="form-control"
                                 value="<?= old('note') ?>" placeholder="Received by, date/time, remarks" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-success">
                            <i class="icon-base bx bx-home-smile me-1"></i> Mark delivered
                          </button>
                        </div>
                      </form>
<?php elseif ($canConfirm): ?>
                      <p class="text-body-secondary small mb-0">
                        Delivered<?= $order['delivered_at'] !== null ? ' on ' . e(format_datetime((string) $order['delivered_at'])) : '' ?>.
                        Waiting for the member to confirm receipt; the auto-complete job closes it
                        <?= $autoDays >= 1 ? (int) $autoDays . ' day' . ($autoDays === 1 ? '' : 's') . ' after delivery' : 'when scheduled' ?>.
                      </p>
<?php elseif ($status === 'completed'): ?>
                      <p class="text-body-secondary small mb-0">
                        Completed<?= $order['completed_at'] !== null ? ' on ' . e(format_datetime((string) $order['completed_at'])) : '' ?>.
                        The invoice is the frozen document for this sale.
                      </p>
<?php elseif ($status === 'cancelled'): ?>
                      <p class="text-body-secondary small mb-0">
                        Cancelled — no fulfilment action is available. Any reserved stock has been released.
                      </p>
<?php else: ?>
                      <p class="text-body-secondary small mb-0">
                        Fulfilment starts once the payment is verified. Verify the payment above first.
                      </p>
<?php endif; ?>
                    </div>
<?php if ($hasInvoice): ?>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-receipt me-1"></i>
                        <?= $invoice !== null && $invoice['voided_at'] !== null ? 'Invoice voided (payment reversed)' : 'Invoice issued' ?>
                      </span>
                      <a href="<?= e($invoiceUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-label-primary">
                        View / print invoice
                      </a>
                    </div>
<?php endif; ?>
                  </div>


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

<?php if ($shipment !== null): ?>
                  <div class="card mb-6 order-shipment-card">
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

                  <div class="card<?= $canCancel ? ' mb-6' : '' ?>">
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

<?php if ($canCancel): ?>
                  <div class="card order-cancel-card">
                    <div class="card-header"><h5 class="mb-0">Cancel order</h5></div>
                    <div class="card-body">
                      <p class="text-body-secondary small mb-3">
<?php if ($refundOnCancel): ?>
                        Cancelling releases the reserved stock and refunds
                        <strong><?= e(money((float) $order['total'])) ?></strong> to the member's e-wallet (the
                        part that was withdrawable stays withdrawable).
<?php else: ?>
                        Cancelling releases the reserved stock. The member was not charged.
<?php endif; ?>
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/transition')) ?>"
                            class="row g-3"
                            data-confirm
                            data-confirm-title="Cancel this order?"
                            data-confirm-text="The order moves to Cancelled and the reserved stock is released.<?= $refundOnCancel ? ' The wallet refund cannot be undone.' : '' ?>"
                            data-confirm-button="Cancel order"
                            data-confirm-cancel="Keep order"
                            data-confirm-tone="danger">
                        <?= csrf_field() ?>
                        <input type="hidden" name="to" value="cancelled" />
                        <div class="col-12">
                          <label class="form-label" for="cancel-reason-code">Reason</label>
                          <select name="reason_code" id="cancel-reason-code" class="form-select">
<?php foreach ($cancelReasons as $code => $label): ?>
                            <option value="<?= e($code) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-12">
                          <label class="form-label" for="cancel-note">Note <span class="text-body-secondary">(required for “Other”)</span></label>
                          <input type="text" name="note" id="cancel-note" maxlength="300" class="form-control"
                                 placeholder="Why the order is being cancelled" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-label-danger w-100">
                            <i class="icon-base bx bx-x me-1"></i> Cancel order
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
<?php endif; ?>
                </div>
              </div>
            </div>
