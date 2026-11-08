<?php
declare(strict_types=1);

/**
 * Product detail (plan §7 Phase 2). The catalogue card links here; the
 * quick-view modal on the grid is the lighter alternative.
 *
 * One live surface per product (`shop.product:{id}`). The breadcrumb row,
 * the shop-closed notice and the product card are regions; the card owns the
 * quantity input, so it is held while the member has unsaved input.
 *
 * @var array<string, mixed> $product
 * @var int                  $available
 * @var array{key: string, label: string, tone: string} $stock
 * @var bool                 $shop_open
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$shop_open = $shop_open ?? true;

$live = $live ?? [
    'surface'  => 'shop.product:' . (int) ($product['id'] ?? 0),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y shop-product-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

              <div class="text-end">
                <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
              </div>

              <div
                class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none"
                data-live-stale
                role="status"
                aria-live="polite"
              >
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>This product changed in another tab.</strong>
                  Your unsaved quantity was kept, so the page may be out of date.
                  <a href="<?= e(url('shop/' . (int) ($product['id'] ?? 0))) ?>" class="alert-link" data-live-reload>
                    Reload the product
                  </a>
                  to see the latest.
                </div>
              </div>

<?= View::capture('shop/product/_heading', ['product' => $product]) ?>

<?= View::capture('shop/product/_notice', ['shop_open' => $shop_open]) ?>

<?= View::capture('shop/product/_card', [
    'product'   => $product,
    'available' => $available,
    'stock'     => $stock,
    'shop_open' => $shop_open,
]) ?>
            </div>
