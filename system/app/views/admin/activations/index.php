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
 * the rows and the pagination are regions. Approve and reject open a
 * confirmation modal; the rejection reason is typed there, so the rows
 * carry no unsaved inline input.
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

<?= View::capture('admin/activations/_header', ['stats' => $stats]) ?>

<?= View::capture('admin/activations/_stats', ['stats' => $stats]) ?>

<?= View::capture('admin/activations/_tabs', [
    'filters'    => $filters,
    'stats'      => $stats,
    'tab_counts' => $tab_counts,
]) ?>

              <div class="card">
                <div class="card-body py-3">
                  <span class="small text-body-secondary d-block mb-2" data-live-status aria-live="polite"></span>
                  <form method="GET" action="<?= e(url('admin/activations')) ?>" class="membership-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="status" value="<?= e($status) ?>" />
                    <div class="filter-field filter-field-wide">
                      <label class="form-label" for="activation-filter-q">Search</label>
                      <input
                        type="search"
                        class="form-control"
                        id="activation-filter-q"
                        name="q"
                        value="<?= e($query) ?>"
                        placeholder="Member, package or reference…"
                      />
                    </div>
                    <div class="filter-field">
                      <label class="form-label" for="activation-filter-method">Payment method</label>
                      <select class="form-select" id="activation-filter-method" name="method">
<?php foreach ($methods as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $method === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>
                    <div class="filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e(url('admin/activations')) ?>" class="btn btn-label-secondary"><i class="icon-base bx bx-x me-1"></i> Clear filters</a>
<?php endif; ?>
                    </div>
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

<?php
/* Activation binary cards (plan 23D-S2): show the package binary outcome
 * for activations that have been processed. Read-only — PackageBinaryService
 * only reads. */
$binaryCards = [];
foreach ($activations as $activation) {
    if ((string) ($activation['binary_processed_at'] ?? '') !== '' || (int) ($activation['binary_points'] ?? 0) > 0) {
        $binary = (new PackageBinaryService())->totalsFor((int) $activation['id']);
        if ($binary['points'] > 0 || $binary['pairs'] > 0 || $binary['amount'] > 0.0) {
            $binaryCards[] = ['activation' => $activation, 'binary' => $binary];
        }
    }
}
?>
<?php foreach ($binaryCards as $card): ?>
<?= View::capture('admin/activations/_binary', [
    'activation' => $card['activation'],
    'binary'     => $card['binary'],
    'wallet_url' => url('admin/wallets'),
]) ?>
<?php endforeach; ?>
            </div>
