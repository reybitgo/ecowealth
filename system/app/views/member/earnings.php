<?php
declare(strict_types=1);

/**
 * Member earnings statement (plan §6, §7 Phase 10; reference
 * views/member/earnings.php; Phase 17E adds the repeat-purchase tab of
 * plan §9).
 *
 * Two tabs, one URL:
 *
 *   Package commissions      every activation award, credited or
 *                            skipped, with its level breakdown.
 *   Repeat-purchase bonus    every repeat-purchase award the member
 *                            earned from their Team chain, with the
 *                            two-clock Active panel and the 1–10
 *                            breakdown.
 *
 * The package tab is the Phase 10–11 statement, unchanged below the
 * new tab pills. The repeat tab is Team chain directed (plan §9): the
 * member's own purchases never pay them, they only set the Active flag
 * for the income their Team chain's purchases produce.
 *
 * One live surface per member (`member.earnings:{id}`). The summary
 * cards, the status panel, the breakdown, the card heading, the rows
 * and the pagination are regions; the filter form and the tab pills
 * stay outside them, so a background refresh never discards an
 * unsubmitted filter and never rewrites the tab the member is on.
 *
 * @var string $tab 'package' (default) or 'repeat'
 * @var string $wallet_url
 * @var string $genealogy_url
 * @var string $package_url
 * @var string $repeat_url
 * @var string $statement_url  this tab's URL
 * @var string $export_url     this tab's CSV export URL
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 *
 * Package tab:
 * @var array{
 *   count:int, credited:float, this_month:float, direct:float,
 *   indirect:float, skipped:int, last_at:?string
 * } $totals
 * @var array<int, array{amount:float, count:int}> $levels
 * @var array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $commissions
 *
 * Repeat tab:
 * @var array{count:int, credited:float, this_month:float, skipped:int, last_at:?string} $totals
 * @var array<int, array{
 *   amount:float, count:int, team_count:int, pager:Paginator,
 *   members:list<array{
 *     id:int, username:string, full_name:string, package_name:?string,
 *     member_status:string, amount:float, count:int
 *   }>
 * }> $levels
 * @var array{level:string, status:string, product_id:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $awards
 * @var list<array<string, mixed>> $products
 * @var array<string, mixed>       $status   UnilevelCycle::memberStatus()
 * @var string $shop_url
 */

