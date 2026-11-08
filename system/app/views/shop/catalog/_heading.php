<?php
declare(strict_types=1);

/**
 * Storefront catalogue — heading and result count.
 *
 * @var Paginator $pager
 * @var array{q?: string} $filters
 */

$query = trim((string) ($filters['q'] ?? ''));
?>
                <div data-live-region="shop-catalog-heading">
                  <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <h4 class="mb-0">Shop</h4>
<?php if ($pager->total > 0): ?>
                    <span class="badge rounded-pill bg-label-secondary">
                      <?= $pager->total ?> <?= $query !== '' ? 'result' : 'product' ?><?= $pager->total === 1 ? '' : 's' ?>
                    </span>
<?php endif; ?>
                  </div>
                  <p class="text-body-secondary mb-0">
                    Explore the EcoWealth range and open any product for the full details.
                  </p>
                </div>
