<?php
declare(strict_types=1);

/**
 * Product card — used by the storefront grid (plan §7 Phase 2).
 *
 * The media stage has a fixed 1:1 ratio so a tall pack shot can never
 * push the title, price or actions out of the card. Packaging is shown
 * with object-fit: contain on a soft product-stage background, the way
 * the marketing product line presents it.
 *
 * @var array<string, mixed> $product   Product row
 * @var int                  $available Available units (stock − reservations)
 * @var bool                 $quick_view Render the quick-view button
 */

$available  = (int) ($available ?? 0);
$quick_view = $quick_view ?? true;
$stock      = StockService::label($available);
$image      = UploadService::url($product['image_url'] ?? null);
$href       = url('shop/' . (int) $product['id']);
$name       = (string) ($product['name'] ?? '');
$sku        = trim((string) ($product['sku'] ?? ''));
$short      = trim((string) ($product['short_description'] ?? ''));
$soldOut    = $stock['key'] === 'out';
?>
              <article class="card product-card h-100<?= $soldOut ? ' is-sold-out' : '' ?>">
                <a
                  href="<?= e($href) ?>"
                  class="product-card-media"
                  aria-label="View <?= e($name) ?>"
                >
<?php if ($image !== null): ?>
                  <img
                    src="<?= e($image) ?>"
                    alt="<?= e($name) ?>"
                    class="product-card-img"
                    loading="lazy"
                    decoding="async"
                  />
<?php else: ?>
                  <span class="product-card-img product-card-img-empty" aria-hidden="true">
                    <img
                      src="<?= e(url('assets/img/ecowealth-mark.png')) ?>"
                      alt=""
                      class="product-card-placeholder-mark"
                      loading="lazy"
                      decoding="async"
                    />
                  </span>
<?php endif; ?>
                  <span class="badge rounded-pill bg-label-<?= e($stock['tone']) ?> product-card-stock">
                    <?= e($stock['label']) ?>
                  </span>
                </a>

                <div class="card-body product-card-body">
                  <div class="product-card-kicker"><?= e($sku !== '' ? $sku : APP_NAME) ?></div>

                  <h6 class="product-card-title">
                    <a href="<?= e($href) ?>"><?= e($name) ?></a>
                  </h6>

<?php if ($short !== ''): ?>
                  <p class="product-card-desc"><?= e($short) ?></p>
<?php endif; ?>

                  <div class="product-card-footer">
                    <div class="product-card-price"><?= e(money((float) $product['price'])) ?></div>
                    <div class="product-card-actions">
<?php if ($quick_view && !empty($product['description']) && !$soldOut): ?>
                      <button
                        type="button"
                        class="btn btn-icon btn-sm btn-label-secondary product-card-quick"
                        data-quick-view="<?= (int) $product['id'] ?>"
                        title="Quick view"
                        aria-label="Quick view <?= e($name) ?>"
                      >
                        <i class="icon-base bx bx-show"></i>
                      </button>
<?php endif; ?>
<?php if (!$soldOut): ?>
                      <form method="POST" action="<?= e(url('cart/add')) ?>" class="product-card-add" data-cart-add>
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>" />
                        <input type="hidden" name="quantity" value="1" />
                        <button
                          type="submit"
                          class="btn btn-icon btn-sm btn-label-primary"
                          title="Add to cart"
                          aria-label="Add <?= e($name) ?> to cart"
                        >
                          <i class="icon-base bx bx-cart-add"></i>
                        </button>
                      </form>
<?php endif; ?>
                      <a
                        href="<?= e($href) ?>"
                        class="btn btn-sm <?= $soldOut ? 'btn-label-secondary' : 'btn-primary' ?> product-card-cta"
                      >
                        <?= $soldOut ? 'View' : 'Details' ?>
                      </a>
                    </div>
                  </div>
                </div>
              </article>
