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
$page_styles = <<<CSS
.product-detail-img { max-height: 420px; object-fit: contain; width: 100%; }
.product-detail-img-empty { height: 320px; background: var(--bs-gray-100, #f4f6fb); }
CSS;
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb mb-0">
                  <li class="breadcrumb-item"><a href="<?= e(url('shop')) ?>">Shop</a></li>
                  <li class="breadcrumb-item active" aria-current="page"><?= e((string) $product['name']) ?></li>
                </ol>
              </nav>

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
<?php if ($image !== null): ?>
                      <img src="<?= e($image) ?>" alt="<?= e((string) $product['name']) ?>" class="rounded product-detail-img" />
<?php else: ?>
                      <div class="rounded product-detail-img-empty d-flex align-items-center justify-content-center">
                        <i class="icon-base bx bx-package icon-xl text-body-secondary"></i>
                      </div>
<?php endif; ?>
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

                      <div class="d-flex flex-wrap gap-2 mb-5">
                        <button type="button" class="btn btn-primary" disabled title="Arrives with the cart in Phase 4">
                          <i class="icon-base bx bx-cart-plus me-1"></i> Add to cart
                        </button>
                        <a href="<?= e(url('shop')) ?>" class="btn btn-label-secondary">
                          <i class="icon-base bx bx-arrow-back me-1"></i> Back to shop
                        </a>
                      </div>

                      <div class="alert alert-info d-flex align-items-start gap-2 mb-5" role="alert">
                        <i class="icon-base bx bx-info-circle fs-4"></i>
                        <div class="small">
                          Checkout is being built. The cart opens in Phase 4 and payments in Phase 6 —
                          this page is the Phase 2 catalogue detail.
                        </div>
                      </div>

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
