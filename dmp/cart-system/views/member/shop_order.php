<?php
/**
 * @file   views/member/shop_order.php
 * @brief  The buyer's order page (plan §5.11). Exactly one status-specific
 *         panel renders. Expects: $order, $events, $shipments, $activeShipment,
 *         $proofs, $refunds.
 */
$orderNo = shop_public_order_no((int) $order['id'], (string) $order['created_at']);
$status  = (string) $order['status'];
$deadline = $status === 'pending' ? $order['payment_deadline']
          : ($status === 'payment_failed' ? $order['correction_deadline'] : null);
$overdue = $deadline && strtotime((string) $deadline) < time();
?>
<?php $pageTitle = 'Order ' . $orderNo; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="mb-3">
      <a href="<?= link_to('shop_orders') ?>" class="btn btn-outline-secondary btn-sm">← Back to Orders</a>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Order <?= e($orderNo) ?></h4>
        <div class="text-muted" style="font-size:.8rem;">Placed <?= e(fmt_datetime((string) $order['created_at'])) ?></div>
      </div>
      <div class="text-end">
        <?= shop_status_badge($status, null) ?>
        <div class="fw-bold mt-1"><?= fmt_money((float) $order['total_price']) ?></div>
        <div class="text-muted" style="font-size:.74rem;">via <?= e(strtoupper((string) $order['payment_method'])) ?></div>
        <?php if (!in_array($status, ['pending', 'payment_review', 'payment_failed', 'cancelled'], true)): ?>
          <a href="<?= link_to('shop_invoice', ['id' => (int) $order['id']]) ?>"
             target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-2">🖨 Invoice</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3">
      <!-- ── Left: status panel + items ── -->
      <div class="col-lg-8">
        <div class="card mb-3">
          <div class="card-header"><span class="card-title">What's happening</span></div>
          <div class="card-body">
            <?php if ($status === 'pending'): ?>
              <h6>Pay for your order</h6>
              <p class="text-muted" style="font-size:.85rem;">Send <?= fmt_money((float) $order['total_price']) ?> using your chosen method, then upload the proof below. Submitting proof ends your option to cancel.</p>
              <?php if ($deadline): ?>
                <p class="<?= $overdue ? 'text-danger fw-bold' : 'text-muted' ?>" style="font-size:.8rem;">
                  <?= $overdue ? '⚠ Payment window has closed' : 'Pay by ' . e(fmt_datetime((string) $deadline)) ?>
                </p>
              <?php endif; ?>
              <?php if (ShopOrder::canMemberAct($order, 'proof') && !$overdue): ?>
                <form method="post" action="<?= link_to('shop_submit_proof') ?>" enctype="multipart/form-data" style="max-width:480px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <div class="mb-2"><label class="form-label">Proof image *</label><input type="file" name="proof_image" class="form-control" accept="image/*" required></div>
                  <div class="mb-2"><label class="form-label">Reference no.</label><input type="text" name="reference_no" class="form-control" maxlength="40"></div>
                  <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">Amount sent</label><input type="number" name="amount_sent" class="form-control" step="0.01" value="<?= number_format((float) $order['total_price'], 2, '.', '') ?>"></div>
                    <div class="col-6"><label class="form-label">Transfer date</label><input type="date" name="transfer_date" class="form-control"></div>
                  </div>
                  <button type="submit" class="btn btn-primary">Submit proof</button>
                </form>
              <?php elseif ($overdue): ?>
                <p class="text-muted" style="font-size:.85rem;">The payment window has closed. Contact support if you already sent the payment.</p>
              <?php endif; ?>

            <?php elseif ($status === 'payment_review'): ?>
              <h6>We're verifying your payment</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">Our staff check proofs within about <?= ShopOrder::SLA_REVIEW_HOURS ?> hours. You'll see the result on this page.</p>

            <?php elseif ($status === 'payment_failed'): ?>
              <h6 class="text-danger">Payment needs correction</h6>
              <?php $latest = $proofs ? end($proofs) : null; ?>
              <?php if ($latest && !empty($latest['reject_reason'])): ?>
                <p class="mb-1" style="font-size:.85rem;">Reason: <strong><?= e(str_replace('_', ' ', (string) $latest['reject_reason'])) ?></strong></p>
              <?php endif; ?>
              <?php if ($order['correction_deadline']): ?>
                <p class="text-muted" style="font-size:.8rem;">Re-upload by <strong><?= e(fmt_datetime((string) $order['correction_deadline'])) ?></strong>.</p>
              <?php endif; ?>
              <?php if (ShopOrder::canMemberAct($order, 'proof')): ?>
                <form method="post" action="<?= link_to('shop_submit_proof') ?>" enctype="multipart/form-data" style="max-width:480px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <div class="mb-2"><label class="form-label">New proof image *</label><input type="file" name="proof_image" class="form-control" accept="image/*" required></div>
                  <div class="mb-2"><label class="form-label">Reference no.</label><input type="text" name="reference_no" class="form-control" maxlength="40"></div>
                  <div class="text-muted mb-2" style="font-size:.75rem;">Submitting this proof ends your option to cancel.</div>
                  <button type="submit" class="btn btn-primary">Re-upload proof</button>
                </form>
              <?php endif; ?>

            <?php elseif (in_array($status, ['paid', 'packing', 'ready_to_ship'], true)): ?>
              <h6>We're preparing your order</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">Payment verified — your items are being picked and packed. Tracking appears here once it's handed to the courier.</p>

            <?php elseif (in_array($status, ['shipped', 'out_for_delivery'], true)): ?>
              <h6><?= $status === 'shipped' ? 'Shipped' : 'Out for delivery' ?></h6>
              <?php if ($activeShipment): ?>
                <p class="mb-0" style="font-size:.9rem;">
                  <strong><?= e((string) $activeShipment['courier']) ?></strong>
                  <span class="font-mono ms-2"><?= e((string) $activeShipment['tracking_number']) ?></span>
                </p>
                <?php if (!empty($activeShipment['expected_delivery_at'])): ?>
                  <p class="text-muted mb-0" style="font-size:.8rem;">Expected <?= e(fmt_date((string) $activeShipment['expected_delivery_at'])) ?></p>
                <?php endif; ?>
              <?php endif; ?>

            <?php elseif ($status === 'delivery_failed'): ?>
              <h6 class="text-danger">A delivery attempt failed</h6>
              <?php if ($activeShipment): ?>
                <p class="mb-1" style="font-size:.85rem;">Reason: <strong><?= e(str_replace('_', ' ', (string) ($activeShipment['fail_reason'] ?? 'not stated'))) ?></strong></p>
                <p class="text-muted mb-0" style="font-size:.8rem;">Attempt <?= (int) $activeShipment['attempts'] ?> of <?= (int) setting('shop_max_delivery_attempts', '3') ?>. Please make sure someone is available at the delivery address.</p>
              <?php endif; ?>

            <?php elseif ($status === 'returned_to_sender'): ?>
              <h6>The parcel is coming back to us</h6>
              <p class="text-muted mb-0" style="font-size:.8rem;">We'll contact you about reshipping. Response window closes <?= !empty($activeShipment['response_deadline']) ? e(fmt_date((string) $activeShipment['response_deadline'])) : 'soon' ?>.</p>

            <?php elseif ($status === 'delivered'): ?>
              <h6 class="text-success">Delivered</h6>
              <?php if ($activeShipment && !empty($activeShipment['pod_receiver'])): ?>
                <p class="mb-1" style="font-size:.85rem;">Received by <strong><?= e((string) $activeShipment['pod_receiver']) ?></strong></p>
              <?php endif; ?>
              <p class="text-muted" style="font-size:.8rem;">Confirm receipt by <strong><?= e(fmt_date((string) ($order['completion_due_at'] ?? ''))) ?></strong> — confirming ends the reporting window.</p>
              <?php if (ShopOrder::canMemberAct($order, 'confirm')): ?>
                <form method="post" action="<?= link_to('shop_confirm_receipt') ?>" class="js-confirm-receipt-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <button type="submit" class="btn btn-success">✅ Confirm receipt</button>
                </form>
                <script>
                (function () {
                  var form = document.querySelector('.js-confirm-receipt-form');
                  if (!form) return;
                  form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    showConfirm({
                      size: 'sm',
                      title: 'Confirm receipt — Order <?= e((string) $order['order_no']) ?>?',
                      message: 'Confirm that you received this order? This ends the reporting window.',
                      confirmText: 'Confirm receipt',
                      confirmClass: 'btn-success',
                      onConfirm: function () { form.submit(); }
                    });
                  });
                })();
                </script>
              <?php endif; ?>

            <?php elseif ($status === 'on_hold'): ?>
              <h6>Order on hold</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">
                Reason: <?= e(str_replace('_', ' ', (string) ($order['hold_reason'] ?? 'under review'))) ?>.
                <?php if ($order['hold_deadline']): ?>We'll update you by <?= e(fmt_datetime((string) $order['hold_deadline'])) ?>.<?php endif; ?>
              </p>

            <?php elseif ($status === 'completed'): ?>
              <h6 class="text-success">Order complete</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">Thank you for shopping with us. This order is closed.</p>

            <?php elseif ($status === 'cancelled'): ?>
              <h6>Order cancelled</h6>
              <p class="text-muted mb-0" style="font-size:.85rem;">
                Reason: <?= e(str_replace('_', ' ', (string) ($order['cancelled_reason'] ?? 'cancelled'))) ?>.
                <?php if ($refunds): ?>A refund of <?= fmt_money((float) $refunds[0]['amount']) ?> is being processed (status: <?= e((string) $refunds[0]['status']) ?>).<?php endif; ?>
              </p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Items -->
        <div class="card mb-3">
          <div class="card-header"><span class="card-title">Items</span></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th style="padding-left:1.25rem;">Product</th><th class="text-end">Price</th><th class="text-center">Qty</th><th class="text-end" style="padding-right:1.25rem;">Total</th></tr></thead>
              <tbody>
                <?php foreach ($order['items'] as $it): ?>
                  <tr>
                    <td style="padding-left:1.25rem;">
                      <?= e((string) $it['product_name']) ?>
                      <?php if (!empty($it['product_sku'])): ?><span class="text-muted" style="font-size:.7rem;"> · <?= e((string) $it['product_sku']) ?></span><?php endif; ?>
                    </td>
                    <td class="text-end font-mono"><?= fmt_money((float) $it['unit_price']) ?></td>
                    <td class="text-center"><?= (int) $it['quantity'] ?></td>
                    <td class="text-end font-mono"><?= fmt_money((float) $it['total_price']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Cancel (own panel; only while the matrix allows it) -->
        <?php if (ShopOrder::canMemberAct($order, 'cancel')): ?>
          <div class="card border-danger-subtle">
            <div class="card-body d-flex justify-content-between align-items-center">
              <div style="font-size:.85rem;">Changed your mind? You can still cancel — stock goes back on sale immediately.</div>
              <?php /* showConfirm(), not the native confirm() every other
                       destructive action on this page uses: the shop pages
                       standardized on the app's modal (tests/js enforces its
                       behavior), and confirm() is also blocked entirely in
                       some embedded webviews. The buyer's order number is
                       interpolated so they know exactly which order they are
                       killing — canceled orders cannot be un-cancelled. */ ?>
              <form method="post" action="<?= link_to('shop_cancel_order') ?>" class="js-cancel-order-form">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">Cancel order</button>
              </form>
              <script>
              (function () {
                var form = document.querySelector('.js-cancel-order-form');
                if (!form) return;
                form.addEventListener('submit', function (e) {
                  e.preventDefault();
                  showConfirm({
                    size: 'sm',
                    title: 'Cancel order <?= e((string) $order['order_no']) ?>?',
                    message: 'The stock goes back on sale immediately<?= $order['payment_method'] === 'ewallet' ? ', and your e-wallet is refunded automatically' : '' ?>. '
                           + 'This cannot be undone.',
                    confirmText: 'Cancel order',
                    confirmClass: 'btn-danger',
                    onConfirm: function () { form.submit(); }
                  });
                });
              })();
              </script>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Right: timeline, shipment, address, proofs ── -->
      <div class="col-lg-4">
        <div class="card mb-3" id="memberHistoryCard">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="card-title">History</span>
            <?php if (!empty($events)): ?>
              <select id="memberHistoryPageSize" class="form-select form-select-sm" aria-label="Events per page" style="width:auto;">
                <option value="5" selected>5</option>
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
              </select>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php require __DIR__ . '/../partials/shop_order_timeline.php'; ?>
            <nav id="memberHistoryPager" class="d-none mt-2" aria-label="History pages">
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span id="memberHistoryPagerInfo" class="text-muted small"></span>
                <ul class="pagination pagination-sm mb-0 ms-auto"></ul>
              </div>
            </nav>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><span class="card-title">Delivery address</span></div>
          <div class="card-body" style="font-size:.85rem;">
            <div class="fw-semibold"><?= e((string) ($order['shipping_name'] ?? '')) ?></div>
            <div><?= e((string) ($order['shipping_address'] ?? '')) ?></div>
            <div><?= e(trim((string) ($order['shipping_city'] ?? '') . ' ' . (string) ($order['shipping_province'] ?? '') . ' ' . (string) ($order['shipping_postal'] ?? ''))) ?></div>
            <?php if (!empty($order['shipping_phone'])): ?><div class="text-muted"><?= e((string) $order['shipping_phone']) ?></div><?php endif; ?>
          </div>
        </div>

        <?php if ($proofs): ?>
          <div class="card mb-3">
            <div class="card-header"><span class="card-title">Payment proofs</span></div>
            <div class="card-body">
              <?php foreach ($proofs as $pf): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-1" style="font-size:.8rem;">
                  <span>Attempt #<?= (int) $pf['attempt_no'] ?></span>
                  <span class="badge bg-<?= ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'][$pf['status']] ?? 'secondary' ?>"><?= e((string) $pf['status']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($refunds): ?>
          <div class="card">
            <div class="card-header"><span class="card-title">Refunds</span></div>
            <div class="card-body" style="font-size:.85rem;">
              <?php foreach ($refunds as $rf): ?>
                <div class="d-flex justify-content-between border-bottom py-1">
                  <span><?= fmt_money((float) $rf['amount']) ?></span>
                  <span class="badge bg-secondary"><?= e((string) $rf['status']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
// ── History timeline pager ───────────────────────────────────
// Client-side pagination of the shared timeline partial: the page renders the
// full event list and paging happens in the browser (page size 5, 10, 20 or
// 50, default 5). No page reload, no server round-trip. Mirrors the admin
// fulfilment desk (views/admin/shop_order.php).
(function () {
  var historyCard = document.getElementById('memberHistoryCard');
  if (!historyCard) return;
  var sizeSel   = document.getElementById('memberHistoryPageSize');
  var pagerNav  = document.getElementById('memberHistoryPager');
  var pagerUl   = pagerNav.querySelector('ul');
  var pagerInfo = document.getElementById('memberHistoryPagerInfo');
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
})();
</script>
<?php require 'views/partials/footer.php'; ?>
