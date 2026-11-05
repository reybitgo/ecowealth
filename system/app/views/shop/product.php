<?php
declare(strict_types=1);

/**
 * Product detail (plan §7 Phase 2). The catalogue card links here; the
 * quick-view modal on the grid is the lighter alternative.
 *
 * @var array<string, mixed> $product
 * @var int                  $available
 * @var array{key: string, label: string, tone: string} $stock
 * @var bool                 $shop_open
 */

$shop_open = $shop_open ?? true;
$image     = UploadService::url($product['image_url'] ?? null);
$soldOut   = $stock['key'] === 'out';
$sku       = trim((string) ($product['sku'] ?? ''));
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
                <nav aria-label="breadcrumb" class="mb-0">
                  <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= e(url('shop')) ?>">Shop</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= e((string) $product['name']) ?></li>
                  </ol>
                </nav>
                <a href="<?= e(url('shop')) ?>" class="btn btn-sm btn-label-secondary flex-shrink-0">
                  <i class="icon-base bx bx-arrow-back me-1"></i> Back to shop
                </a>
              </div>

<?php if (!$shop_open): ?>
              <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                <i class="icon-base bx bx-store-alt fs-4"></i>
                <span>The shop is currently closed. Please check back soon.</span>
              </div>
<?php endif; ?>

              <div class="card">
                <div class="card-body">
                  <div class="row g-6">
                    <div class="col-md-5">
                      <div class="product-detail-media<?= $image === null ? ' product-detail-media-empty' : '' ?>">
<?php if ($image !== null): ?>
                        <img src="<?= e($image) ?>" alt="<?= e((string) $product['name']) ?>" class="product-detail-img" />
<?php else: ?>
                        <img
                          src="<?= e(url('assets/img/ecowealth-mark.png')) ?>"
                          alt=""
                          aria-hidden="true"
                          class="product-detail-placeholder-mark"
                        />
<?php endif; ?>
                      </div>
                    </div>

                    <div class="col-md-7">
                      <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                        <h4 class="mb-0"><?= e((string) $product['name']) ?></h4>
                        <span class="badge bg-label-<?= e($stock['tone']) ?> fs-6"><?= e($stock['label']) ?></span>
                      </div>

<?php if (!empty($product['short_description'])): ?>
                      <p class="text-body-secondary mb-4"><?= e((string) $product['short_description']) ?></p>
<?php endif; ?>

                      <div class="d-flex align-items-baseline gap-3 mb-4">
                        <span class="h3 mb-0"><?= e(money((float) $product['price'])) ?></span>
                        <span class="text-body-secondary small"><?= $available > 0 ? $available . ' available' : 'Out of stock' ?></span>
                      </div>

                      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
<?php if (!$shop_open): ?>
                        <button type="button" class="btn btn-secondary" disabled>
                          <i class="icon-base bx bx-store-alt me-1"></i> Shop closed
                        </button>
<?php elseif ($soldOut): ?>
                        <button type="button" class="btn btn-secondary" disabled>
                          <i class="icon-base bx bx-x-circle me-1"></i> Sold out
                        </button>
<?php else: ?>
                        <form method="POST" action="<?= e(url('cart/add')) ?>" class="d-flex flex-wrap align-items-center gap-2" data-cart-add>
                          <?= csrf_field() ?>
                          <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>" />
                          <div class="input-group product-qty-group">
                            <button type="button" class="btn btn-outline-secondary" data-cart-step="-1" aria-label="Decrease quantity">&minus;</button>
                            <input
                              type="number"
                              class="form-control text-center"
                              name="quantity"
                              value="1"
                              min="1"
                              max="<?= max(1, $available) ?>"
                              data-cart-qty
                              aria-label="Quantity"
                            />
                            <button type="button" class="btn btn-outline-secondary" data-cart-step="1" aria-label="Increase quantity">+</button>
                          </div>
                          <button type="submit" class="btn btn-primary">
                            <i class="icon-base bx bx-cart-add me-1"></i> Add to cart
                          </button>
                        </form>
<?php endif; ?>
                        <a href="<?= e(url('cart')) ?>" class="btn btn-label-primary">
                          <i class="icon-base bx bx-cart me-1"></i> View cart
                        </a>
                      </div>

                      <p class="text-body-secondary small mb-5">
                        <i class="icon-base bx bx-check-circle me-1"></i>
<?php if (!$shop_open): ?>
                        The shop is closed; your cart is saved.
<?php elseif ($soldOut): ?>
                        This product is out of stock. Check back soon.
<?php else: ?>
                        Added items stay in your cart — checkout opens in Phase 6.
<?php endif; ?>
                      </p>

<?php if (!empty($product['description'])): ?>
                      <h6 class="mb-2">About this product</h6>
                      <p class="text-body-secondary mb-5" style="white-space: pre-line"><?= e((string) $product['description']) ?></p>
<?php endif; ?>

                      <dl class="row mb-0 small text-body-secondary">
                        <dt class="col-sm-3 fw-normal">SKU</dt>
                        <dd class="col-sm-9"><?= e($sku !== '' ? $sku : '—') ?></dd>
                        <dt class="col-sm-3 fw-normal">Availability</dt>
                        <dd class="col-sm-9"><?= $soldOut ? 'Sold out' : $available . ' unit' . ($available === 1 ? '' : 's') . ' available' ?></dd>
                      </dl>
                    </div>
                  </div>
                </div>
              </div>
            </div>
