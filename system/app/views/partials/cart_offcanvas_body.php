<?php
declare(strict_types=1);

/**
 * Off-canvas cart body (plan §5.2, Phase 4).
 *
 * Rendered inside `partials/cart_offcanvas.php` and — after every AJAX
 * mutation — returned by /cart/count and the cart endpoints, so the
 * drawer always shows server truth instead of client-side arithmetic.
 *
 * @var array{items: list<array<string, mixed>>, totals: array{items: int, quantity: int, subtotal: float}} $state
 */

$items  = $state['items'] ?? [];
$totals = $state['totals'] ?? ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0];
$total  = (float) ($totals['subtotal'] ?? 0);
$hasProblems = false;

foreach ($items as $item) {
    if (($item['over_available'] ?? false) === true || ($item['is_inactive'] ?? false) === true) {
        $hasProblems = true;
        break;
    }
}
?>
<?php if ($items === []): ?>
              <div class="cart-drawer-empty d-flex flex-column align-items-center justify-content-center flex-grow-1 text-center p-6">
                <span class="avatar avatar-lg">
                  <span class="avatar-initial rounded-circle bg-label-primary">
                    <i class="icon-base bx bx-cart icon-lg"></i>
                  </span>
                </span>
                <p class="mt-4 mb-1 fw-semibold">Your cart is empty</p>
                <p class="text-body-secondary small mb-4">Fresh picks are one tap away.</p>
                <a href="<?= e(url('shop')) ?>" class="btn btn-sm btn-primary">
                  <i class="icon-base bx bx-store me-1"></i> Browse the shop
                </a>
              </div>
<?php else: ?>
              <div class="cart-drawer-items flex-grow-1 overflow-auto p-4">
<?php foreach ($items as $item): ?>
                <div
                  class="cart-drawer-item d-flex gap-3 pb-3 mb-3 border-bottom"
                  data-cart-row
                  data-item-id="<?= (int) $item['id'] ?>"
                  data-unit-price="<?= e(number_format((float) $item['current_price'], 2, '.', '')) ?>"
                >
                  <div class="cart-drawer-media flex-shrink-0">
<?php if (!empty($item['image_url'])): ?>
                    <img src="<?= e(UploadService::url($item['image_url']) ?? '') ?>" alt="<?= e((string) $item['product_name']) ?>" loading="lazy" />
<?php else: ?>
                    <span class="cart-drawer-media-empty" aria-hidden="true">
                      <i class="icon-base bx bx-package"></i>
                    </span>
<?php endif; ?>
                  </div>

                  <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                      <a href="<?= e(url('shop/' . (int) $item['product_id'])) ?>" class="cart-drawer-name">
                        <?= e((string) $item['product_name']) ?>
                      </a>
                      <span class="cart-drawer-line" data-line-total><?= e(money((float) $item['line_total'])) ?></span>
                    </div>
                    <div class="text-body-secondary small mb-2">
                      <?= e(money((float) $item['current_price'])) ?> each
<?php if (($item['over_available'] ?? false) === true): ?>
                      <span class="badge bg-label-danger ms-1">Only <?= (int) $item['available'] ?> left</span>
<?php elseif (($item['is_inactive'] ?? false) === true): ?>
                      <span class="badge bg-label-secondary ms-1">Unavailable</span>
<?php endif; ?>
                    </div>

                    <div class="d-flex align-items-center justify-content-between gap-2">
                      <form method="POST" action="<?= e(url('cart/update')) ?>" data-cart-qty-form class="cart-qty-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" />
                        <div class="input-group input-group-sm cart-qty-group">
                          <button class="btn btn-outline-secondary" type="button" data-cart-step="-1" aria-label="Decrease quantity of <?= e((string) $item['product_name']) ?>">&minus;</button>
                          <input
                            type="number"
                            class="form-control text-center"
                            name="quantity"
                            value="<?= (int) $item['quantity'] ?>"
                            min="1"
                            max="<?= max(1, (int) $item['available']) ?>"
                            data-cart-qty
                            aria-label="Quantity of <?= e((string) $item['product_name']) ?>"
                          />
                          <button class="btn btn-outline-secondary" type="button" data-cart-step="1" aria-label="Increase quantity of <?= e((string) $item['product_name']) ?>">+</button>
                        </div>
                        <button type="submit" class="visually-hidden">Update</button>
                      </form>

                      <form method="POST" action="<?= e(url('cart/remove')) ?>" data-cart-remove-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" />
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" data-cart-remove>Remove</button>
                      </form>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <div class="cart-drawer-footer border-top p-4">
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-body-secondary">Items</span>
                  <span data-cart-count><?= (int) $totals['quantity'] ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-body-secondary">Subtotal</span>
                  <span data-cart-subtotal><?= e(money($total)) ?></span>
                </div>
                <div class="d-flex justify-content-between fw-semibold">
                  <span>Total</span>
                  <span data-cart-total><?= e(money($total)) ?></span>
                </div>
                <p class="text-body-secondary small mt-2 mb-3">No shipping or service fee.</p>
<?php if ($hasProblems): ?>
                <button type="button" class="btn btn-primary w-100" disabled>Fix your cart to check out</button>
                <a href="<?= e(url('cart')) ?>" class="btn btn-label-primary w-100 mt-2">Review your cart</a>
<?php else: ?>
                <a href="<?= e(url('checkout')) ?>" class="btn btn-primary w-100">Checkout</a>
                <a href="<?= e(url('cart')) ?>" class="btn btn-label-secondary w-100 mt-2" data-cart-open-cart>View full cart</a>
<?php endif; ?>
              </div>
<?php endif; ?>
