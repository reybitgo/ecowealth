<?php
declare(strict_types=1);

/**
 * Storefront catalogue — the closed/empty/grid result states.
 *
 * One region covers all three: a product becoming sold out, inactive or
 * newly added can move the page between them without the client needing a
 * region that does not exist yet.
 *
 * @var list<array<string, mixed>> $products
 * @var array<int, int>            $available  product id => available units
 * @var Paginator                  $pager
 * @var array{q?: string}          $filters
 * @var bool                       $shop_open
 * @var array<int, float>          $bonus_totals product id => repeat-purchase ladder total
 */

$query = trim((string) ($filters['q'] ?? ''));
$bonusTotals = $bonus_totals ?? [];
?>
              <div data-live-region="shop-catalog-results">
<?php if (!$shop_open): ?>
                <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                  <i class="icon-base bx bx-store-alt fs-4"></i>
                  <span>The shop is currently closed. Please check back soon.</span>
                </div>
<?php elseif ($products === []): ?>
                <div class="card">
                  <div class="card-body">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-store',
        'title'        => $query !== '' ? 'No products match your search' : 'No products yet',
        'text'         => $query !== ''
            ? 'Try a different word, or clear the search to see the whole catalogue.'
            : 'The catalogue is being prepared. Please check back soon.',
        'action_href'  => $query !== '' ? 'shop' : '',
        'action_label' => $query !== '' ? 'Clear search' : '',
    ]);
?>
                  </div>
                </div>
<?php else: ?>
                <div class="row g-3 g-md-6 shop-product-grid">
<?php foreach ($products as $product): ?>
                  <div class="col-6 col-md-4 col-xl-3">
<?php
    echo View::partial('product_card', [
        'product'    => $product,
        'available'  => (int) ($available[(int) $product['id']] ?? 0),
        'quick_view' => true,
        'bonus_total' => (float) ($bonusTotals[(int) $product['id']] ?? 0),
    ]);
?>
                  </div>
<?php endforeach; ?>
                </div>

<?php if ($pager->pages > 1): ?>
                <div class="card mt-6">
                  <div class="card-body py-3">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Products', 'show_per_page' => false]) ?>
                  </div>
                </div>
<?php endif; ?>
<?php endif; ?>
              </div>
