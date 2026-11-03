<?php
declare(strict_types=1);

/**
 * Product card — used by the storefront grid (plan §7 Phase 2).
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
$soldOut    = $stock['key'] === 'out';
?>
              <div class="card h-100 product-card">
                <a href="<?= e($href) ?>" class="text-decoration-none" tabindex="-1" aria-hidden="true">
<?php if ($image !== null): ?>
                  <img
                    src="<?= e($image) ?>"
                    alt="<?= e((string) $product['name']) ?>"
                    class="card-img-top product-card-img"
                    loading="lazy"
                  />
<?php else: ?>
                  <div class="card-img-top product-card-img product-card-img-empty d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-package icon-xl text-body-secondary"></i>
                  </div>
<?php endif; ?>
                </a>
                <div class="card-body d-flex flex-column">
                  <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                    <a href="<?= e($href) ?>" class="text-body text-decoration-none">
                      <h6 class="mb-0"><?= e((string) $product['name']) ?></h6>
                    </a>
                    <span class="badge bg-label-<?= e($stock['tone']) ?>"><?= e($stock['label']) ?></span>
                  </div>

<?php if (!empty($product['short_description'])): ?>
                  <p class="text-body-secondary small mb-3"><?= e((string) $product['short_description']) ?></p>
<?php endif; ?>

                  <div class="mt-auto d-flex align-items-center justify-content-between gap-2">
                    <span class="fw-semibold"><?= e(money((float) $product['price'])) ?></span>
                    <div class="d-flex align-items-center gap-1">
<?php if ($quick_view && !empty($product['description']) && !$soldOut): ?>
                      <button
                        type="button"
                        class="btn btn-sm btn-icon btn-label-secondary"
                        data-quick-view="<?= (int) $product['id'] ?>"
                        title="Quick view"
                        aria-label="Quick view <?= e((string) $product['name']) ?>"
                      >
                        <i class="icon-base bx bx-show"></i>
                      </button>
<?php endif; ?>
                      <a href="<?= e($href) ?>" class="btn btn-sm btn-primary">
                        <?= $soldOut ? 'View' : 'Details' ?>
                      </a>
                    </div>
                  </div>
                </div>
              </div>
