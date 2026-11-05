<?php
declare(strict_types=1);

/**
 * Full cart page (plan §5.2, Phase 4; reference views/member/cart.php).
 *
 * The desktop table is the primary list; every mutation also has a plain
 * POST form so the page works with JavaScript disabled (P4-T12). The
 * drawer is patched by app-cart.js from the server's own partial, so the
 * badge and the list can never disagree.
 *
 * @var array{
 *   cart: array<string, mixed>|null,
 *   items: list<array<string, mixed>>,
 *   totals: array{items: int, quantity: int, subtotal: float},
 *   stock_errors: list<string>
 * } $state
 * @var bool $shop_open
 */

$items       = $state['items'] ?? [];
$totals      = $state['totals'] ?? ['items' => 0, 'quantity' => 0, 'subtotal' => 0.0];
$stockErrors = $state['stock_errors'] ?? [];
$subtotal    = (float) ($totals['subtotal'] ?? 0);
$isEmpty     = $items === [];
?>
            <div class="container-xxl flex-grow-1 container-p-y cart-page" data-cart-page>
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Your cart</h4>
                  <p class="text-body-secondary mb-0">
                    Prices and stock are read from the catalogue every time you open this page.
                  </p>
                </div>
                <a href="<?= e(url('shop')) ?>" class="btn btn-label-primary">
                  <i class="icon-base bx bx-arrow-back me-1"></i> Continue shopping
                </a>
              </div>

<?php if (!$shop_open): ?>
              <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                <i class="icon-base bx bx-store-alt fs-4"></i>
                <span>The shop is currently closed. Your cart is saved and can be edited once it reopens.</span>
              </div>
<?php endif; ?>

<?php if ($isEmpty): ?>
              <div class="card">
                <div class="card-body">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-cart',
        'title'        => 'Your cart is empty',
        'text'         => 'Browse the catalogue and add something you like — it will be kept here for you.',
        'action_href'  => 'shop',
        'action_label' => 'Browse the shop',
    ]);
?>
                </div>
              </div>
<?php else: ?>
<?php if ($stockErrors !== []): ?>
              <div class="alert alert-warning" role="alert">
                <div class="d-flex align-items-center gap-2 fw-semibold mb-2">
                  <i class="icon-base bx bx-error fs-4"></i>
                  <span>Some items need attention before checkout</span>
                </div>
                <ul class="mb-0 ps-4">
<?php foreach ($stockErrors as $error): ?>
                  <li class="small"><?= e($error) ?></li>
<?php endforeach; ?>
                </ul>
              </div>
<?php endif; ?>

              <div class="row g-6">
                <div class="col-lg-8">
                  <div class="card cart-table-card">
                    <div class="table-responsive">
                      <table class="table align-middle cart-table mb-0">
                        <thead>
                          <tr>
                            <th scope="col" class="cart-col-product">Product</th>
                            <th scope="col" class="text-end">Price</th>
                            <th scope="col" class="text-center cart-col-qty">Quantity</th>
                            <th scope="col" class="text-end">Line total</th>
                            <th scope="col" class="cart-col-remove"><span class="visually-hidden">Remove</span></th>
                          </tr>
                        </thead>
                        <tbody data-cart-page-items>
<?php foreach ($items as $item): ?>
                          <tr
                            class="cart-row<?= ($item['over_available'] ?? false) || ($item['is_inactive'] ?? false) ? ' is-problem' : '' ?>"
                            data-cart-row
                            data-item-id="<?= (int) $item['id'] ?>"
                            data-unit-price="<?= e(number_format((float) $item['current_price'], 2, '.', '')) ?>"
                          >
                            <td>
                              <div class="d-flex align-items-center gap-3">
                                <a href="<?= e(url('shop/' . (int) $item['product_id'])) ?>" class="cart-page-media flex-shrink-0">
<?php if (!empty($item['image_url'])): ?>
                                  <img src="<?= e(UploadService::url($item['image_url']) ?? '') ?>" alt="<?= e((string) $item['product_name']) ?>" loading="lazy" />
<?php else: ?>
                                  <span class="cart-drawer-media-empty" aria-hidden="true"><i class="icon-base bx bx-package"></i></span>
<?php endif; ?>
                                </a>
                                <div class="min-w-0">
                                  <a href="<?= e(url('shop/' . (int) $item['product_id'])) ?>" class="cart-row-name">
                                    <?= e((string) $item['product_name']) ?>
                                  </a>
                                  <div class="text-body-secondary small">
                                    <?= e((string) ($item['product_sku'] ?? '')) ?>
                                  </div>
                                  <div class="mt-1">
