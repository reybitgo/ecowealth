<?php
declare(strict_types=1);

/**
 * Admin dashboard — operations overview (plan §7, Phase 3+).
 *
 * Live data only: accounts, catalogue, inventory and packages. The screen
 * follows the Sneat dashboard language (stat cards, chart + donut row,
 * recent-accounts table) so the back office matches the reference design.
 *
 * Each data block is its own live region; the filter-free welcome card stays
 * outside the regions and is never re-rendered by a background refresh.
 *
 * @var array<string, int|float> $stats
 * @var list<array<string, mixed>> $packages
 * @var list<array<string, mixed>> $recent
 * @var array{labels: list<string>, values: list<float>, stock: list<int>} $chart
 * @var array{healthy: int, low: int, out: int} $stock
 * @var list<array<string, mixed>> $attention
 * @var array{catalogue: array{labels: list<string>, values: list<float>}, stock: array{labels: list<string>, values: list<int>}} $chart_data
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
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

$packages  = $packages ?? [];
$recent    = $recent ?? [];
$attention = $attention ?? [];
$chart     = array_merge(['labels' => [], 'values' => [], 'stock' => []], $chart ?? []);
$stock     = array_merge(['healthy' => 0, 'low' => 0, 'out' => 0], $stock ?? []);

$live = $live ?? [
    'surface'  => 'admin.dashboard',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$chart_data = $chart_data ?? [
    'catalogue' => [
        'labels' => array_values($chart['labels']),
        'values' => array_values(array_map('floatval', $chart['values'])),
    ],
    'stock' => [
        'labels' => ['Healthy', 'Low stock', 'Out of stock'],
        'values' => [(int) $stock['healthy'], (int) $stock['low'], (int) $stock['out']],
    ],
];

$roleTone = static fn (string $role): string => match ($role) {
    'superadmin' => 'info',
    'admin'      => 'primary',
    default      => 'secondary',
};
?>
            <div
              class="container-xxl flex-grow-1 container-p-y"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
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
                        <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
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

<?= View::capture('admin/dashboard/_kpis', ['stats' => $stats]) ?>

              <!-- Charts -->
              <div class="row g-6 mb-6">
<?= View::capture('admin/dashboard/_catalogue', ['stats' => $stats, 'chart' => $chart]) ?>
<?= View::capture('admin/dashboard/_stock', [
    'stats'     => $stats,
    'stock'     => $stock,
    'attention' => $attention,
]) ?>
              </div>

              <!-- Accounts + packages -->
              <div class="row g-6">
<?= View::capture('admin/dashboard/_recent', ['recent' => $recent]) ?>
<?= View::capture('admin/dashboard/_packages', ['packages' => $packages]) ?>
              </div>
            </div>

            <script>
              window.ECOWEALTH_ADMIN = <?= json_encode(
                  $chart_data,
                  JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
              ) ?: '{}' ?>;
            </script>
