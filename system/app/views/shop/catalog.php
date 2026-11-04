<?php
declare(strict_types=1);

/**
 * Storefront catalogue (plan §7 Phase 2; reference views/member/shop.php).
 *
 * @var list<array<string, mixed>> $products
 * @var array<int, int>            $available  product id => available units
 * @var Paginator                  $pager
 * @var array{q?: string}          $filters
 * @var bool                       $shop_open
 */

$shop_open = $shop_open ?? true;
$query     = trim((string) ($filters['q'] ?? ''));

/* Quick-view payload: encoded once, read by JS from a JSON script block. */
$quickViewData = [];
foreach ($products as $product) {
    if (empty($product['description'])) {
        continue;
    }

    $quickViewData[(int) $product['id']] = [
        'name'        => (string) $product['name'],
        'description' => (string) $product['description'],
        'price'       => money((float) $product['price']),
        'stock'       => StockService::label((int) ($available[(int) $product['id']] ?? 0))['label'],
        'image'       => UploadService::url($product['image_url'] ?? null),
        'url'         => url('shop/' . (int) $product['id']),
    ];
}
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
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
                <form method="GET" action="<?= e(url('shop')) ?>" class="d-flex gap-2" role="search">
                  <div class="input-group">
                    <span class="input-group-text"><i class="icon-base bx bx-search"></i></span>
                    <input
                      type="search"
                      class="form-control"
                      name="q"
                      value="<?= e($query) ?>"
                      placeholder="Search products…"
                      aria-label="Search products"
                    />
                    <button type="submit" class="btn btn-primary">Search</button>
<?php if ($query !== ''): ?>
                    <a href="<?= e(url('shop')) ?>" class="btn btn-label-secondary" aria-label="Clear search">
                      <i class="icon-base bx bx-x"></i>
                    </a>
<?php endif; ?>
                  </div>
                </form>
              </div>

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
    ]);
?>
                </div>
<?php endforeach; ?>
              </div>

<?php if ($pager->pages > 1): ?>
              <div class="card mt-6">
                <div class="card-body py-3">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Products']) ?>
                </div>
              </div>
<?php endif; ?>
<?php endif; ?>
            </div>

<?php if ($quickViewData !== []): ?>
            <!-- Quick view: the card keeps its detail link, the modal is progressive enhancement. -->
            <div class="modal fade" id="productQuickView" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="quickViewTitle">Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="row g-4">
                      <div class="col-sm-5" id="quickViewMedia" hidden>
                        <div class="quick-view-media">
                          <img id="quickViewImage" src="" alt="" />
                        </div>
                      </div>
                      <div class="col" id="quickViewDetails">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                          <span class="h5 mb-0" id="quickViewPrice"></span>
                          <span class="badge bg-label-secondary" id="quickViewStock"></span>
                        </div>
                        <p class="text-body-secondary mb-0" id="quickViewDescription" style="white-space: pre-line"></p>
                      </div>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="<?= e(url('shop')) ?>" class="btn btn-primary" id="quickViewLink">View details</a>
                  </div>
                </div>
              </div>
            </div>
            <script type="application/json" id="productQuickViewData"><?= json_encode($quickViewData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
            <script>
              document.addEventListener('DOMContentLoaded', function () {
                var dataEl = document.getElementById('productQuickViewData');
                var modalEl = document.getElementById('productQuickView');
                if (!dataEl || !modalEl) return;

                var data;
                try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }

                var modal = new bootstrap.Modal(modalEl);
                var media = document.getElementById('quickViewMedia');
                var image = document.getElementById('quickViewImage');
                var details = document.getElementById('quickViewDetails');

                document.querySelectorAll('[data-quick-view]').forEach(function (button) {
                  button.addEventListener('click', function () {
                    var item = data[button.getAttribute('data-quick-view')];
                    if (!item) return;

                    document.getElementById('quickViewTitle').textContent = item.name;
                    document.getElementById('quickViewPrice').textContent = item.price;
                    document.getElementById('quickViewStock').textContent = item.stock;
                    document.getElementById('quickViewDescription').textContent = item.description;
                    document.getElementById('quickViewLink').setAttribute('href', item.url);

                    if (item.image) {
                      image.src = item.image;
                      image.alt = item.name;
                      media.hidden = false;
                      details.className = 'col-sm-7';
                    } else {
                      image.removeAttribute('src');
                      image.alt = '';
                      media.hidden = true;
                      details.className = 'col';
                    }

                    modal.show();
                  });
                });
              });
            </script>
<?php endif; ?>
