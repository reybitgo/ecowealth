<?php
declare(strict_types=1);

/**
 * Admin dashboard — operations overview (plan §7, Phase 3+).
 *
 * Live data only: accounts, catalogue, inventory and packages. The screen
 * follows the Sneat dashboard language (stat cards, chart + donut row,
 * recent-accounts table) so the back office matches the reference design.
 *
 * @var array<string, int|float> $stats
 * @var list<array<string, mixed>> $products
 * @var list<array<string, mixed>> $packages
 * @var list<array<string, mixed>> $recent
 * @var array{labels: list<string>, values: list<float>, stock: list<int>} $chart
 * @var array{healthy: int, low: int, out: int} $stock
 * @var list<array<string, mixed>> $attention
 */

$me          = $me ?? auth_user();
$displayName = trim((string) ($me['full_name'] ?? '')) ?: (string) ($me['username'] ?? 'Admin');

$stats = array_merge([
    'accounts'        => 0,
    'accounts_active' => 0,
    'members'         => 0,
    'admins'          => 0,
    'new_accounts'    => 0,
    'new_prev'        => 0,
    'products'        => 0,
    'products_active' => 0,
    'low_stock'       => 0,
    'out_of_stock'    => 0,
    'stock_units'     => 0,
    'inventory_value' => 0.0,
    'packages'        => 0,
    'packages_active' => 0,
    'package_value'   => 0.0,
], $stats ?? []);

$products  = $products ?? [];
$packages  = $packages ?? [];
$recent    = $recent ?? [];
$attention = $attention ?? [];
$chart     = array_merge(['labels' => [], 'values' => [], 'stock' => []], $chart ?? []);
$stock     = array_merge(['healthy' => 0, 'low' => 0, 'out' => 0], $stock ?? []);

/* ---- KPI cards ------------------------------------------------------- */
$newAccounts = (int) $stats['new_accounts'];

$stockAlerts = (int) $stats['low_stock'] + (int) $stats['out_of_stock'];

$cards = [
    [
        'label'      => 'Total accounts',
        'value'      => number_format((int) $stats['accounts']),
        'icon'       => 'bx-group',
        'tone'       => 'primary',
        'hint'       => (int) $stats['accounts_active'] . ' active account' . ((int) $stats['accounts_active'] === 1 ? '' : 's'),
        'badge'      => $newAccounts > 0 ? '+' . $newAccounts . ' in 30 days' : 'No new accounts',
        'badge_tone' => $newAccounts > 0 ? 'success' : 'secondary',
        'badge_icon' => $newAccounts > 0 ? 'bx-up-arrow-alt' : 'bx-minus',
    ],
    [
        'label'      => 'Products',
        'value'      => number_format((int) $stats['products']),
        'icon'       => 'bx-package',
        'tone'       => 'info',
        'hint'       => (int) $stats['products_active'] . ' active in the catalogue',
        'badge'      => $stockAlerts > 0 ? $stockAlerts . ' stock alert' . ($stockAlerts === 1 ? '' : 's') : 'Stock healthy',
        'badge_tone' => $stockAlerts > 0 ? 'warning' : 'success',
        'badge_icon' => $stockAlerts > 0 ? 'bx-error-circle' : 'bx-check-circle',
    ],
    [
        'label'      => 'Inventory value',
        'value'      => money((float) $stats['inventory_value']),
        'icon'       => 'bx-wallet',
        'tone'       => 'success',
        'hint'       => number_format((int) $stats['stock_units']) . ' units on hand',
        'badge'      => 'Retail value',
        'badge_tone' => 'primary',
        'badge_icon' => 'bx-trending-up',
    ],
    [
        'label'      => 'Packages',
        'value'      => number_format((int) $stats['packages']),
        'icon'       => 'bx-box',
        'tone'       => 'warning',
        'hint'       => money((float) $stats['package_value']) . ' combined entry value',
        'badge'      => (int) $stats['packages_active'] . ' active',
        'badge_tone' => (int) $stats['packages_active'] > 0 ? 'success' : 'secondary',
        'badge_icon' => 'bx-check-circle',
    ],
];

