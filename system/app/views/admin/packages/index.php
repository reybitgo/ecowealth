<?php
declare(strict_types=1);

/**
 * Admin package list (plan §7 Phase 3; reference views/admin/packages.php).
 *
 * @var list<array<string, mixed>> $packages
 * @var Paginator                  $pager
 * @var array{q?: string, status?: string} $filters
 * @var array{total:int, active:int, inactive:int} $stats
 * @var array<int, array<int, float>> $levels    package id => level 2–10 => amount
 * @var array<int, list<array<string, mixed>>> $contents
 */

$query      = trim((string) ($filters['q'] ?? ''));
$status     = (string) ($filters['status'] ?? '');
$hasFilters = $query !== '' || $status !== '';

$cards = [
    ['label' => 'Packages', 'value' => $stats['total'], 'icon' => 'bx-box', 'tone' => 'primary'],
    ['label' => 'Active', 'value' => $stats['active'], 'icon' => 'bx-check-circle', 'tone' => 'success'],
    ['label' => 'Inactive', 'value' => $stats['inactive'], 'icon' => 'bx-pause-circle', 'tone' => 'secondary'],
];
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Packages</h4>
                  <p class="text-body-secondary mb-0">
                    Associate packages, their referral ladder and their contents.
                  </p>
                </div>
                <a href="<?= e(url('admin/packages/new')) ?>" class="btn btn-primary">
                  <i class="icon-base bx bx-plus me-1"></i> New package
                </a>
              </div>

              <div class="row g-6 mb-6">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="mb-3">
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
                  <h5 class="mb-0">Associate packages</h5>
                  <form method="GET" action="<?= e(url('admin/packages')) ?>" class="catalog-filter d-flex flex-wrap gap-2">
                    <input
                      type="search"
                      name="q"
                      class="form-control"
                      value="<?= e($query) ?>"
                      placeholder="Search name or slug…"
                      aria-label="Search packages"
                    />
                    <select name="status" class="form-select" aria-label="Filter by status">
                      <option value="">All statuses</option>
                      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($hasFilters): ?>
                    <a href="<?= e(url('admin/packages')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Package</th>
                        <th scope="col" class="text-end">Price</th>
                        <th scope="col" class="text-end">Product value</th>
                        <th scope="col" class="text-end">Level 1 (direct)</th>
                        <th scope="col" class="text-end">Levels 2–10</th>
                        <th scope="col" class="text-end">Contents</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
<?php if ($packages === []): ?>
                      <tr>
                        <td colspan="8" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-box',
        'title'        => $hasFilters ? 'No packages match these filters' : 'No packages yet',
        'text'         => $hasFilters
            ? 'Try a different search or clear the filters to see every package.'
            : 'Create the first associate package to open membership offers.',
        'action_href'  => $hasFilters ? 'admin/packages' : 'admin/packages/new',
        'action_label' => $hasFilters ? 'Clear filters' : 'New package',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($packages as $package): ?>
<?php
    $id          = (int) $package['id'];
    $levelRows   = $levels[$id] ?? [];
    $ladderTotal = array_sum($levelRows);
    $contentRows = $contents[$id] ?? [];
    $isActive    = $package['status'] === 'active';
?>
                      <tr>
                        <td>
                          <a href="<?= e(url('admin/packages/' . $id . '/edit')) ?>" class="text-body fw-medium">
                            <?= e((string) $package['name']) ?>
                          </a>
                          <div class="small text-body-secondary"><?= e((string) $package['slug']) ?></div>
                        </td>
                        <td class="text-end text-nowrap"><?= e(money((float) $package['price'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $package['product_value'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(money((float) $package['direct_referral_bonus'])) ?></td>
                        <td class="text-end text-nowrap">
                          <div><?= e(money($ladderTotal)) ?></div>
                          <div class="small text-body-secondary"><?= (int) count(array_filter($levelRows, static fn (float $v): bool => $v > 0)) ?> paying levels</div>
                        </td>
                        <td class="text-end text-nowrap"><?= (int) count($contentRows) ?></td>
                        <td><?= status_badge((string) $package['status']) ?></td>
                        <td class="text-end">
                          <div class="d-inline-flex align-items-center gap-1">
                            <a
                              href="<?= e(url('admin/packages/' . $id . '/edit')) ?>"
                              class="btn btn-sm btn-icon btn-label-primary"
                              title="Edit"
                              aria-label="Edit <?= e((string) $package['name']) ?>"
                            ><i class="icon-base bx bx-edit"></i></a>

                            <form method="POST" action="<?= e(url('admin/packages/' . $id . '/status')) ?>" class="d-inline">
                              <?= csrf_field() ?>
                              <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>" />
                              <button
                                type="submit"
                                class="btn btn-sm btn-icon btn-label-<?= $isActive ? 'warning' : 'success' ?>"
                                title="<?= $isActive ? 'Disable (hide from members)' : 'Enable (show to members)' ?>"
                                aria-label="<?= $isActive ? 'Disable' : 'Enable' ?> <?= e((string) $package['name']) ?>"
                              ><i class="icon-base bx <?= $isActive ? 'bx-pause-circle' : 'bx-play-circle' ?>"></i></button>
                            </form>
                          </div>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Packages']) ?>
                </div>
              </div>
            </div>
