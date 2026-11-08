<?php
declare(strict_types=1);

/**
 * Member earnings statement (plan §6, §7 Phase 10; reference
 * views/member/earnings.php).
 *
 * Every row is one award: the activation that caused it, the package
 * that priced it, the level it was paid at and the wallet movement it
 * wrote. The drawer renders the same row without leaving the page.
 *
 * One live surface per member (`member.earnings:{id}`). The summary
 * cards, the skipped-award notice, the card heading, the rows and the
 * pagination are regions; the filter form stays outside them, so a
 * background refresh never discards an unsubmitted filter.
 *
 * @var array{
 *   count:int, credited:float, this_month:float, direct:float,
 *   indirect:float, skipped:int, last_at:?string
 * } $totals
 * @var array<int, array{amount:float, count:int}> $levels
 * @var array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $commissions
 * @var string $wallet_url
 * @var string $genealogy_url
 * @var string $statement_url
 * @var string $export_url
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => 'member.earnings:0',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$hasFilters = $filters['type'] !== ''
    || $filters['level'] !== ''
    || $filters['status'] !== ''
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== '';

/* PHP turns a numeric string key into an int, so the level list is a real
 * list of (value, label) pairs: the comparison against the string filter
 * stays strict. Named `$levelOptions` so it never shadows the breakdown
 * data the `_levels` partial receives. */
$levelOptions = [['value' => '', 'label' => 'All levels']];
for ($level = Commission::LEVEL_DIRECT; $level <= Commission::LEVEL_MAX; $level++) {
    $levelOptions[] = ['value' => (string) $level, 'label' => commission_level_label($level)];
}

$types = ['' => 'All types'];
foreach (Commission::TYPES as $type) {
    $types[$type] = commission_type_label($type);
}

$statuses = ['' => 'All statuses'];
foreach (Commission::STATUSES as $status) {
    $statuses[$status] = ucfirst($status);
}
?>
            <div
              class="container-xxl flex-grow-1 container-p-y commission-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Earnings</h4>
                  <p class="text-body-secondary mb-0">
                    Every commission comes from one activation and moves real wallet money. Awards are final.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e($export_url) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-download me-1"></i> Export CSV
                  </a>
                  <a href="<?= e($genealogy_url) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-git-branch me-1"></i> Genealogy
                  </a>
                  <a href="<?= e($wallet_url) ?>" class="btn btn-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> Open e-wallet
                  </a>
                </div>
              </div>

<?= View::capture('member/earnings/_stats', ['totals' => $totals]) ?>

<?= View::capture('member/earnings/_levels', ['levels' => $levels, 'totals' => $totals]) ?>

<?= View::capture('member/earnings/_skipped', ['totals' => $totals]) ?>

              <div class="card">
<?= View::capture('member/earnings/_header', ['pager' => $pager]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($statement_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <div class="commission-filter-field">
                      <label class="form-label" for="commission-filter-type">Type</label>
                      <select name="type" id="commission-filter-type" class="form-select">
<?php foreach ($types as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="commission-filter-level">Level</label>
                      <select name="level" id="commission-filter-level" class="form-select">
<?php foreach ($levelOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= $filters['level'] === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="commission-filter-status">Status</label>
                      <select name="status" id="commission-filter-status" class="form-select">
<?php foreach ($statuses as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="commission-filter-from">From</label>
                      <input type="date" name="from" id="commission-filter-from" class="form-control" value="<?= e($filters['from']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="commission-filter-to">To</label>
                      <input type="date" name="to" id="commission-filter-to" class="form-control" value="<?= e($filters['to']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="commission-filter-q">Source</label>
                      <input
                        type="search"
                        name="q"
                        id="commission-filter-q"
                        class="form-control"
                        value="<?= e($filters['q']) ?>"
                        placeholder="Member or package…"
                      />
                    </div>

                    <div class="commission-filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e($statement_url) ?>" class="btn btn-label-secondary ms-2">
                        <i class="icon-base bx bx-x me-1"></i> Clear
                      </a>
<?php endif; ?>
                    </div>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle commission-table mb-0">
                    <thead>
                      <tr>
                        <th scope="col" class="commission-col-date">Date</th>
                        <th scope="col">Source</th>
                        <th scope="col" class="commission-col-package">Package</th>
                        <th scope="col" class="commission-col-type">Type</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Details</th>
                      </tr>
                    </thead>
<?= View::capture('member/earnings/_rows', [
    'commissions' => $commissions,
    'filters'     => $filters,
    'wallet_url'  => $wallet_url,
]) ?>
                  </table>
                </div>

<?= View::capture('member/earnings/_pagination', ['pager' => $pager]) ?>
              </div>

<?= View::partial('commission_detail', ['show_recipient' => false]) ?>
            </div>