/* ---- Page-specific helpers ------------------------------------------ */
$roleTone = static fn (string $role): string => match ($role) {
    'superadmin' => 'info',
    'admin'      => 'primary',
    default      => 'secondary',
};

$stockRows = [
    ['key' => 'healthy', 'label' => 'Healthy',     'tone' => 'success', 'hint' => '30+ units',  'count' => (int) $stock['healthy']],
    ['key' => 'low',     'label' => 'Low stock',   'tone' => 'warning', 'hint' => '1–29 units', 'count' => (int) $stock['low']],
    ['key' => 'out',     'label' => 'Out of stock', 'tone' => 'danger',  'hint' => '0 units',    'count' => (int) $stock['out']],
];

$payload = json_encode([
    'catalogue' => [
        'labels' => array_values($chart['labels']),
        'values' => array_values(array_map('floatval', $chart['values'])),
    ],
    'stock' => [
        'labels' => ['Healthy', 'Low stock', 'Out of stock'],
        'values' => [(int) $stock['healthy'], (int) $stock['low'], (int) $stock['out']],
    ],
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';

$topProduct = (string) ($chart['labels'][0] ?? '');
$topValue   = (float) ($chart['values'][0] ?? 0);
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <!-- Welcome -->
              <div class="card welcome-card mb-6">
                <div class="card-body p-5 p-lg-7">
                  <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-5">
                    <div>
                      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="badge rounded-pill bg-label-primary">Back office</span>
                        <span class="badge rounded-pill bg-label-<?= e($roleTone((string) ($me['role'] ?? 'admin'))) ?>">
                          <?= e(ucfirst((string) ($me['role'] ?? 'admin'))) ?>
                        </span>
                        <span class="text-body-secondary small">
                          <i class="icon-base bx bx-calendar me-1"></i><?= e(date('D, M j, Y')) ?>
                        </span>
                      </div>
                      <h4 class="mb-2">Welcome back, <?= e($displayName) ?> &#x1f44b;</h4>
                      <p class="text-body-secondary mb-0">
                        Here is the latest picture of your catalogue, stock and team.
                      </p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <a href="<?= e(url('admin/products/new')) ?>" class="btn btn-primary">
                        <i class="icon-base bx bx-plus me-1"></i> New product
                      </a>
                      <a href="<?= e(url('admin/packages/new')) ?>" class="btn btn-label-primary">
                        <i class="icon-base bx bx-box me-1"></i> New package
                      </a>
                      <a
                        href="<?= e(url('shop')) ?>"
                        class="btn btn-label-secondary"
                        target="_blank"
                        rel="noopener"
                      >
                        <i class="icon-base bx bx-store-alt me-1"></i> Storefront
                      </a>
                    </div>
                  </div>
                </div>
              </div>

              <!-- KPI cards -->
              <div class="row g-6 mb-6">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100 stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-4">
                        <span class="avatar avatar-md">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?> icon-md"></i>
                          </span>
                        </span>
                        <span class="badge rounded-pill bg-label-<?= e($card['badge_tone']) ?>">
                          <i class="icon-base <?= e(icon_class($card['badge_icon'])) ?> me-1"></i><?= e($card['badge']) ?>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1"><?= e($card['label']) ?></p>
                      <h4 class="mb-2 text-heading stat-value"><?= e($card['value']) ?></h4>
                      <small class="text-body-secondary"><?= e($card['hint']) ?></small>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <!-- Charts -->
              <div class="row g-6 mb-6">
                <div class="col-xl-8">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                      <div>
                        <h5 class="card-title mb-1">Catalogue value</h5>
                        <p class="text-body-secondary small mb-0">Retail value of the stock currently on hand.</p>
                      </div>
                      <span class="badge rounded-pill bg-label-primary">
                        <?= e(money((float) $stats['inventory_value'])) ?>
                      </span>
                    </div>
                    <div class="card-body pt-2">
                      <div id="catalogueValueChart" class="dashboard-chart"></div>

                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 border-top pt-4 mt-2">
                        <div class="d-flex align-items-center gap-3">
                          <span class="avatar avatar-sm">
                            <span class="avatar-initial rounded bg-label-primary">
                              <i class="icon-base bx bx-cube"></i>
                            </span>
                          </span>
                          <div>
                            <small class="text-body-secondary d-block">Units on hand</small>
                            <span class="fw-semibold"><?= number_format((int) $stats['stock_units']) ?></span>
                          </div>
                        </div>
                        <div class="text-end">
                          <small class="text-body-secondary d-block">Highest stock value</small>
                          <span class="fw-semibold">
                            <?= e($topProduct !== '' ? $topProduct : '—') ?> &middot; <?= e(money($topValue)) ?>
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-xl-4">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-1">Stock health</h5>
                      <p class="text-body-secondary small mb-0"><?= (int) $stats['products'] ?> products in the catalogue.</p>
                    </div>
                    <div class="card-body pt-2">
                      <div id="stockHealthChart" class="dashboard-chart-sm mx-auto" style="max-width: 15rem"></div>

                      <ul class="list-unstyled mb-0 mt-4">
<?php foreach ($stockRows as $row): ?>
                        <li class="d-flex align-items-center justify-content-between py-2">
                          <span class="d-flex align-items-center gap-2">
                            <span
                              class="rounded-circle d-inline-block"
                              style="width: .5rem; height: .5rem; background-color: var(--bs-<?= e($row['tone']) ?>)"
                            ></span>
                            <span class="fw-medium"><?= e($row['label']) ?></span>
                            <small class="text-body-secondary"><?= e($row['hint']) ?></small>
                          </span>
                          <span class="fw-semibold"><?= (int) $row['count'] ?></span>
                        </li>
<?php endforeach; ?>
                      </ul>

<?php if ($attention !== []): ?>
                      <hr class="my-4" />
                      <h6 class="mb-3">Needs attention</h6>
                      <div class="d-flex flex-column gap-1">
<?php foreach ($attention as $item): ?>
<?php $units = (int) ($item['stock'] ?? 0); ?>
                        <a
                          href="<?= e(url('admin/products/' . (int) $item['id'] . '/edit')) ?>"
                          class="d-flex align-items-center gap-2 py-1 text-reset text-decoration-none"
                        >
                          <span class="badge rounded-pill bg-label-<?= $units === 0 ? 'danger' : 'warning' ?>">
                            <i class="icon-base <?= $units === 0 ? 'bx bx-x-circle' : 'bx bx-error' ?>"></i>
                          </span>
                          <span class="flex-grow-1 small text-truncate"><?= e((string) $item['name']) ?></span>
                          <small class="text-body-secondary text-nowrap"><?= $units ?> left</small>
                        </a>
<?php endforeach; ?>
                      </div>
<?php else: ?>
                      <div class="alert alert-success d-flex align-items-center gap-2 mb-0 mt-4 py-2" role="status">
                        <i class="icon-base bx bx-check-circle"></i>
                        <span class="small">Every product is well stocked.</span>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Accounts + packages -->
              <div class="row g-6">
                <div class="col-xl-8">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="card-title mb-1">Recent accounts</h5>
                        <p class="text-body-secondary small mb-0">The newest sign-ups across the platform.</p>
                      </div>
                      <span class="badge rounded-pill bg-label-secondary"><?= count($recent) ?> shown</span>
                    </div>
                    <div class="table-responsive">
                      <table class="table dashboard-table mb-0">
                        <thead>
                          <tr>
                            <th>Account</th>
                            <th class="d-none d-md-table-cell">Role</th>
                            <th>Status</th>
                            <th class="d-none d-lg-table-cell">Joined</th>
                            <th class="d-none d-lg-table-cell text-end text-nowrap">Last sign-in</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($recent === []): ?>
                          <tr>
                            <td colspan="5" class="text-center text-body-secondary py-6">No accounts yet.</td>
                          </tr>
<?php endif; ?>
<?php foreach ($recent as $row): ?>
<?php
    $role = (string) ($row['role'] ?? 'member');
    $fullName = trim((string) ($row['full_name'] ?? ''));
?>
                          <tr>
                            <td>
                              <div class="d-flex align-items-center gap-3">
                                <span class="avatar avatar-sm flex-shrink-0">
                                  <span class="avatar-initial rounded-circle bg-label-<?= e($roleTone($role)) ?> fw-semibold">
                                    <?= e(initials((string) ($row['username'] ?? 'U'))) ?>
                                  </span>
                                </span>
                                <div class="min-w-0">
                                  <div class="fw-medium text-truncate"><?= e((string) ($row['username'] ?? '')) ?></div>
                                  <small class="text-body-secondary d-block text-truncate">
                                    <?= e($fullName !== '' ? $fullName : (string) ($row['email'] ?? '')) ?>
                                  </small>
                                </div>
                              </div>
                            </td>
                            <td class="d-none d-md-table-cell"><span class="badge bg-label-<?= e($roleTone($role)) ?>"><?= e(ucfirst($role)) ?></span></td>
                            <td><?= status_badge((int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'inactive') ?></td>
                            <td class="d-none d-lg-table-cell text-body-secondary text-nowrap"><?= e(date('M j, Y', strtotime((string) ($row['created_at'] ?? 'now')) ?: time())) ?></td>
                            <td class="d-none d-lg-table-cell text-body-secondary text-nowrap text-end"><?= e(time_ago((string) ($row['last_login_at'] ?? ''))) ?></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <div class="col-xl-4">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="card-title mb-1">Packages</h5>
                        <p class="text-body-secondary small mb-0">Entry packages members can activate.</p>
                      </div>
                      <a href="<?= e(url('admin/packages')) ?>" class="btn btn-sm btn-label-primary">Manage</a>
                    </div>
                    <div class="card-body pt-2">
<?php if ($packages === []): ?>
                      <div class="text-center text-body-secondary py-6">
                        <i class="icon-base bx bx-box icon-lg d-block mb-2"></i>
                        <p class="mb-3">No packages yet.</p>
                        <a href="<?= e(url('admin/packages/new')) ?>" class="btn btn-sm btn-primary">Create the first package</a>
                      </div>
<?php else: ?>
<?php $lastPackage = count($packages) - 1; ?>
<?php foreach ($packages as $index => $package): ?>
                      <div class="d-flex align-items-center gap-3 py-3 <?= $index === $lastPackage ? '' : 'border-bottom' ?>">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded bg-label-primary">
                            <i class="icon-base bx bx-box"></i>
                          </span>
                        </span>
                        <div class="flex-grow-1 min-w-0">
                          <h6 class="mb-0 text-truncate"><?= e((string) $package['name']) ?></h6>
                          <small class="text-body-secondary">
                            Direct referral <?= e(money((float) ($package['direct_referral_bonus'] ?? 0))) ?>
                          </small>
                        </div>
                        <div class="text-end flex-shrink-0">
                          <div class="fw-semibold"><?= e(money((float) ($package['price'] ?? 0))) ?></div>
                          <?= status_badge((string) ($package['status'] ?? 'inactive')) ?>
                        </div>
                        <a
                          href="<?= e(url('admin/packages/' . (int) $package['id'] . '/edit')) ?>"
                          class="btn btn-icon btn-sm btn-label-secondary flex-shrink-0"
                          aria-label="Edit <?= e((string) $package['name']) ?>"
                        >
                          <i class="icon-base bx bx-edit"></i>
                        </a>
                      </div>
<?php endforeach; ?>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <script>
              window.ECOWEALTH_ADMIN = <?= $payload ?>;
            </script>