<?php if (($item['is_inactive'] ?? false) === true): ?>
                                    <span class="badge bg-label-secondary">Unavailable</span>
<?php elseif (($item['over_available'] ?? false) === true): ?>
                                    <span class="badge bg-label-danger">Only <?= (int) $item['available'] ?> left</span>
<?php else: ?>
                                    <span class="badge bg-label-<?= e($item['stock_badge']['tone']) ?>"><?= e($item['stock_badge']['label']) ?></span>
<?php endif; ?>
                                  </div>
                                </div>
                              </div>
                            </td>
                            <td class="text-end text-nowrap"><?= e(money((float) $item['current_price'])) ?></td>
                            <td class="text-center">
                              <form method="POST" action="<?= e(url('cart/update')) ?>" data-cart-qty-form class="cart-qty-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" />
                                <!-- First submit button in the form: pressing Enter in the
                                     quantity field submits the typed value, not a step. -->
                                <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true">Update</button>
                                <div class="input-group input-group-sm cart-qty-group mx-auto">
                                  <button class="btn btn-outline-secondary" type="submit" name="direction" value="down" data-cart-step="-1" aria-label="Decrease quantity of <?= e((string) $item['product_name']) ?>">&minus;</button>
                                  <input
                                    type="number"
                                    class="form-control text-center"
                                    name="quantity"
                                    value="<?= (int) $item['quantity'] ?>"
                                    min="0"
                                    max="<?= max(1, (int) $item['available']) ?>"
                                    data-cart-qty
                                    aria-label="Quantity of <?= e((string) $item['product_name']) ?>"
                                  />
                                  <button class="btn btn-outline-secondary" type="submit" name="direction" value="up" data-cart-step="1" aria-label="Increase quantity of <?= e((string) $item['product_name']) ?>">+</button>
                                </div>
                                <button type="submit" class="btn btn-sm btn-label-secondary mt-2 cart-update-btn">Update</button>
                              </form>
                            </td>
                            <td class="text-end text-nowrap fw-semibold" data-line-total><?= e(money((float) $item['line_total'])) ?></td>
                            <td class="text-end">
                              <form method="POST" action="<?= e(url('cart/remove')) ?>" data-cart-remove-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>" />
                                <button type="submit" class="btn btn-icon btn-sm btn-label-danger" data-cart-remove aria-label="Remove <?= e((string) $item['product_name']) ?>" title="Remove">
                                  <i class="icon-base bx bx-trash"></i>
                                </button>
                              </form>
                            </td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>

                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-4">
                    <a href="<?= e(url('shop')) ?>" class="btn btn-label-secondary btn-sm">
                      <i class="icon-base bx bx-arrow-back me-1"></i> Continue shopping
                    </a>
                    <span class="text-body-secondary small">Prices update automatically when the catalogue changes.</span>
                  </div>
                </div>

                <div class="col-lg-4">
                  <div class="card cart-summary">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Order summary</h5>
                    </div>
                    <div class="card-body">
                      <div class="d-flex justify-content-between mb-2">
                        <span class="text-body-secondary">Items</span>
                        <span data-cart-count><?= (int) $totals['quantity'] ?></span>
                      </div>
                      <div class="d-flex justify-content-between mb-2">
                        <span class="text-body-secondary">Subtotal</span>
                        <span data-cart-subtotal><?= e(money($subtotal)) ?></span>
                      </div>
                      <div class="d-flex justify-content-between mb-2">
                        <span class="text-body-secondary">Shipping</span>
                        <span class="text-body-secondary">Free</span>
                      </div>
                      <hr />
                      <div class="d-flex justify-content-between fw-semibold">
                        <span>Total</span>
                        <span data-cart-total><?= e(money($subtotal)) ?></span>
                      </div>
                      <p class="text-body-secondary small mt-3 mb-0" aria-live="polite">
<?php if ($stockErrors !== []): ?>
                        Fix the quantities marked above to continue.
<?php else: ?>
                        Total is what you pay — no shipping or service fee.
<?php endif; ?>
                      </p>
                    </div>
                    <div class="card-footer">
                      <button type="button" class="btn btn-primary w-100" disabled>
                        <i class="icon-base bx bx-credit-card me-1"></i> Proceed to checkout
                      </button>
<?php if ($stockErrors === []): ?>
                      <p class="text-body-secondary small text-center mt-3 mb-0">
                        Checkout opens in Phase 6 — your cart is saved.
                      </p>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
<?php endif; ?>
            </div>
