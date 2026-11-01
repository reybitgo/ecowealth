<?php
/**
 * @file   views/admin/shop_order.php
 * @brief  The fulfilment desk (plan §5.16): action bar generated from the
 *         matrix, hold/resume, proofs, shipment, refunds, note, timeline.
 *         Expects: $order, $buyer, $events, $proofs, $shipments,
 *         $activeShipment, $refunds.
 */
$orderNo = shop_public_order_no((int) $order['id'], (string) $order['created_at']);
$status  = (string) $order['status'];
$isSelf  = Auth::id() === (int) $order['member_id'];

/** Legal admin targets for the action bar, straight from the matrix (§5.3). */
$legalTargets = [];
foreach (ShopOrder::TRANSITIONS as $to => $rule) {
    $fromList = $rule['from']['admin'] ?? $rule['from'] ?? [];
    if (in_array('admin', $rule['actors'], true) && in_array($status, $fromList, true)) {
        $legalTargets[] = $to;
    }
}
$needConfirm = ['cancelled', 'returned_to_sender', 'payment_failed', 'on_hold']; // irreversible legs

/* One-line developer copy for the confirm modal, keyed by target status
   (shop_order_confirm_copy() in core/helpers.php — views may not declare
   functions, M14). showConfirm's `message` is trusted HTML (see
   assets/js/app.js), so these literals are safe raw; only the order number   is dynamic and it is e()'d where interpolated. */
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="mb-3">
      <a href="<?= link_to('admin_shop_orders') ?>" class="btn btn-outline-secondary btn-sm">← Back to Orders</a>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <div class="d-flex align-items-center gap-2">
          <h4 class="mb-0">Order <?= e($orderNo) ?></h4>
          <?= shop_status_badge($status, null) ?>
          <?php if ($isSelf): ?><span class="badge bg-dark">your own order</span><?php endif; ?>
        </div>
        <div class="text-muted" style="font-size:.78rem;">
          Buyer @<?= e($buyer['username'] ?? $order['member_id']) ?> · placed <?= e(fmt_datetime((string) $order['created_at'])) ?>
        </div>
      </div>
      <div class="text-end">
        <div class="fw-bold fs-5"><?= fmt_money((float) $order['total_price']) ?></div>
        <div class="text-muted" style="font-size:.74rem;"><?= e(strtoupper((string) $order['payment_method'])) ?>
          <?php if (!empty($order['payment_reference'])): ?>
            · ref <span class="font-mono"><?= e((string) $order['payment_reference']) ?></span>
            <button class="btn btn-sm btn-link p-0" onclick="copyText(<?= js((string) $order['payment_reference']) ?>)">copy</button>
          <?php endif; ?>
        </div>
        <a href="<?= link_to('admin_order_invoice', ['id' => (int) $order['id']]) ?>"
           target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-2">🖨 Invoice</a>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-8">
        <!-- ── Action bar (generated from the matrix) ── -->
        <div class="card mb-3">
          <div class="card-header"><span class="card-title">Actions</span></div>
          <div class="card-body">
            <?php if ($isSelf): ?>
              <div class="alert alert-warning mb-0" style="font-size:.85rem;">
                🚫 You cannot act on your own order — segregation of duties (§5.9).
              </div>
            <?php elseif (!$legalTargets && $status !== 'on_hold'): ?>
              <div class="text-muted" style="font-size:.85rem;">No admin actions available from <strong><?= e(shop_status_label($status)) ?></strong>.</div>
            <?php else: ?>
              <div class="d-flex flex-wrap gap-2">
                <?php if ($status === 'payment_review'): ?>
                  <!-- Verify payment -->
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="paid">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">✅ Verify payment</div>
                    <input type="number" name="amount_sent" class="form-control form-control-sm mb-1" step="0.01" placeholder="Amount received" value="<?= number_format((float) $order['total_price'], 2, '.', '') ?>" required>
                    <input type="text" name="payment_reference" class="form-control form-control-sm mb-1" placeholder="Payment reference" maxlength="40">
                    <button type="submit" class="btn btn-success btn-sm w-100">Mark paid</button>
                  </form>
                  <!-- Reject proof -->
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2 js-confirm-form"
                        data-confirm="<?= e(shop_order_confirm_copy('payment_failed') ?? 'Reject this proof?') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="payment_failed">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">❌ Reject proof</div>
                    <input type="text" name="reason_code" class="form-control form-control-sm mb-1" placeholder="Reason (e.g. amount_short)" maxlength="64" required>
                    <button type="submit" class="btn btn-danger btn-sm w-100">Reject</button>
                  </form>
                <?php endif; ?>

                <?php if ($status === 'paid'): ?>
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="packing">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">📦 Start packing</div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Packing</button>
                  </form>
                  <!-- Revert (rare): paid → payment_review, only before packing -->
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2 js-confirm-form"
                        data-confirm="<?= e(shop_order_confirm_copy('payment_review') ?? 'Move this paid order back to payment review?') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="payment_review">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">↩ Revert</div>
                    <input type="text" name="note" class="form-control form-control-sm mb-1" placeholder="Why?" maxlength="255">
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Revert to review</button>
                  </form>
                <?php endif; ?>

                <?php if ($status === 'packing'): ?>
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="ready_to_ship">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">🏷️ Finish packing</div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Ready to ship</button>
                  </form>
                <?php endif; ?>

                <?php if ($status === 'ready_to_ship'): ?>
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="shipped">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">🚚 Courier handoff</div>
                    <input type="text" name="courier" class="form-control form-control-sm mb-1" placeholder="Courier" maxlength="40" required>
                    <input type="text" name="tracking_number" class="form-control form-control-sm mb-1" placeholder="Tracking no." maxlength="80" required>
                    <input type="date" name="expected_delivery_at" class="form-control form-control-sm mb-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Mark shipped (stock deducts)</button>
                  </form>
                <?php endif; ?>

                <?php if (in_array($status, ['shipped', 'delivery_failed'], true)): ?>
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="out_for_delivery">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">🛵 Out for delivery</div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Dispatch</button>
                  </form>
                <?php endif; ?>

                <?php if (in_array($status, ['shipped', 'out_for_delivery'], true)): ?>
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="delivery_failed">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">⚠ Delivery failed</div>
                    <select name="fault" class="form-select form-select-sm mb-1" required>
                      <option value="customer">customer</option><option value="carrier">carrier</option><option value="shop">shop</option>
                    </select>
                    <input type="text" name="fail_reason" class="form-control form-control-sm mb-1" placeholder="Reason" maxlength="160" required>
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">Record failure</button>
                  </form>
                <?php endif; ?>

                <?php if (in_array($status, ['shipped', 'out_for_delivery', 'delivery_failed'], true)): ?>
                  <?php /* H1: without enctype the browser sends no files, so
                         $_FILES['pod_image'] was always empty and only a typed
                         receiver name could satisfy the POD guard. */ ?>
                  <form method="post" action="<?= link_to('admin_order_transition') ?>"
                        enctype="multipart/form-data" class="border rounded p-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="delivered">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">📬 Mark delivered</div>
                    <input type="text" name="pod_receiver" class="form-control form-control-sm mb-1" placeholder="Received by" maxlength="120">
                    <input type="file" name="pod_image" class="form-control form-control-sm mb-1" accept="image/*">
                    <div class="form-text" style="font-size:.7rem;">Receiver name or POD photo required.</div>
                    <button type="submit" class="btn btn-success btn-sm w-100">Delivered</button>
                  </form>
                <?php endif; ?>

                <?php /* H2: returned_to_sender was implemented in the controller and
                       legal in the matrix, but NO form rendered it — so the status
                       was unreachable and so was the Reship button below. */ ?>
                <?php if (in_array($status, ['shipped', 'out_for_delivery', 'delivery_failed'], true)): ?>
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border rounded p-2 js-confirm-form"
                        data-confirm="<?= e(shop_order_confirm_copy('returned_to_sender') ?? 'Mark this parcel as returned to sender?') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="returned_to_sender">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">📮 Return to sender</div>
                    <div class="form-text mb-1" style="font-size:.7rem;">Parcel is coming back or was intercepted. Closes the active shipment.</div>
                    <button type="submit" class="btn btn-outline-warning btn-sm w-100">Return to sender</button>
                  </form>
                <?php endif; ?>

                <?php if ($status === 'returned_to_sender'): ?>
                  <form method="post" action="<?= link_to('admin_order_reship') ?>" class="border rounded p-2 js-confirm-form"
                        data-confirm="Open a reshipment? The shop absorbs the cost — no fee is charged.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <div class="fw-semibold mb-1" style="font-size:.82rem;">🔁 Reship</div>
                    <div class="form-text mb-1" style="font-size:.7rem;">Goods are reused; stock unchanged; no fee.</div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Reship order</button>
                  </form>
                <?php endif; ?>

                <?php /* C6 (decision D3): the "Close order" button is GONE.
       It posted to=completed with actor 'admin', but the matrix allows only
       ['customer','system'] for that target — so the transition was refused
       every time and the admin saw the misleading "This order just changed.
       Reload and try again." An admin force-close also bypasses the report
       window and the open-refund block, both of which the customer-confirm
       and auto-complete-timer paths honour. Removed rather than widened:
       the matrix stays the single source of truth. */ ?>

                <?php if (in_array($status, ['pending', 'payment_failed', 'paid', 'packing', 'ready_to_ship', 'on_hold', 'returned_to_sender'], true)): ?>
                  <form method="post" action="<?= link_to('admin_order_transition') ?>" class="border border-danger-subtle rounded p-2 js-confirm-form"
                        data-confirm="<?= e(shop_order_confirm_copy('cancelled') ?? 'CANCEL this order?') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="to" value="cancelled">
                    <div class="fw-semibold mb-1 text-danger" style="font-size:.82rem;">🚫 Cancel order</div>
                    <select name="reason_code" class="form-select form-select-sm mb-1">
                      <option value="out_of_stock">out_of_stock</option>
                      <option value="undeliverable">undeliverable</option>
                      <option value="declined">declined</option>
                      <option value="lost_shipment">lost_shipment</option>
                      <option value="duplicate_payment">duplicate_payment</option>
                      <option value="customer_request">customer_request</option>
                    </select>
                    <input type="text" name="note" class="form-control form-control-sm mb-1" placeholder="Note" maxlength="255">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">Cancel</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ── Items + address ── -->
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between"><span class="card-title">Items</span><span class="text-muted" style="font-size:.75rem;">v<?= (int) $order['version'] ?></span></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th style="padding-left:1.25rem;">Product</th><th class="text-center">Qty</th><th class="text-center">Reservation</th><th class="text-end" style="padding-right:1.25rem;">Total</th></tr></thead>
              <tbody>
                <?php foreach ($order['items'] as $it): ?>
                  <tr>
                    <td style="padding-left:1.25rem;"><?= e((string) $it['product_name']) ?></td>
                    <td class="text-center"><?= (int) $it['quantity'] ?></td>
                    <td class="text-center"><span class="badge bg-light text-dark"><?= e((string) $it['reservation_state']) ?></span></td>
                    <td class="text-end font-mono" style="padding-right:1.25rem;"><?= fmt_money((float) $it['total_price']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><span class="card-title">📍 Ship to</span></div>
          <div class="card-body" style="font-size:.85rem;">
            <div class="fw-semibold"><?= e((string) ($order['shipping_name'] ?? '')) ?> <?= $order['shipping_phone'] ? '· ' . e((string) $order['shipping_phone']) : '' ?></div>
            <div><?= e((string) ($order['shipping_address'] ?? '')) ?></div>
            <div><?= e(trim((string) ($order['shipping_city'] ?? '') . ' ' . (string) ($order['shipping_province'] ?? '') . ' ' . (string) ($order['shipping_postal'] ?? ''))) ?></div>
            <?php if (!empty($order['notes_member'])): ?>
              <div class="text-muted mt-1" style="font-size:.78rem;">Buyer note: <?= e((string) $order['notes_member']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ── Right rail ── -->
      <div class="col-lg-4">
        <?php if ($status === 'on_hold'): ?>
          <div class="card mb-3 border-warning-subtle">
            <div class="card-header"><span class="card-title">⏸ On hold</span></div>
            <div class="card-body" style="font-size:.85rem;">
              <div>Reason: <strong><?= e(str_replace('_', ' ', (string) $order['hold_reason'])) ?></strong></div>
              <div>Owner: <?= e(User::find((int) $order['hold_owner_id'])['username'] ?? (string) $order['hold_owner_id']) ?></div>
              <div>Deadline: <?= e(fmt_datetime((string) $order['hold_deadline'])) ?></div>
              <form method="post" action="<?= link_to('admin_order_resume') ?>" class="mt-2">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <button type="submit" class="btn btn-warning btn-sm w-100">▶ Resume to <?= e(shop_status_label((string) $order['previous_status'])) ?></button>
              </form>
            </div>
          </div>
        <?php elseif (in_array('on_hold', $legalTargets, true) && !$isSelf): ?>
          <div class="card mb-3">
            <div class="card-header"><span class="card-title">⏸ Hold</span></div>
            <div class="card-body">
              <form method="post" action="<?= link_to('admin_order_hold') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <input type="text" name="hold_reason" class="form-control form-control-sm mb-1" placeholder="Reason *" maxlength="160" required>
                <select name="hold_owner_id" class="form-select form-select-sm mb-1" required>
                  <?php foreach (($staff ?? []) as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === Auth::actingAdminId() ? ' selected' : '' ?>>@<?= e($s['username']) ?><?= $s['role'] !== 'admin' ? ' (' . e($s['role']) . ')' : '' ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="datetime-local" name="hold_deadline" class="form-control form-control-sm mb-2" required>
                <button type="submit" class="btn btn-outline-warning btn-sm w-100">Place hold</button>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($proofs): ?>
          <div class="card mb-3">
            <div class="card-header"><span class="card-title">🧾 Payment proofs</span></div>
            <div class="card-body">
              <?php foreach ($proofs as $pf): ?>
                <div class="border-bottom pb-2 mb-2">
                  <div class="d-flex justify-content-between align-items-center" style="font-size:.8rem;">
                    <span>Attempt #<?= (int) $pf['attempt_no'] ?></span>
                    <span class="badge bg-<?= ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'][$pf['status']] ?? 'secondary' ?>"><?= e((string) $pf['status']) ?></span>
                  </div>
                  <div class="text-muted" style="font-size:.72rem;">
                    <?= !empty($pf['reference_no']) ? 'ref ' . e((string) $pf['reference_no']) . ' · ' : '' ?>
                    <?= !empty($pf['amount_sent']) ? e(fmt_money((float) $pf['amount_sent'])) . ' · ' : '' ?>
                    <?= e(fmt_datetime((string) $pf['submitted_at'])) ?>
                  </div>
                  <a href="<?= link_to('admin_order_proof', ['id' => (int) $order['id'], 'proof_id' => (int) $pf['id']]) ?>"
                     target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-1">View proof</a>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($shipments): ?>
          <div class="card mb-3">
            <div class="card-header"><span class="card-title">🚚 Shipments</span></div>
            <div class="card-body" style="font-size:.8rem;">
              <?php foreach ($shipments as $s): ?>
                <div class="border-bottom pb-2 mb-2">
                  <div class="d-flex justify-content-between">
                    <span class="fw-semibold">Shipment #<?= (int) $s['seq'] ?><?= !empty($s['reship_of']) ? ' (reship)' : '' ?></span>
                    <span class="badge bg-<?= $s['state'] === 'active' ? 'success' : 'secondary' ?>"><?= e((string) $s['state']) ?></span>
                  </div>
                  <?php if (!empty($s['courier'])): ?>
                    <div><?= e((string) $s['courier']) ?> · <span class="font-mono"><?= e((string) $s['tracking_number']) ?></span></div>
                  <?php endif; ?>
                  <div class="text-muted" style="font-size:.7rem;">
                    attempts: <?= (int) $s['attempts'] ?>
                    <?= !empty($s['fault']) ? ' · fault: ' . e((string) $s['fault']) . ' (' . e((string) $s['fail_reason']) . ')' : '' ?>
                    <?= !empty($s['pod_receiver']) ? ' · POD: ' . e((string) $s['pod_receiver']) : '' ?>
                  </div>
                  <?php if ($s['state'] === 'active'): ?>
                    <details class="mt-1"><summary class="text-primary" style="font-size:.74rem;cursor:pointer;">Edit shipment</summary>
                      <form method="post" action="<?= link_to('admin_order_shipment') ?>" enctype="multipart/form-data" class="mt-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                        <input type="text" name="courier" class="form-control form-control-sm mb-1" placeholder="Courier" value="<?= e((string) $s['courier']) ?>">
                        <input type="text" name="tracking_number" class="form-control form-control-sm mb-1" placeholder="Tracking" value="<?= e((string) $s['tracking_number']) ?>">
                        <input type="date" name="expected_delivery_at" class="form-control form-control-sm mb-1" value="<?= e((string) ($s['expected_delivery_at'] ?? '')) ?>">
                        <input type="file" name="pod_image" class="form-control form-control-sm" accept="image/*">
                        <button type="submit" class="btn btn-sm btn-outline-primary mt-1 w-100">Save shipment</button>
                      </form>
                    </details>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Refunds -->
        <div class="card mb-3">
          <div class="card-header"><span class="card-title">💸 Refunds</span></div>
          <div class="card-body" style="font-size:.8rem;">
            <?php foreach ($refunds as $rf): ?>
              <div class="border-bottom pb-2 mb-2">
                <div class="d-flex justify-content-between">
                  <span class="font-mono"><?= fmt_money((float) $rf['amount']) ?></span>
                  <span class="badge bg-<?= $rf['status'] === 'processed' ? 'success' : ($rf['status'] === 'denied' ? 'danger' : 'secondary') ?>"><?= e((string) $rf['status']) ?></span>
                </div>
                <div class="text-muted" style="font-size:.72rem;"><?= e(shop_refund_cause_label((string) $rf['cause'])) ?>
                  <?php if (!empty($rf['receipt_ref'])): ?>
                    · credit note <span class="font-mono"><?= e((string) $rf['receipt_ref']) ?></span>
                    <a href="<?= link_to('admin_order_refund_receipt', ['refund_id' => (int) $rf['id']]) ?>"
                       target="_blank" rel="noopener">view / print</a>
                  <?php endif; ?>
                </div>
                <?php if ($rf['status'] === 'open'): ?>
                  <form method="post" action="<?= link_to('admin_order_refund') ?>" class="d-flex gap-1 mt-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="refund_id" value="<?= (int) $rf['id'] ?>">
                    <button type="submit" name="refund_action" value="approve" class="btn btn-sm btn-outline-success"
                            data-confirm="Approve this <?= fmt_money((float) $rf['amount']) ?> refund? A credit note will be issued.">Approve</button>
                    <button type="submit" name="refund_action" value="deny" class="btn btn-sm btn-outline-danger"
                            data-confirm="Deny this <?= fmt_money((float) $rf['amount']) ?> refund? This cannot be undone.">Deny</button>
                  </form>
                <?php elseif ($rf['status'] === 'approved'): ?>
                  <form method="post" action="<?= link_to('admin_order_refund') ?>" class="mt-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="refund_id" value="<?= (int) $rf['id'] ?>">
                    <input type="hidden" name="refund_action" value="mark_paid">
                    <input type="text" name="external_ref" class="form-control form-control-sm mb-1" placeholder="External settlement ref (optional)" maxlength="80">
                    <button type="submit" class="btn btn-sm btn-success w-100">Mark paid</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <form method="post" action="<?= link_to('admin_order_refund') ?>" class="mt-1">
              <?= csrf_field() ?>
              <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
              <input type="hidden" name="refund_action" value="create">
              <div class="input-group input-group-sm mb-1">
                <span class="input-group-text">₱</span>
                <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="Amount" required>
              </div>
              <select name="cause" class="form-select form-select-sm mb-1">
                <option value="admin_cancel">admin_cancel</option>
                <option value="delivery_failure">delivery_failure</option>
                <option value="customer_dispute">customer_dispute</option>
                <option value="other">other</option>
              </select>
              <?php /* Phase 6 / M6: this is the field that decides where the money
                       goes. It was previously inferred from `destination`, which
                       meant the box below had no effect on the payout at all and
                       silently decided it instead. Both are now explicit. */ ?>
              <label class="form-label mb-1" style="font-size:.72rem;" for="refundMethod">Payout route</label>
              <select name="method" id="refundMethod" class="form-select form-select-sm mb-1">
                <option value="ewallet">E-wallet — credits the member's wallet automatically</option>
                <option value="cash">Cash — settled manually, no wallet credit</option>
                <option value="bank">Bank transfer — settled manually, no wallet credit</option>
                <option value="manual">GCash / other — settled manually, no wallet credit</option>
              </select>
              <input type="text" name="destination" class="form-control form-control-sm mb-1" placeholder="Destination note (account no., branch…)" maxlength="120">
              <div class="form-text mb-1" style="font-size:.7rem;">
                Only <strong>E-wallet</strong> credits the wallet when the refund is paid out. The note is for your records.
              </div>
              <button type="submit" class="btn btn-sm btn-outline-primary w-100">Open refund task</button>
            </form>
          </div>
        </div>

        <!-- Timeline -->
        <div class="card mb-3" id="adminHistoryCard">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="card-title">🕘 History</span>
            <?php if (!empty($events)): ?>
              <select id="historyPageSize" class="form-select form-select-sm" aria-label="Events per page" style="width:auto;">
                <option value="5" selected>5</option>
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
              </select>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php require __DIR__ . '/../partials/shop_order_timeline.php'; ?>
            <nav id="historyPager" class="d-none mt-2" aria-label="History pages">
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span id="historyPagerInfo" class="text-muted small"></span>
                <ul class="pagination pagination-sm mb-0 ms-auto"></ul>
              </div>
            </nav>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
// Confirm-before-post for every action-bar form. The irreversibly-important
// legs carry a specific data-confirm message (rendered above from
// shop_order_confirm_copy()); the plain forward steps get a default one.
// One delegated listener, like views/admin/shop_products.php.
(function () {
  var ORDER_NO = '<?= e($orderNo) ?>';

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('.js-confirm-form');
    if (!form) return;

    // Confirm the button the admin actually pressed. A form may carry several
    // submitters (Refunds: Approve / Deny), so querySelector's "first submit
    // button" is wrong here — it always reported Approve.
    var submitter = e.submitter ||
      (form.contains(document.activeElement) ? document.activeElement : null);
    var btn = (submitter && form.contains(submitter) && /^(button|input)$/i.test(submitter.tagName))
      ? submitter
      : form.querySelector('button[type="submit"], input[type="submit"]');

    e.preventDefault();

    var msg    = (btn && btn.dataset.confirm) || form.dataset.confirm || '';
    var target = (form.querySelector('input[name="to"]') || {}).value || '';
    var label  = btn ? (btn.textContent || btn.value || '').trim() : 'Confirm action';

    showConfirm({
      size: 'sm',
      title: label + ' — ' + ORDER_NO,
      message: msg
        ? msg  // rendered above with e(); trusted HTML by contract
        : 'Move this order ' + label.replace(/\s*\(.*\)$/, '') + '?',
      confirmText: label,
      confirmClass: /cancel|reject|deny|failure|returned/i.test(label + ' ' + target) ? 'btn-danger' : 'btn-primary',
      onConfirm: function () {
        // form.submit() drops the pressed button's name/value; carry it through
        // a hidden input so refund_action=approve|deny actually posts.
        if (btn && btn.name) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = btn.name;
          hidden.value = btn.value;
          form.appendChild(hidden);
        }
        form.submit();
      }
    });
  });  // The remaining forms (forward steps like Packing / Dispatch, plus hold,
  // refund-creation, note) also post real state changes with no other
  // confirmation; opt them in with a default message.
  document.querySelectorAll(
    '.card form[method="post"]:not(.js-confirm-form)'
  ).forEach(function (form) {
    form.classList.add('js-confirm-form');
    form.dataset.confirm = '';
  });

  // Flash → toast. The transition handlers redirect back with a flash
  // message ('Order moved to X.' / refusals); render it as the app toast
  // (showToast already escapes the message and maps error→danger).
  if (window.showToast) {
    document.querySelectorAll('.page-content > .alert[role="alert"]').forEach(function (el) {
      var kind = ['danger', 'warning', 'info', 'success'].find(function (c) { return el.classList.contains('alert-' + c); }) || 'info';
      var text = (el.textContent || '').replace(/^[✓✕ℹ⚠]\s*/, '').trim();
      if (text) showToast(text, kind);
      el.remove();
    });
  }

  // ── History timeline pager ─────────────────────────────────
  // Client-side pagination of the shared timeline partial: the desk renders
  // the full event list and paging happens in the browser (page size 5, 10,
  // 20 or 50, default 5). No page reload, no server round-trip.
  var historyCard = document.getElementById('adminHistoryCard');
  if (historyCard) {
    var sizeSel  = document.getElementById('historyPageSize');
    var pagerNav = document.getElementById('historyPager');
    var pagerUl  = pagerNav.querySelector('ul');
    var pagerInfo = document.getElementById('historyPagerInfo');
    var page = 1;
    var size = sizeSel ? (parseInt(sizeSel.value, 10) || 5) : 5;

    function drawHistoryRows() {
      var timeline = historyCard.querySelector('.shop-timeline');
      var rows = timeline ? Array.prototype.slice.call(timeline.querySelectorAll('.shop-timeline-row')) : [];
      if (!rows.length) { pagerNav.classList.add('d-none'); return; }
      var pages = Math.max(1, Math.ceil(rows.length / size));
      if (page > pages) page = pages;
      rows.forEach(function (row, i) {
        row.classList.toggle('d-none', !(i >= (page - 1) * size && i < page * size));
      });
      var from = (page - 1) * size + 1;
      var to = Math.min(page * size, rows.length);
      if (pagerInfo) pagerInfo.textContent = 'Showing ' + from + '–' + to + ' of ' + rows.length;
      var html = '<li class="page-item' + (page <= 1 ? ' disabled' : '') + '">' +
                 '<a class="page-link" href="#" data-hp="first" aria-label="First page">&laquo;</a></li>';
      html += '<li class="page-item' + (page <= 1 ? ' disabled' : '') + '">' +
                 '<a class="page-link" href="#" data-hp="prev" aria-label="Previous">&lsaquo;</a></li>';
      for (var p = 1; p <= pages; p++) {
        html += '<li class="page-item' + (p === page ? ' active' : '') + '">' +
                '<a class="page-link" href="#" data-hp="' + p + '">' + p + '</a></li>';
      }
      html += '<li class="page-item' + (page >= pages ? ' disabled' : '') + '">' +
              '<a class="page-link" href="#" data-hp="next" aria-label="Next">&rsaquo;</a></li>';
      html += '<li class="page-item' + (page >= pages ? ' disabled' : '') + '">' +
              '<a class="page-link" href="#" data-hp="last" aria-label="Last page">&raquo;</a></li>';
      pagerUl.innerHTML = html;
      pagerNav.classList.toggle('d-none', pages <= 1);
    }

    pagerUl.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-hp]');
      if (!a) return;
      e.preventDefault();
      var cmd = a.dataset.hp;
      var timeline = historyCard.querySelector('.shop-timeline');
      var rows = timeline ? timeline.querySelectorAll('.shop-timeline-row') : [];
      var pages = Math.max(1, Math.ceil(rows.length / size));
      if (cmd === 'first') page = 1;
      else if (cmd === 'prev') page = Math.max(1, page - 1);
      else if (cmd === 'next') page = Math.min(pages, page + 1);
      else if (cmd === 'last') page = pages;
      else page = parseInt(cmd, 10) || 1;
      drawHistoryRows();
    });

    if (sizeSel) sizeSel.addEventListener('change', function () {
      size = parseInt(this.value, 10) || 5;
      page = 1;
      drawHistoryRows();
    });

    drawHistoryRows();
  }
})();
</script>
<?php require 'views/partials/footer.php'; ?>
