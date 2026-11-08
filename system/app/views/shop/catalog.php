<?php
declare(strict_types=1);

/**
 * Storefront catalogue (plan §7 Phase 2; reference views/member/shop.php).
 *
 * One live surface. The heading's result count, the closed/empty/grid states
 * and the quick-view payload are regions; the search form stays outside them,
 * so a background refresh never steals focus or discards a typed search.
 *
 * The quick-view modal itself is static: Bootstrap binds to that element
 * once, while the delegated handler reads the region's JSON on every open.
 *
 * @var list<array<string, mixed>> $products
 * @var array<int, int>            $available  product id => available units
 * @var Paginator                  $pager
 * @var array{q?: string}          $filters
 * @var bool                       $shop_open
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$shop_open = $shop_open ?? true;
$query     = trim((string) ($filters['q'] ?? ''));

$live = $live ?? [
    'surface'  => 'shop.catalog',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y shop-catalog"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
<?= View::capture('shop/catalog/_heading', ['pager' => $pager, 'filters' => $filters]) ?>

                <div class="d-flex flex-column align-items-end gap-1">
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
                  <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                </div>
              </div>

<?= View::capture('shop/catalog/_results', [
    'products'  => $products,
    'available' => $available,
    'pager'     => $pager,
    'filters'   => $filters,
    'shop_open' => $shop_open,
]) ?>

<?= View::capture('shop/catalog/_quickview', [
    'products'  => $products,
    'available' => $available,
]) ?>
            </div>

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
            <script>
              document.addEventListener('DOMContentLoaded', function () {
                var modalEl = document.getElementById('productQuickView');
                if (!modalEl) return;

                var modal = null;

                /* Delegated: a live refresh may replace every card, and the
                 * quick-view JSON region is re-read on every open. */
                document.addEventListener('click', function (event) {
                  var button = event.target && event.target.closest
                    ? event.target.closest('[data-quick-view]')
                    : null;

                  if (!button) return;

                  var dataEl = document.getElementById('productQuickViewData');
                  if (!dataEl) return;

                  var data;
                  try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }

                  var item = data[button.getAttribute('data-quick-view')];
                  if (!item) return;

                  var media = document.getElementById('quickViewMedia');
                  var image = document.getElementById('quickViewImage');
                  var details = document.getElementById('quickViewDetails');

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

                  if (!modal) {
                    modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                  }

                  modal.show();
                });
              });
            </script>