$live = $live ?? [
    'surface'  => 'member.earnings:0',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$tab         = ($tab ?? 'package') === 'repeat' ? 'repeat' : 'package';
$isRepeat    = $tab === 'repeat';
$package_url = $package_url ?? url('earnings');
$repeat_url  = $repeat_url ?? url('earnings?ledger=repeat');
$statement_url = $statement_url ?? ($isRepeat ? $repeat_url : $package_url);
$export_url  = $export_url ?? url('earnings/export');

if ($isRepeat) {
    $filters  = $filters ?? UnilevelAward::filterDefaults();
    $awards   = $awards ?? [];
    $products = $products ?? [];
    $pager    = $pager ?? Paginator::fromTableRequest(count($awards));
    $status   = $status ?? UnilevelCycle::memberStatus((int) ($user_id ?? 0));

    $hasFilters = (string) $filters['level'] !== ''
        || (string) $filters['status'] !== ''
        || (string) $filters['product_id'] !== ''
        || (string) $filters['from'] !== ''
        || (string) $filters['to'] !== ''
        || (string) $filters['q'] !== '';

    $levelOptions = [['value' => '', 'label' => 'All levels']];
    for ($level = UnilevelAward::LEVEL_MIN; $level <= UnilevelAward::LEVEL_MAX; $level++) {
        $levelOptions[] = ['value' => (string) $level, 'label' => 'Level ' . $level];
    }

    $statusOptions = ['' => 'All statuses'];
    foreach (UnilevelAward::STATUSES as $statusKey) {
        $statusOptions[$statusKey] = UnilevelAward::STATUS_LABELS[$statusKey] ?? ucfirst($statusKey);
    }
} else {
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
}
?>
            <div
              class="container-xxl flex-grow-1 container-p-y commission-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
<?php if ($isRepeat): ?>
              data-live-config="<?= e(Live::GENEALOGY_TEAM) ?>"
<?php endif; ?>
            >
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
<?php if ($isRepeat): ?>
                  <h4 class="mb-1">Repeat-purchase bonus</h4>
                  <p class="text-body-secondary mb-0">
                    Every repeat-purchase award your <strong>Team chain</strong>'s paid purchases earned you.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
<?php else: ?>
                  <h4 class="mb-1">Earnings</h4>
                  <p class="text-body-secondary mb-0">
                    Every commission comes from one activation and moves real wallet money. Awards are final.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
<?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e($export_url) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-download me-1"></i> Export CSV
                  </a>
<?php if ($isRepeat): ?>
                  <a href="<?= e($genealogy_url ?? url('genealogy')) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-git-branch me-1"></i> Genealogy
                  </a>
<?php endif; ?>
                  <a href="<?= e($wallet_url) ?>" class="btn btn-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> Open e-wallet
                  </a>
                </div>
              </div>

              <ul class="nav nav-pills mb-4 commission-tabs" role="list">
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e($package_url) ?>"
                    class="nav-link<?= $isRepeat ? '' : ' active' ?>"
<?= $isRepeat ? '' : ' aria-current="page"' ?>
                  >
                    <i class="icon-base bx bx-line-chart me-1"></i> Package commissions
                  </a>
                </li>
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e($repeat_url) ?>"
                    class="nav-link<?= $isRepeat ? ' active' : '' ?>"
<?= $isRepeat ? ' aria-current="page"' : '' ?>
                  >
                    <i class="icon-base bx bx-gift me-1"></i> Repeat-purchase bonus
                  </a>
                </li>
              </ul>

<?php if ($isRepeat): ?>
<?= View::capture('member/earnings/_repeat_stats', ['totals' => $totals]) ?>

<?= View::capture('member/earnings/_repeat_status', ['status' => $status]) ?>

<?= View::capture('member/earnings/_repeat_levels', ['levels' => $levels, 'totals' => $totals]) ?>

              <div class="card">
<?= View::capture('member/earnings/_repeat_header', ['pager' => $pager]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($statement_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="repeat" />
                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="repeat-filter-q">Product, order or buyer</label>
                      <input
                        type="search"
                        name="q"
                        id="repeat-filter-q"
                        class="form-control"
                        value="<?= e((string) $filters['q']) ?>"
                        placeholder="Search your awards…"
                      />
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="repeat-filter-level">Level</label>
                      <select name="level" id="repeat-filter-level" class="form-select">
<?php foreach ($levelOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= $filters['level'] === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="repeat-filter-status">Status</label>
                      <select name="status" id="repeat-filter-status" class="form-select">
<?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="repeat-filter-product">Product</label>
                      <select name="product_id" id="repeat-filter-product" class="form-select">
                        <option value="">All products</option>
<?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" <?= $filters['product_id'] === (string) $product['id'] ? 'selected' : '' ?>><?= e((string) $product['name']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="repeat-filter-from">From</label>
                      <input type="date" name="from" id="repeat-filter-from" class="form-control" value="<?= e((string) $filters['from']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="repeat-filter-to">To</label>
                      <input type="date" name="to" id="repeat-filter-to" class="form-control" value="<?= e((string) $filters['to']) ?>" />
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
                        <th scope="col" class="commission-col-source">Buyer</th>
                        <th scope="col" class="commission-col-package">Product</th>
                        <th scope="col">Level</th>
                        <th scope="col" class="text-end commission-col-base">Base</th>
                        <th scope="col" class="text-end commission-col-rate">Rate</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="commission-col-order">Order</th>
                      </tr>
                    </thead>
<?= View::capture('member/earnings/_repeat_rows', [
    'awards'        => $awards,
    'filters'       => $filters,
    'statement_url' => $statement_url,
    'wallet_url'    => $wallet_url,
    'shop_url'      => $shop_url ?? url('shop'),
]) ?>
                  </table>
                </div>

<?= View::capture('member/earnings/_repeat_pagination', ['pager' => $pager]) ?>
              </div>
<?php else: ?>
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
<?php endif; ?>
            </div>
