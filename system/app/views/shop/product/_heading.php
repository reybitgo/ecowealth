<?php
declare(strict_types=1);

/**
 * Product detail — breadcrumb and back link.
 *
 * @var array<string, mixed>|null $product
 */
?>
              <div
                class="d-flex align-items-center justify-content-between gap-3 mb-4"
                data-live-region="shop-product-heading"
              >
                <nav aria-label="breadcrumb" class="mb-0">
                  <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= e(url('shop')) ?>">Shop</a></li>
                    <li class="breadcrumb-item active" aria-current="page">
                      <?= e($product === null ? 'Unavailable' : (string) $product['name']) ?>
                    </li>
                  </ol>
                </nav>
                <a href="<?= e(url('shop')) ?>" class="btn btn-sm btn-label-secondary flex-shrink-0">
                  <i class="icon-base bx bx-arrow-back me-1"></i> Back to shop
                </a>
              </div>
