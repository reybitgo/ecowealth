<?php
declare(strict_types=1);

/**
 * Admin product list (plan §7 Phase 2; reference views/admin/shop_products.php).
 *
 * The stats, rows and pagination are separate partials because the live
 * feed renders the exact same fragments. The filter form is deliberately
 * outside those regions: a background refresh must never steal focus or
 * discard an unsubmitted search.
 *
 * @var list<array<string, mixed>> $products
 * @var Paginator                  $pager
 * @var array{q?: string, status?: string} $filters
 * @var array{total:int, active:int, inactive:int, out_of_stock:int} $stats
 * @var array<int, array{orders:int, carts:int, packages:int}> $references
 * @var array<int, int>            $available
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$query      = trim((string) ($filters['q'] ?? ''));
$status     = (string) ($filters['status'] ?? '');
$hasFilters = $query !== '' || $status !== '';

$live = $live ?? [
    'surface'  => 'admin.products',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Products</h4>
                  <p class="text-body-secondary mb-0">Manage the catalogue members see in the shop.</p>
                </div>
                <a href="<?= e(url('admin/products/new')) ?>" class="btn btn-primary">
                  <i class="icon-base bx bx-plus me-1"></i> New product
                </a>
              </div>

<?= View::capture('admin/products/_stats', ['stats' => $stats]) ?>

              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div class="d-flex align-items-center gap-3">
                    <h5 class="mb-0">Catalogue</h5>
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
                  <form method="GET" action="<?= e(url('admin/products')) ?>" class="catalog-filter d-flex flex-wrap gap-2">
                    <?= per_page_hidden() ?>
                    <input
                      type="search"
                      name="q"
                      class="form-control"
                      value="<?= e($query) ?>"
                      placeholder="Search name or SKU…"
                      aria-label="Search products"
                    />
                    <select name="status" class="form-select" aria-label="Filter by status">
                      <option value="">All statuses</option>
                      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($hasFilters): ?>
                    <a href="<?= e(url('admin/products')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Product</th>
                        <th scope="col" class="text-end">Price</th>
                        <th scope="col" class="text-end">Stock</th>
                        <th scope="col">Status</th>
                        <th scope="col">Updated</th>
                        <th scope="col" class="text-end">Actions</th>
                      </tr>
                    </thead>
<?= View::capture('admin/products/_rows', [
    'products'   => $products,
    'references' => $references,
    'available'  => $available,
    'filters'    => $filters,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/products/_pagination', ['pager' => $pager, 'pager_label' => 'Products']) ?>
              </div>
            </div>
