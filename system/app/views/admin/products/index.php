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
 * @var array{q?: string, status?: string, unilevel?: string} $filters
 * @var array{total:int, active:int, inactive:int, out_of_stock:int} $stats
 * @var array<int, array{orders:int, carts:int, packages:int, awards:int}> $references
 * @var array<int, int>            $available
 * @var array<int, float>          $repeat_totals ladder total per product
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$query        = trim((string) ($filters['q'] ?? ''));
$status       = (string) ($filters['status'] ?? '');
$unilevel     = (string) ($filters['unilevel'] ?? '');
$binary       = (string) ($filters['binary'] ?? '');
$repeatTotals = $repeat_totals ?? [];
$binaryStatus = $binary_status ?? [];
$hasFilters   = $query !== '' || $status !== '' || $unilevel !== '' || $binary !== '';

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
<?= View::partial('page_header', [
    'title'       => 'Products',
    'description' => 'Manage the catalogue members see in the shop.',
    'align'       => 'center',
    'actions'     => '<a href="' . e(url('admin/products/new')) . '" class="btn btn-primary">'
        . '<i class="icon-base bx bx-plus me-1"></i> New product</a>',
]) ?>

<?= View::capture('admin/products/_stats', ['stats' => $stats]) ?>

              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div class="d-flex align-items-center gap-3">
                    <h5 class="mb-0">Catalogue</h5>
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
                  <form method="GET" action="<?= e(url('admin/products')) ?>" class="catalog-filter d-flex flex-wrap align-items-end gap-2">
                    <?= per_page_hidden() ?>
                    <div class="filter-field filter-field-wide">
                      <label class="form-label" for="product-filter-q">Search</label>
                      <input
                        type="search"
                        id="product-filter-q"
                        name="q"
                        class="form-control"
                        value="<?= e($query) ?>"
                        placeholder="Name or SKU…"
                      />
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="product-filter-status">Status</label>
                      <select id="product-filter-status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                      </select>
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="product-filter-unilevel">Repeat purchase</label>
                      <select id="product-filter-unilevel" name="unilevel" class="form-select">
                        <option value="">All</option>
                        <option value="on" <?= $unilevel === 'on' ? 'selected' : '' ?>>On</option>
                        <option value="off" <?= $unilevel === 'off' ? 'selected' : '' ?>>Off</option>
                      </select>
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="product-filter-binary">Product binary</label>
                      <select id="product-filter-binary" name="binary" class="form-select">
                        <option value="">All</option>
                        <option value="on" <?= $binary === 'on' ? 'selected' : '' ?>>On</option>
                        <option value="off" <?= $binary === 'off' ? 'selected' : '' ?>>Off</option>
                      </select>
                    </div>
                    <div class="filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e(url('admin/products')) ?>" class="btn btn-label-secondary"><i class="icon-base bx bx-x me-1"></i> Clear filters</a>
<?php endif; ?>
                    </div>
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
    'products'      => $products,
    'references'    => $references,
    'available'     => $available,
    'filters'       => $filters,
    'repeat_totals' => $repeatTotals,
    'binary_status' => $binaryStatus,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/products/_pagination', ['pager' => $pager, 'pager_label' => 'Products']) ?>
              </div>
            </div>
