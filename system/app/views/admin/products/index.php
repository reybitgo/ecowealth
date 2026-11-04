<?php
declare(strict_types=1);

/**
 * Admin product list (plan §7 Phase 2; reference views/admin/shop_products.php).
 *
 * @var list<array<string, mixed>> $products
 * @var Paginator                  $pager
 * @var array{q?: string, status?: string} $filters
 * @var array{total:int, active:int, inactive:int, out_of_stock:int} $stats
 * @var array<int, array{orders:int, carts:int}> $references
 * @var array<int, int>            $available
 */

$query  = trim((string) ($filters['q'] ?? ''));
$status = (string) ($filters['status'] ?? '');
$hasFilters = $query !== '' || $status !== '';

$cards = [
    ['label' => 'Products', 'value' => $stats['total'], 'icon' => 'bx-package', 'tone' => 'primary'],
    ['label' => 'Active', 'value' => $stats['active'], 'icon' => 'bx-check-circle', 'tone' => 'success'],
    ['label' => 'Inactive', 'value' => $stats['inactive'], 'icon' => 'bx-pause-circle', 'tone' => 'secondary'],
    ['label' => 'Out of stock', 'value' => $stats['out_of_stock'], 'icon' => 'bx-error-circle', 'tone' => 'warning'],
];
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Products</h4>
                  <p class="text-body-secondary mb-0">Manage the catalogue members see in the shop.</p>
                </div>
                <a href="<?= e(url('admin/products/new')) ?>" class="btn btn-primary">
                  <i class="icon-base bx bx-plus me-1"></i> New product
                </a>
              </div>

              <div class="row g-6 mb-6">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1"><?= e($card['label']) ?></p>
                      <h4 class="mb-0"><?= (int) $card['value'] ?></h4>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <h5 class="mb-0">Catalogue</h5>
                  <form method="GET" action="<?= e(url('admin/products')) ?>" class="catalog-filter d-flex flex-wrap gap-2">
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
                    <tbody>
<?php if ($products === []): ?>
                      <tr>
                        <td colspan="6" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-package',
        'title'        => $hasFilters ? 'No products match these filters' : 'No products yet',
        'text'         => $hasFilters
            ? 'Try a different search or clear the filters to see the whole catalogue.'
            : 'Add the first product to open the catalogue to members.',
        'action_href'  => $hasFilters ? 'admin/products' : 'admin/products/new',
        'action_label' => $hasFilters ? 'Clear filters' : 'New product',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($products as $product): ?>
<?php
    $id        = (int) $product['id'];
    $refs      = $references[$id] ?? ['orders' => 0, 'carts' => 0, 'packages' => 0];
    $blockers  = [];

    if (($refs['orders'] ?? 0) > 0) {
        $blockers[] = 'order history';
    }
    if (($refs['carts'] ?? 0) > 0) {
        $blockers[] = 'a member’s cart';
    }
    if (($refs['packages'] ?? 0) > 0) {
        $blockers[] = 'a package';
    }

    $blocked   = $blockers !== [];
    $blockedBy = $blocked
        ? 'This product appears in ' . implode(' and ', $blockers) . '.'
        : '';
    $availableUnits = (int) ($available[$id] ?? 0);
    $thumb          = UploadService::url($product['image_url'] ?? null);
    $isActive       = $product['status'] === 'active';
?>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center gap-3">
<?php if ($thumb !== null): ?>
                            <img src="<?= e($thumb) ?>" alt="" class="admin-product-thumb" loading="lazy" />
<?php else: ?>
                            <span class="admin-product-thumb admin-product-thumb-empty" aria-hidden="true">
                              <img src="<?= e(url('assets/img/ecowealth-mark.png')) ?>" alt="" loading="lazy" />
                            </span>
<?php endif; ?>
                            <div>
                              <a href="<?= e(url('admin/products/' . $id . '/edit')) ?>" class="text-body fw-medium">
                                <?= e((string) $product['name']) ?>
                              </a>
                              <div class="small text-body-secondary">
                                <?= e(trim((string) ($product['sku'] ?? '')) !== '' ? (string) $product['sku'] : 'No SKU') ?>
                              </div>
                            </div>
                          </div>
                        </td>
                        <td class="text-end text-nowrap"><?= e(money((float) $product['price'])) ?></td>
                        <td class="text-end text-nowrap">
                          <div><?= (int) $product['stock'] ?></div>
                          <div class="small text-body-secondary"><?= $availableUnits ?> available</div>
                        </td>
                        <td><?= status_badge((string) $product['status']) ?></td>
                        <td class="text-nowrap small text-body-secondary"><?= e(format_datetime((string) $product['updated_at'])) ?></td>
                        <td class="text-end">
                          <div class="d-inline-flex align-items-center gap-1">
                            <a
                              href="<?= e(url('admin/products/' . $id . '/edit')) ?>"
                              class="btn btn-sm btn-icon btn-label-primary"
                              title="Edit"
                              aria-label="Edit <?= e((string) $product['name']) ?>"
                            ><i class="icon-base bx bx-edit"></i></a>

                            <form
                              method="POST"
                              action="<?= e(url('admin/products/' . $id . '/status')) ?>"
                              class="d-inline"
                            >
                              <?= csrf_field() ?>
                              <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>" />
                              <button
                                type="submit"
                                class="btn btn-sm btn-icon btn-label-<?= $isActive ? 'warning' : 'success' ?>"
                                title="<?= $isActive ? 'Disable (hide from shop)' : 'Enable (show in shop)' ?>"
                                aria-label="<?= $isActive ? 'Disable' : 'Enable' ?> <?= e((string) $product['name']) ?>"
                              ><i class="icon-base bx <?= $isActive ? 'bx-pause-circle' : 'bx-play-circle' ?>"></i></button>
                            </form>

                            <form
                              method="POST"
                              action="<?= e(url('admin/products/' . $id . '/delete')) ?>"
                              class="d-inline js-delete-product"
                              data-name="<?= e((string) $product['name']) ?>"
                              data-blocked="<?= $blocked ? '1' : '0' ?>"
                              data-blocked-reason="<?= e($blockedBy) ?>"
                            >
                              <?= csrf_field() ?>
                              <button
                                type="submit"
                                class="btn btn-sm btn-icon btn-label-danger"
                                title="<?= $blocked ? e($blockedBy) : 'Delete' ?>"
                                aria-label="Delete <?= e((string) $product['name']) ?>"
                              ><i class="icon-base bx bx-trash"></i></button>
                            </form>
                          </div>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Products']) ?>
                </div>
              </div>
            </div>

            <script>
              document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.js-delete-product').forEach(function (form) {
                  form.addEventListener('submit', function (event) {
                    if (form.dataset.blocked === '1') {
                      event.preventDefault();
                      var message = form.dataset.blockedReason || 'This product cannot be deleted.';
                      if (window.Swal) {
                        window.Swal.fire({ icon: 'warning', title: 'Cannot delete', text: message });
                      } else {
                        window.alert(message);
                      }
                      return;
                    }

                    if (window.Swal) {
                      event.preventDefault();
                      window.Swal.fire({
                        icon: 'warning',
                        title: 'Delete product?',
                        html: 'Delete <strong>' + form.dataset.name.replace(/[&<>"']/g, function (c) {
                          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                        }) + '</strong> permanently?',
                        showCancelButton: true,
                        confirmButtonText: 'Delete',
                        confirmButtonColor: '#d33',
                        cancelButtonText: 'Cancel'
                      }).then(function (result) {
                        if (result.isConfirmed) form.submit();
                      });
                    }
                  });
                });
              });
            </script>
