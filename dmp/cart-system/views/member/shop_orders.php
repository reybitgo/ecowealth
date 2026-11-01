<?php
/**
 * @file   views/member/shop_orders.php
 * @brief  Buyer's order history (plan §5.10). Expects: $orders (paginate()).
 */
?>
<?php $pageTitle = 'My Orders'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">My Orders</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Everything you have ordered from the shop.</p>
      </div>
      <a href="<?= link_to('shop') ?>" class="btn btn-outline-primary btn-sm">🛍️ Continue shopping</a>
    </div>

    <div class="card" id="ordersCard">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title">Orders</span>
        <?php if (!empty($orders)): ?>
          <select id="ordersPageSize" class="form-select form-select-sm" aria-label="Orders per page" style="width:auto;">
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
              <th>Placed</th>
              <th class="text-end">Total</th>
              <th>Method</th>
              <th>Status</th>
              <th class="text-end" style="padding-right:1.25rem;"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($orders)): ?>
              <tr><td colspan="6" class="text-center py-5 text-muted">
                <div style="font-size:2rem;opacity:.3;">🧾</div>
                <div class="mt-2">No orders yet.</div>
                <a href="<?= link_to('shop') ?>" class="btn btn-primary btn-sm mt-2">Visit the Shop</a>
              </td></tr>
            <?php else: foreach ($orders as $o): ?>
              <tr class="js-order-row">
                <td style="padding-left:1.25rem;" class="fw-semibold"><?= e($o['order_no']) ?></td>
                <td class="text-muted" style="font-size:.8rem;"><?= e(fmt_datetime((string) $o['created_at'])) ?></td>
                <td class="text-end font-mono"><?= fmt_money((float) $o['total_price']) ?></td>
                <td class="text-muted" style="font-size:.8rem;"><?= e(strtoupper((string) $o['payment_method'])) ?></td>
                <td><?php
                  $badgeStatus = (string) $o['status'];
                  $badgeStamp  = $o['created_at'] ?? null;
                  require __DIR__ . '/../partials/order_status_badge.php';
                ?></td>
                <td class="text-end" style="padding-right:1.25rem;">
                  <?php if (ShopOrder::canMemberAct($o, 'proof')): ?>
                    <a href="<?= link_to('shop_order', ['id' => $o['id']]) ?>" class="btn btn-sm btn-warning">Upload proof</a>
                  <?php endif; ?>
                  <a href="<?= link_to('shop_order', ['id' => $o['id']]) ?>" class="btn btn-sm btn-outline-primary">View</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <nav id="ordersPager" class="d-none card-footer" aria-label="Order pages">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <span id="ordersPagerInfo" class="text-muted small"></span>
          <ul class="pagination pagination-sm mb-0 ms-auto"></ul>
        </div>
      </nav>
    </div>
  </div>
</div>
<script>
// ── My Orders pager ──────────────────────────────────────────
// Client-side pagination: the page renders every order and paging happens in
// the browser (page size 5, 10, 20 or 50, default 5). No page reload, no
// server round-trip. Mirrors the admin history timeline pager
// (views/admin/shop_order.php).
(function () {
  var ordersCard = document.getElementById('ordersCard');
  if (!ordersCard) return;
  var sizeSel   = document.getElementById('ordersPageSize');
  var pagerNav  = document.getElementById('ordersPager');
  var pagerUl   = pagerNav.querySelector('ul');
  var pagerInfo = document.getElementById('ordersPagerInfo');
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
