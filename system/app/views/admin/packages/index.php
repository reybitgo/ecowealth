<?php
declare(strict_types=1);

/**
 * Admin package list (plan §7 Phase 3; reference views/admin/packages.php).
 *
 * One live surface (`admin.packages`): the counters, the keyed rows and
 * the pagination are regions; the search/status form stays outside them
 * so a background refresh never discards an unsubmitted filter.
 *
 * @var list<array<string, mixed>> $packages
 * @var Paginator                  $pager
 * @var array{q?: string, status?: string} $filters
 * @var array{total:int, active:int, inactive:int} $stats
 * @var array<int, array<int, float>> $levels    package id => level 2–10 => amount
 * @var array<int, list<array<string, mixed>>> $contents
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$query      = trim((string) ($filters['q'] ?? ''));
$status     = (string) ($filters['status'] ?? '');
$hasFilters = $query !== '' || $status !== '';

$live = $live ?? [
    'surface'  => 'admin.packages',
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
                  <h4 class="mb-1">Packages</h4>
                  <p class="text-body-secondary mb-0">
                    Associate packages, their referral ladder and their contents.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <a href="<?= e(url('admin/packages/new')) ?>" class="btn btn-primary">
                  <i class="icon-base bx bx-plus me-1"></i> New package
                </a>
              </div>

<?= View::capture('admin/packages/_stats', ['stats' => $stats]) ?>

              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <h5 class="mb-0">Associate packages</h5>
                  <form method="GET" action="<?= e(url('admin/packages')) ?>" class="catalog-filter d-flex flex-wrap gap-2">
                    <?= per_page_hidden() ?>
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
<?= View::capture('admin/packages/_rows', [
    'packages' => $packages,
    'filters'  => $filters,
    'levels'   => $levels,
    'contents' => $contents,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/packages/_pagination', ['pager' => $pager]) ?>
              </div>
            </div>
