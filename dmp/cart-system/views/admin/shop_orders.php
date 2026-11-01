<?php
/**
 * @file   views/admin/shop_orders.php
 * @brief  Admin fulfilment queue (plan §5.15): 14 status tabs + all, live
 *         counts, next-action hints from the matrix. Expects: $orders,
 *         $counts, $status.
 */
/* Tab order follows the order-state sequence (ShopOrder::STATUSES): an order
   moves pending → payment_review → paid → … → delivered → completed/cancelled,
   so the queue reads left-to-right in the same lifecycle order rather than in
   whatever order the statuses happened to be listed. The model constant is
   the single source of truth, so adding a status there re-orders the tabs
   automatically without touching this view. */
$tabs = array_merge(['all'], ShopOrder::STATUSES);
?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_admin.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Shop Orders</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Verify payments, pick and pack, hand off to the courier.</p>
      </div>
      <form method="post" action="<?= link_to('admin_shop_expire') ?>" class="d-inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary btn-sm" title="Run the expiry + auto-complete legs now">⏱ Run expiry</button>
      </form>
    </div>

    <!-- Status tabs. Phase 8.6: these navigate by URL rather than swapping
         panels, so they are links inside a nav, not ARIA tabs. role="tablist"
         would promise tabpanels that do not exist and would break keyboard
         arrow-key navigation. aria-current="page" is what actually tells a
         screen reader which one is selected. -->
    <ul class="nav nav-tabs flex-wrap mb-3" style="font-size:.82rem;">
      <?php foreach ($tabs as $t): ?>
        <li class="nav-item">
          <a class="nav-link <?= $status === $t ? 'active' : '' ?>"
             href="<?= link_to('admin_shop_orders', ['status' => $t]) ?>"
             <?= $status === $t ? 'aria-current="page"' : '' ?>>
            <?= $t === 'all' ? 'All' : e(shop_status_label($t)) ?>
            <span class="badge bg-<?= ($counts[$t] ?? 0) > 0 ? 'primary' : 'secondary' ?> ms-1"><?= $counts[$t] ?? 0 ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="card" id="adminOrdersCard">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">Orders</span>
        <?php if (!empty($orders)): ?>
          <select id="adminOrdersPageSize" class="form-select form-select-sm" aria-label="Orders per page" style="width:auto;">
            <option value="5" selected>5</option>
            <option value="10">10</option>
            <option value="20">20</option>
            <option value="50">50</option>
          </select>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="padding-left:1.25rem;">Order #</th>
              <th>Buyer</th>
              <th>Placed</th>
              <th class="text-end">Total</th>
              <th>Method</th>
              <th>Status</th>
              <th>Next action</th>
              <th class="text-end" style="padding-right:1.25rem;"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($orders)): ?>
              <tr><td colspan="8" class="text-center py-5 text-muted">
                <div style="font-size:2rem;opacity:.3;">📦</div>
                <div class="mt-2">No orders<?= $status !== 'all' ? ' in ' . e(shop_status_label($status)) : '' ?>.</div>
              </td></tr>
            <?php else: foreach ($orders as $o):
              $buyer = User::find((int) $o['member_id']);
            ?>
              <tr class="js-order-row">
                <td style="padding-left:1.25rem;" class="fw-semibold"><?= e($o['order_no']) ?></td>
                <td style="font-size:.82rem;">
                  <?= e('@' . ($buyer['username'] ?? $o['member_id'])) ?>
                  <?php if (Auth::id() === (int) $o['member_id']): ?>
                    <span class="badge bg-dark ms-1" title="Your own order — actions are refused">self</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.78rem;"><?= e(fmt_datetime((string) $o['created_at'])) ?></td>
                <td class="text-end font-mono"><?= fmt_money((float) $o['total_price']) ?></td>
                <td class="text-muted" style="font-size:.78rem;"><?= e(strtoupper((string) $o['payment_method'])) ?></td>
                <td><?php
                  $badgeStatus = (string) $o['status'];
                  $badgeStamp  = $o['created_at'] ?? null;
                  require __DIR__ . '/../partials/order_status_badge.php';
                ?></td>
                <td class="text-muted" style="font-size:.76rem;"><?= e(shop_next_action((string) $o['status']) ?? '—') ?></td>
                <td class="text-end" style="padding-right:1.25rem;">
                  <a href="<?= link_to('admin_shop_order', ['id' => $o['id']]) ?>" class="btn btn-sm btn-outline-primary">Open</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <nav id="adminOrdersPager" class="d-none card-footer" aria-label="Order pages">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <span id="adminOrdersPagerInfo" class="text-muted small"></span>
          <ul class="pagination pagination-sm mb-0 ms-auto"></ul>
        </div>
      </nav>
    </div>
  </div>
</div>
<script>
// ── Shop Orders pager ────────────────────────────────────────
// Client-side pagination: the page renders every order for the selected status
// tab and paging happens in the browser (page size 5, 10, 20 or 50, default 5).
// No page reload, no server round-trip. Mirrors the member My Orders pager.
(function () {
  var ordersCard = document.getElementById('adminOrdersCard');
  if (!ordersCard) return;
  var sizeSel   = document.getElementById('adminOrdersPageSize');
  var pagerNav  = document.getElementById('adminOrdersPager');
  var pagerUl   = pagerNav.querySelector('ul');
  var pagerInfo = document.getElementById('adminOrdersPagerInfo');
  var page = 1;
  var size = sizeSel ? (parseInt(sizeSel.value, 10) || 5) : 5;

  function drawOrderRows() {
    var rows = Array.prototype.slice.call(ordersCard.querySelectorAll('.js-order-row'));
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
    var rows = ordersCard.querySelectorAll('.js-order-row');
    var pages = Math.max(1, Math.ceil(rows.length / size));
    if (cmd === 'first') page = 1;
    else if (cmd === 'prev') page = Math.max(1, page - 1);
    else if (cmd === 'next') page = Math.min(pages, page + 1);
    else if (cmd === 'last') page = pages;
    else page = parseInt(cmd, 10) || 1;
    drawOrderRows();
  });

  if (sizeSel) sizeSel.addEventListener('change', function () {
    size = parseInt(this.value, 10) || 5;
    page = 1;
    drawOrderRows();
  });

  drawOrderRows();
})();
</script>
<?php require 'views/partials/footer.php'; ?>
