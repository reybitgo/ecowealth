<?php
/**
 * @file   views/member/shop.php
 * @brief  Storefront catalog (plan §5.9). Expects: $products, $cartCount.
 */
?>
<?php $pageTitle = 'Shop'; ?>
<?php require 'views/partials/head.php'; ?>
<?php require 'views/partials/sidebar_member.php'; ?>
<div class="main-content">
  <?php require 'views/partials/topbar.php'; ?>
  <div class="page-content">
    <?= render_flash() ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="mb-0">Shop</h4>
        <p class="text-muted mb-0" style="font-size:.8rem;">Browse our products and order for delivery.</p>
      </div>
      <a href="<?= link_to('cart') ?>" class="btn btn-outline-primary">🛒 View Cart
        <span id="shopViewCartBadge" class="badge bg-primary ms-1<?= $cartCount > 0 ? '' : ' d-none' ?>"><?= $cartCount ?></span>
      </a>
    </div>

    <?php if (empty($products)): ?>
      <div class="card"><div class="card-body text-center py-5 text-muted">
        <div style="font-size:2.5rem;opacity:.35;">🛍️</div>
        <div class="mt-2">No products are available right now. Check back soon.</div>
      </div></div>
    <?php else: ?>
      <div class="row g-3" id="shopContent">
        <?php /* preload cart items so the card can show per-product in-cart state */ ?>
        <?php /* phpcs:disable Squiz.PHP.DisallowShortOpenTag.E short tag is intentional inline JSON */ ?>
        <?php
          $cartItems = [];
          if ($cartId) {
            foreach (Cart::getItems($cartId) as $row) {
              $cartItems[(int) $row['product_id']] = true;   // presence only; the card just renders "Already in cart"
            }
          }
          // One batched query for the whole catalog instead of two per card (M7).
          $availById = Product::availableStockMap(array_column($products, 'id'));
        ?>
        <?php foreach ($products as $p):
          $img     = !empty($p['image_url']) ? APP_URL . '/uploads/' . e($p['image_url']) : null;
          $avail   = $availById[(int) $p['id']] ?? 0;
          $soldOut = $avail <= 0;
        ?>
          <div class="col-12 col-sm-6 col-md-4 col-lg-3">
            <div class="card h-100 d-flex flex-column">
              <?php if ($img): ?>
                <img src="<?= $img ?>" alt="<?= e($p['name']) ?>" class="card-img-top shop-card-img">
              <?php else: ?>
                <div class="card-img-top shop-card-img d-flex align-items-center justify-content-center text-muted" style="background:#f4f6fb;font-size:2.5rem;">🛍️</div>
              <?php endif; ?>
              <div class="card-body d-flex flex-column">
                <h6 class="mb-1"><?= e($p['name']) ?></h6>
                <?php if (!empty($p['short_description'])): ?>
                  <p class="text-muted mb-2" style="font-size:.78rem;"><?= e($p['short_description']) ?></p>
                <?php endif; ?>
                <div class="mt-auto">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><?= fmt_money((float) $p['price']) ?></span>
                    <?php if ($soldOut): ?>
                      <span class="badge bg-secondary">Sold out</span>
                    <?php elseif ($avail <= 5): ?>
                      <span class="badge bg-warning text-dark"><?= $avail ?> left</span>
                    <?php else: ?>
                      <span class="badge bg-success-subtle text-success">In stock</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!$soldOut): ?>
                    <?php $inCart = $cartId && isset($cartItems[(int) $p['id']]); ?>
                    <form method="post" action="<?= link_to('add_to_cart') ?>" data-product-id="<?= (int) $p['id'] ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                      <?php /* The card adds exactly one unit per click (the
                               controller defaults quantity to 1). Adjusting a
                               line up/down is the cart page's job — it has the
                               real stepper — so no Qty field is offered here. */ ?>
                      <button type="submit" class="btn btn-primary shop-add-btn w-100"
                              <?= $inCart ? 'aria-disabled="true" disabled' : '' ?>>
                        <?= $inCart ? 'Already in cart' : 'Add to cart' ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Product description modal (single shared instance) -->
      <div class="modal fade" id="productDescModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="productDescTitle"></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="productDescBody"></div>
          </div>
        </div>
      </div>
      <?php if (!empty($products)): ?>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          var data = <?= json_encode(array_map(function ($p) {
              return ['id' => (int) $p['id'], 'name' => $p['name'], 'desc' => (string) ($p['description'] ?? '')];
          }, $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          document.querySelectorAll('[data-desc-product]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var p = data.find(function (x) { return x.id === parseInt(btn.dataset.descProduct, 10); });
              if (!p) return;
              document.getElementById('productDescTitle').textContent = p.name;
              document.getElementById('productDescBody').textContent = p.desc || 'No description yet.';
              new bootstrap.Modal(document.getElementById('productDescModal')).show();
            });
          });

          // Product-card add-to-cart is intentionally non-incrementing.
          // If a product is already in the cart the card renders a disabled
          // "Already in cart" button + an in-page notice, so repeated taps
          // never add a second copy or bump the quantity.
          // (Cart add is still handled server-side normally for products not
          // in the cart; this guard is only a UI convenience on the shop page.)

          // The off-canvas drawer lives above this page and can change the
          // cart without a reload. Keep the shop card state and the View Cart
          // badge honest: reset the card for an item just removed, and
          // re-sync the badge count after any drawer mutation.
          window.addEventListener('cart:changed', function (e) {
            var d = e.detail || {};
            var badge = document.getElementById('shopViewCartBadge');
            if (badge) {
              badge.textContent = d.count || 0;
              badge.classList.toggle('d-none', (d.count || 0) <= 0);
            }
            if (d.removedProductId) {
              var form = document.querySelector('#shopContent form[data-product-id="' + d.removedProductId + '"]');
              if (form) {
                var btn = form.querySelector('.shop-add-btn');
                if (btn) {
                  btn.disabled = false;
                  btn.removeAttribute('aria-disabled');
                  btn.textContent = 'Add to cart';
                }
              }
            }
          });
        });
      </script>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php require 'views/partials/footer.php'; ?>
