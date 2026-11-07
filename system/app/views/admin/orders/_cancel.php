<?php
declare(strict_types=1);

/**
 * Admin order page — cancel order.
 *
 * The card only exists while the order is still cancellable. The hidden
 * wrapper keeps the region in the DOM so another administrator's cancel
 * removes it on the next refresh instead of leaving a stale form behind.
 * The visible card owns a form, so it carries data-live-hold="dirty".
 *
 * @var array<string, mixed> $ctx
 */

$order          = $ctx['order'];
$orderId        = (int) $order['id'];
$canCancel      = (bool) $ctx['can_cancel'];
$refundOnCancel = (bool) $ctx['refund_on_cancel'];
$cancelReasons  = $ctx['cancel_reasons'];
?>
<?php if (!$canCancel): ?>
                  <div class="card" data-live-region="order-cancel" hidden></div>
<?php else: ?>
                  <div class="card order-cancel-card" data-live-region="order-cancel" data-live-hold="dirty">
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
