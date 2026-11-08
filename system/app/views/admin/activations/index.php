<?php
declare(strict_types=1);

/**
 * Admin activation queue (plan §4.4, §5.10; Phase 9).
 *
 * Manual (proof-backed) activations wait here. Approving sets the
 * member active, attaches the package and runs the commission hook
 * exactly once — all inside ActivationService.
 *
 * One live surface. The heading badge, the four counters, the status tabs,
 * the rows and the pagination are regions. The rows region holds unsaved
 * rejection reasons: the client keeps the table and the page shows a stale
 * banner instead of replacing a row mid-edit.
 *
 * @var array{total:int, review:int, approved:int, rejected:int, amount_review:float, amount_approved:float} $stats
 * @var array{status:string, method:string, q:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $activations
 * @var array{review:int, approved:int, rejected:int} $tab_counts
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$status     = (string) $filters['status'];
$method     = (string) $filters['method'];
$query      = (string) $filters['q'];
$hasFilters = $status !== '' || $method !== '' || $query !== '';

$methods = [
    ''        => 'All methods',
    'ewallet' => 'E-wallet',
    'code'    => 'Registration code',
    'gcash'   => 'GCash proof',
    'maya'    => 'Maya proof',
    'bank'    => 'Bank proof',
];

$live = $live ?? [
    'surface'  => 'admin.activations',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y activation-queue"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

              <div
                class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none"
                data-live-stale
                role="status"
                aria-live="polite"
              >
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>The activation queue changed in another tab.</strong>
                  Your unsaved rejection reason was kept, so the queue may be out of date.
                  <a href="<?= e(url('admin/activations')) ?>" class="alert-link" data-live-reload>
                    Reload the queue
                  </a>
                  to see the latest.
                </div>
              </div>

<?= View::capture('admin/activations/_header', ['stats' => $stats]) ?>

<?= View::capture('admin/activations/_stats', ['stats' => $stats]) ?>

              <div class="card">
                <div class="card-header">
                  <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
<?= View::capture('admin/activations/_tabs', [
    'filters'    => $filters,
    'stats'      => $stats,
    'tab_counts' => $tab_counts,
]) ?>
                </div>

                <div class="card-body py-3 border-top">
                  <form method="GET" action="<?= e(url('admin/activations')) ?>" class="membership-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="status" value="<?= e($status) ?>" />
                    <input
                      type="search"
                      class="form-control"
                      name="q"
                      value="<?= e($query) ?>"
                      placeholder="Member, package or reference…"
                      aria-label="Search activations"
                    />
                    <select class="form-select" name="method" aria-label="Filter by payment method">
<?php foreach ($methods as $value => $label): ?>
                      <option value="<?= e($value) ?>" <?= $method === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($hasFilters): ?>
                    <a href="<?= e(url('admin/activations')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Member</th>
                        <th>Package</th>
                        <th>Method</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th class="text-end">Review</th>
                      </tr>
                    </thead>
<?= View::capture('admin/activations/_rows', [
    'activations' => $activations,
    'filters'     => $filters,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/activations/_pagination', ['pager' => $pager]) ?>
              </div>
            </div>
