<?php
/**
 * @file   views/member/cart.php
 * @brief  Full cart page (plan §5.12). DOM IDs are namespaced with "cartPage"
 *         because the global offcanvas uses the bare ones (§5.1).
 *         Expects: $cartId, $items, $totals, $stockErrors.
 */
?>
<?php $pageTitle = 'Cart'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Cart</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Prices are confirmed at checkout.</p>
      </div>
      <a href="<?= link_to('shop') ?>" class="btn btn-outline-primary btn-sm">🛍️ Continue shopping</a>
    </div>

    <?php if ($stockErrors): ?>
      <div class="alert alert-warning">
        <?php foreach ($stockErrors as $se): ?><div style="font-size:.85rem;"><?= e($se) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card">
          <?php if (empty($items)): ?>
            <div class="card-body text-center py-5">
              <div class="mt-1 h5 mb-0">Cart is empty</div>
              <a href="<?= link_to('shop') ?>" class="btn btn-primary btn-sm mt-3">Browse the Shop</a>
            </div>
          <?php else: ?>
            <div class="table-responsive" id="cartPageItems">
              <table class="table table-hover align-middle mb-0">
                <thead>
                  <tr>
                    <th style="padding-left:1.25rem;">Product</th>
                    <th class="text-end">Price</th>
                    <th class="text-center" style="width:150px;">Quantity</th>
                    <th class="text-end">Line total</th>
                    <th style="width:60px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($items as $item): $img = $item['image_url'] ? APP_URL.'/uploads/'.e($item['image_url']) : null; ?>
                    <tr class="cart-item" data-item-id="<?= (int) $item['id'] ?>" data-unit-price="<?= (float) $item['unit_price'] ?>">
                      <td style="padding-left:1.25rem;">
                        <div class="d-flex align-items-center gap-2">
                          <?php if ($img): ?><img src="<?= $img ?>" alt="" style="width:42px;height:42px;object-fit:cover;border-radius:.4rem;">
                          <?php else: ?><div style="width:42px;height:42px;border-radius:.4rem;background:#f4f6fb;display:flex;align-items:center;justify-content:center;">🛍️</div><?php endif; ?>
                          <div>
                            <div class="fw-semibold"><?= e($item['product_name']) ?></div>
                            <div class="text-muted" style="font-size:.72rem;"><?= (int) $item['stock'] ?> in stock</div>
                          </div>
                        </div>
                      </td>
                      <td class="text-end font-mono"><?= number_format((float) $item['unit_price'], 2) ?></td>
                      <td class="text-center">
                        <div class="input-group input-group-sm justify-content-center" style="width:120px;margin:0 auto;">
                          <button class="btn border cart-qty-btn" data-dir="down" type="button" aria-label="Decrease quantity of <?= $item['product_name'] ?>">−</button>
                          <input type="number" class="form-control text-center cart-qty-input" data-item-id="<?= (int) $item['id'] ?>" value="<?= (int) $item['quantity'] ?>" min="1" max="<?= (int) $item['stock'] ?>">
                          <button class="btn border cart-qty-btn" data-dir="up" type="button" aria-label="Increase quantity of <?= $item['product_name'] ?>">+</button>
                        </div>
                      </td>
                      <td class="text-end font-mono fw-semibold"><?= number_format((float) $item['unit_price'] * (int) $item['quantity'], 2) ?></td>
                      <td class="text-end" style="padding-right:1rem;">
                        <button class="btn btn-sm btn-link text-danger cart-remove-btn" data-item-id="<?= (int) $item['id'] ?>" type="button" title="Remove" style="text-decoration:none;">✕</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card" id="cartPageFooter">
          <div class="card-header"><span class="card-title">Summary</span></div>
          <div class="card-body">
            <div class="d-flex justify-content-between mb-1"><span>Items</span><span id="cartPageItemsCount"><?= (int) $totals['total_items'] ?></span></div>
            <div class="d-flex justify-content-between mb-1"><span>Subtotal</span><span id="cartPageSubtotal"><?= number_format((float) $totals['total_price'], 2) ?> PHP</span></div>
            <hr>
            <div class="d-flex justify-content-between fw-bold">
              <span>Total</span><span id="cartPageTotalPrice"><?= number_format((float) $totals['total_price'], 2) ?> PHP</span>
            </div>
            <div class="text-muted mt-2" style="font-size:.75rem;">Total is what you pay. No shipping or service fee.</div>
          </div>
          <div class="card-footer">
            <?php if (!empty($items) && !$stockErrors): ?>
              <a href="<?= link_to('checkout') ?>" class="btn btn-primary w-100" id="checkoutBtn">Proceed to checkout</a>
            <?php elseif (!empty($stockErrors)): ?>
              <button class="btn btn-primary w-100" disabled>Out of stock</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var CSRF = '<?= e(csrf_token()) ?>';
  function post(url, data) {
    var body = new URLSearchParams(data);
    body.set('csrf_token', CSRF);
    return fetch(url, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: body})
      .then(function (r) { return r.json(); });
  }
  function fmt(n) { return parseFloat(n).toFixed(2); }
  function refreshSummary(items) {
    var subtotal = 0, count = 0;
    items.forEach(function (row) {
      var price = parseFloat(row.dataset.unitPrice) || 0;
      var qty = parseInt(row.querySelector('.cart-qty-input').value, 10) || 0;
      subtotal += price * qty; count += qty;
      var line = row.querySelector('[data-line-total]') || row.cells[3];
      if (line) line.textContent = fmt(price * qty);
    });
    document.getElementById('cartPageSubtotal').textContent = fmt(subtotal) + ' PHP';
    document.getElementById('cartPageTotalPrice').textContent = fmt(subtotal) + ' PHP';
    document.getElementById('cartPageItemsCount').textContent = count;
    var badge = document.querySelector('.topbar-wrapper .topbar-cart-badge');
    if (badge) {
      badge.textContent = count;
      badge.classList.toggle('d-none', count <= 0);
    }
    document.querySelectorAll('.sidebar-cart-badge').forEach(function (el) {
      el.textContent = count;
      el.classList.toggle('d-none', count <= 0);
    });
  }
  function observe() {
    var list = document.getElementById('cartPageItems');
    if (!list || list.dataset.observed) return;
    list.dataset.observed = '1';
    list.querySelectorAll('.cart-qty-input').forEach(function (i) { i.dataset.qty = i.value; });  // last qty the server confirmed
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.cart-qty-btn');
      if (btn) {
        var row = btn.closest('.cart-item');
        var input = row.querySelector('.cart-qty-input');
        var cur = parseInt(input.value, 10) || 1;
        var stock = parseInt(input.max, 10) || 0;  // server-rendered stock ceiling
        if (btn.dataset.dir === 'up') {
          var next = cur + 1;
          if (stock > 0 && next > stock) {
            // Stock ceiling: hold the quantity at stock and notify (same
            // wording the server would send) instead of POSTing a doomed value.
            input.value = Math.min(cur, stock);
            if (window.showToast) showToast('Insufficient stock. Requested ' + next + ', only ' + stock + ' available.', 'error');
            if (parseInt(input.value, 10) !== cur) {
              input.dispatchEvent(new Event('change', { bubbles: true }));  // stale over-stock line → sync down
            }
            return;
          }
          input.value = next;
        } else {
          input.value = Math.max(1, cur - 1);
        }
        input.dispatchEvent(new Event('change', { bubbles: true }));  // must bubble to reach the list-level listener
        return;
      }        var rm = e.target.closest('.cart-remove-btn');
        if (rm) {
          var r = rm.closest('.cart-item');
          var checkoutBtn = document.getElementById('checkoutBtn');
          post('<?= link_to('remove_cart_item') ?>', {item_id: r.dataset.itemId}).then(function (res) {
            if (!res.ok) { if (window.showToast) showToast(res.error || 'Remove failed', 'error'); return; }
            r.remove();
            refreshSummary(document.querySelectorAll('#cartPageItems .cart-item'));
            document.getElementById('cartPageItemsCount').textContent = res.count;
            // When the cart becomes empty after a remove, disable the checkout
            // affordance from the UI so no checkout navigation can be triggered.
            if (res.count == 0 && checkoutBtn) {
              if (checkoutBtn.tagName === 'A') {
                checkoutBtn.style.pointerEvents = 'none';
                checkoutBtn.style.opacity = '0.5';
              } else if (checkoutBtn.tagName === 'BUTTON') {
                checkoutBtn.disabled = true;
              }
            }
          });
        }
    });
    list.addEventListener('change', function (e) {
      if (!e.target.classList.contains('cart-qty-input')) return;
      var row = e.target.closest('.cart-item');
      var input = e.target;
      var v = Math.max(1, parseInt(input.value, 10) || 1);
      var stock = parseInt(input.max, 10) || 0;
      // Typed over the stock ceiling: hold at stock + notify, don't POST a
      // value we know the server will reject.
      if (stock > 0 && v > stock) {
        input.value = stock;
        if (window.showToast) showToast('Insufficient stock. Requested ' + v + ', only ' + stock + ' available.', 'error');
        refreshSummary(document.querySelectorAll('#cartPageItems .cart-item'));
        return;
      }
      input.value = v;
      post('<?= link_to('update_cart_item') ?>', {item_id: row.dataset.itemId, quantity: v}).then(function (res) {
        if (!res.ok) {
          if (window.showToast) showToast(res.error || 'Update failed', 'error');
          input.value = input.dataset.qty || input.defaultValue;  // server kept the old quantity
          refreshSummary(document.querySelectorAll('#cartPageItems .cart-item'));
          return;
        }
        input.dataset.qty = String(v);
        refreshSummary(document.querySelectorAll('#cartPageItems .cart-item'));
        document.getElementById('cartPageItemsCount').textContent = res.count;
      });
    });
  }
  document.addEventListener('DOMContentLoaded', observe);
})();
</script>
<?php require 'views/partials/footer.php'; ?>
