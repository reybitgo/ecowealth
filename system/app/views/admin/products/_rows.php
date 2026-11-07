<?php
declare(strict_types=1);

/**
 * Admin product table body region.
 *
 * The initial page and the live feed render this same partial. Each row
 * carries data-live-key so the client can patch only the rows that
 * changed; the forms carry data-live-action so the shared client can
 * submit them with fetch (and fall back to a normal POST without JS).
 *
 * @var list<array<string, mixed>> $products
 * @var array<int, array{orders:int, carts:int, packages:int}> $references
 * @var array<int, int> $available
 * @var array{q?: string, status?: string} $filters
 */

$query      = trim((string) ($filters['q'] ?? ''));
$status     = (string) ($filters['status'] ?? '');
$hasFilters = $query !== '' || $status !== '';
?>
                      <tbody data-live-region="product-rows">
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
                        <tr data-live-key="<?= $id ?>">
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
                                <a
                                  href="<?= e(url('admin/products/' . $id . '/edit')) ?>"
                                  class="text-body fw-medium"
                                  data-live-focus="edit-name"
                                >
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
                                data-live-focus="edit"
                              ><i class="icon-base bx bx-edit"></i></a>

                              <form
                                method="POST"
                                action="<?= e(url('admin/products/' . $id . '/status')) ?>"
                                class="d-inline"
                                data-live-action="status"
                              >
                                <?= csrf_field() ?>
                                <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>" />
                                <button
                                  type="submit"
                                  class="btn btn-sm btn-icon btn-label-<?= $isActive ? 'warning' : 'success' ?>"
                                  title="<?= $isActive ? 'Disable (hide from shop)' : 'Enable (show in shop)' ?>"
                                  aria-label="<?= $isActive ? 'Disable' : 'Enable' ?> <?= e((string) $product['name']) ?>"
                                  data-live-focus="status"
                                ><i class="icon-base bx <?= $isActive ? 'bx-pause-circle' : 'bx-play-circle' ?>"></i></button>
                              </form>

                              <form
                                method="POST"
                                action="<?= e(url('admin/products/' . $id . '/delete')) ?>"
                                class="d-inline"
                                data-live-action="delete"
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
                                  data-live-focus="delete"
                                ><i class="icon-base bx bx-trash"></i></button>
                              </form>
                            </div>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
