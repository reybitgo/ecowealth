<?php
declare(strict_types=1);

/**
 * Admin commission ledger (plan §6, §7 Phase 10, Appendix A extension;
 * Phase 17D adds the repeat-purchase tab of plan §8; Phase 19D adds the
 * Royalty tab; Phase 20E adds the Team Harvest tab).
 *
 * Four tabs, one URL:
 *
 *   Package commissions        the referral ledger (`commissions`):
 *                              every credited and skipped award,
 *                              filterable by member/source/package,
 *                              type, level, status and date.
 *   Repeat-purchase bonus      the product ladder ledger
 *                              (`unilevel_awards`): one row per order
 *                              line, recipient and level 1–10, with the
 *                              closed-month history above it.
 *   Royalty                    the monthly royalty ledger
 *                              (`royalty_awards`): one row per member
 *                              per closed month, with the closed-month
 *                              cards and the open-month preview above it.
 *   Team Harvest               the level ledger (`team_harvest_awards`):
 *                              one row per (member, level) decision, the
 *                              ten-row distribution and the six counters.
 *   General Harvest bonus      the shared-board ledger
 *                              (`general_harvest_awards`): one row per
 *                              (node, level) decision, the ten-level
 *                              distribution, the board's frontier and the
 *                              six counters. Baseline rows are recorded,
 *                              never paid (D-06).
 *
 * One live surface: the counters, the period card, the card heading's
 * row count, the rows and the pagination are regions. The filter form
 * stays outside them so a background refresh never discards an
 * unsubmitted filter.
 *
 * @var string $tab 'package' (default), 'repeat', 'royalty', 'team_harvest' or 'general_harvest'
 * @var string $ledger The tab, as a query value
 * @var string $ledger_url  This tab's URL
 * @var string $export_url  This tab's CSV export URL
 * @var string $wallet_url
 * @var string $package_url
 * @var string $repeat_url
 * @var string $royalty_url
 * @var string $team_harvest_url
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 *
 * Package tab:
 * @var array{credited_total:float, this_month:float, credited:int, skipped:int, recipients:int, average:float, top_level:int} $stats
 * @var array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $commissions
 *
 * Repeat tab:
 * @var array{credited_total:float, this_month:float, credited:int, skipped:int, recipients:int, average:float, top_level:int, voided:int, unrecovered:float, pending_total:float, pending:int, cancelled:int, cancelled_total:float, total_awarded:float, next_release_at:?string, pending_recipients:int} $stats
 * @var array{level:string, status:string, product_id:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $awards
 * @var list<array<string, mixed>> $periods
 * @var list<array<string, mixed>> $products
 *
 * Royalty tab:
 * @var array{period:string, period_status:?string, rows:int, paid_rows:int, pending_rows:int, skipped_rows:int, recipients:int, paid_total:float, pending_total:float, award_total:float, average:float, top_rank:?string, lifetime_paid:float} $stats
 * @var array{period:string, status:string, rank:string, q:string} $filters
 * @var list<array<string, mixed>> $awards
 * @var list<array<string, mixed>> $periods
 * @var array<string, mixed>|null $preview
 * @var bool   $preview_mode
 * @var array<string, mixed>|null $royalty_ops
 * @var string $open_period
 * @var array<int, array<string, mixed>> $rank_breakdown
 *
 * Team Harvest tab:
 * @var array{credited_total:float, this_month:float, credited_rows:int, recipients:int, baseline_total:float, baseline_rows:int, awards:int, open_levels:int, open_members:int, open_amount:float} $stats
 * @var array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $awards
 * @var array<int, array<string, mixed>> $levels
 * @var int    $max_levels
 * @var bool   $seeded
 * @var ?string $baseline
 * @var bool   $enabled
 *
 * General Harvest tab:
 * @var array{credited_total:float, credited_rows:int, recipients:int, baseline_total:float, baseline_rows:int, awards:int, open_amount:float, open_levels:int, settled_n:int, queue_n:int, armed:bool, enabled:bool} $stats
 * @var array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $awards
 * @var array<int, array<string, mixed>> $levels
 * @var int    $max_levels
 * @var list<array{level:int, rows:int, complete:int, missing:int, next_n:int, amount:float}> $frontier
 */

$tab         = in_array($tab ?? 'package', ['repeat', 'royalty', 'team_harvest', 'general_harvest'], true) ? (string) $tab : 'package';
$package_url = $package_url ?? url('admin/commissions');
$repeat_url  = $repeat_url ?? url('admin/commissions?ledger=repeat');
$royalty_url = $royalty_url ?? url('admin/commissions?ledger=royalty');
$team_harvest_url = $team_harvest_url ?? url('admin/commissions?ledger=team_harvest');
$general_harvest_url = $general_harvest_url ?? url('admin/commissions?ledger=general_harvest');
$ledger_url  = $ledger_url ?? match ($tab) {
    'repeat'            => $repeat_url,
    'royalty'           => $royalty_url,
    'team_harvest'      => $team_harvest_url,
    'general_harvest'   => $general_harvest_url,
    default             => $package_url,
};
$export_url  = $export_url ?? url('admin/commissions/export');
$wallet_url  = $wallet_url ?? url('admin/wallets');

$live = $live ?? [
    'surface'  => 'admin.commissions',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$isRepeat      = $tab === 'repeat';
$isRoyalty     = $tab === 'royalty';
$isTeamHarvest = $tab === 'team_harvest';
$isGeneralHarvest = $tab === 'general_harvest';

if ($isRoyalty) {
    $stats        = $stats ?? [];
    $filters      = $filters ?? RoyaltyAward::filterDefaults();
    $awards       = $awards ?? [];
    $periods      = $periods ?? [];
    $preview      = $preview ?? null;
    $preview_mode = (bool) ($preview_mode ?? false);
    $royalty_ops  = $royalty_ops ?? null;
    $open_period  = (string) ($open_period ?? RoyaltyPeriod::periodOf());
    $pager        = $pager ?? new Paginator(0, Paginator::perPageFromRequest());

    $hasFilters = (string) $filters['status'] !== ''
        || (string) $filters['rank'] !== ''
        || (string) $filters['q'] !== '';

    $rankOptions = [['value' => '', 'label' => 'All paying ranks']];
    foreach ([RoyaltyRank::DIRECTOR, RoyaltyRank::MANAGER, RoyaltyRank::SUPERVISOR] as $rank) {
        $rankOptions[] = ['value' => (string) $rank, 'label' => RoyaltyRank::NAMES[$rank]];
    }

    $statusOptions = ['' => 'All statuses'];
    foreach (RoyaltyAward::STATUSES as $status) {
        $stateLabels = [
            'pending' => 'Awaiting payment',
            'paid'    => 'Paid',
            'skipped' => 'Skipped',
        ];
        $statusOptions[$status] = $stateLabels[$status] ?? RoyaltyAward::STATUS_LABELS[$status] ?? ucfirst($status);
    }

    $periodOptions = [['value' => '', 'label' => 'Latest closed month']];

    foreach ($periods as $row) {
        $ts = strtotime((string) $row['period'] . '-01 12:00:00');
        $periodOptions[] = [
            'value' => (string) $row['period'],
            'label' => ($ts === false ? (string) $row['period'] : date('M Y', $ts))
                . ' — ' . RoyaltyPeriod::label((string) $row['status']),
        ];
    }

    $openTs = strtotime($open_period . '-01 12:00:00');
    $periodOptions[] = [
        'value' => $open_period,
        'label' => ($openTs === false ? $open_period : date('M Y', $openTs)) . ' — current month (preview)',
    ];
} elseif ($isRepeat) {
    $filters = $filters ?? UnilevelAward::filterDefaults();
    $awards  = $awards ?? [];
    $periods = $periods ?? [];
    $products = $products ?? [];
    $pager   = $pager ?? Paginator::fromTableRequest(count($awards));

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
    foreach (UnilevelAward::STATUSES as $status) {
        /* The two escrow states read exactly like their row badge. */
        $statusOptions[$status] = UnilevelAward::STATUS_BADGES[$status][0]
            ?? UnilevelAward::STATUS_LABELS[$status]
            ?? ucfirst($status);
    }
} elseif ($isTeamHarvest) {
    $stats    = $stats ?? [];
    $filters  = $filters ?? TeamHarvestAward::filterDefaults();
    $awards   = $awards ?? [];
    $levels   = $levels ?? [];
    $max_levels = (int) ($max_levels ?? TeamHarvestLevel::maxLevels());
    $seeded   = (bool) ($seeded ?? false);
    $baseline = $baseline ?? null;
    $enabled  = (bool) ($enabled ?? false);
    $pager    = $pager ?? new Paginator(0, Paginator::perPageFromRequest());

    $hasFilters = (string) $filters['level'] !== ''
        || (string) $filters['status'] !== ''
        || (string) $filters['from'] !== ''
        || (string) $filters['to'] !== ''
        || (string) $filters['q'] !== '';

    $levelOptions = [['value' => '', 'label' => 'All levels']];
    for ($level = TeamHarvestLevel::LEVEL_MIN; $level <= TeamHarvestLevel::LEVEL_MAX; $level++) {
        $levelOptions[] = ['value' => (string) $level, 'label' => 'Level ' . $level];
    }

    /* The two stored statuses, with the admin labels the row badge uses
     * (plan Appendix C.7). */
    $statusOptions = ['' => 'All statuses'];
    foreach (TeamHarvestAward::STATUSES as $status) {
        $statusOptions[$status] = TeamHarvestAward::STATUS_LABELS[$status] ?? ucfirst($status);
    }
} elseif ($isGeneralHarvest) {
    $stats    = $stats ?? [];
    $filters  = $filters ?? GeneralHarvestAward::filterDefaults();
    $awards   = $awards ?? [];
    $levels   = $levels ?? [];
    $max_levels = (int) ($max_levels ?? GeneralHarvestLevel::maxLevels());
    $frontier = $frontier ?? [];
    $pager    = $pager ?? new Paginator(0, Paginator::perPageFromRequest());

    $hasFilters = (string) $filters['level'] !== ''
        || (string) $filters['status'] !== ''
        || (string) $filters['from'] !== ''
        || (string) $filters['to'] !== ''
        || (string) $filters['q'] !== '';

    $levelOptions = [['value' => '', 'label' => 'All levels']];
    for ($level = GeneralHarvestLevel::LEVEL_MIN; $level <= GeneralHarvestLevel::LEVEL_MAX; $level++) {
        $levelOptions[] = ['value' => (string) $level, 'label' => 'Level ' . $level];
    }

    /* The two stored statuses, with the admin labels the row badge uses
     * (plan Appendix C.7). */
    $statusOptions = ['' => 'All statuses'];
    foreach (GeneralHarvestAward::STATUSES as $status) {
        $statusOptions[$status] = GeneralHarvestAward::STATUS_LABELS[$status] ?? ucfirst($status);
    }
} else {
    $filters = $filters ?? Commission::normaliseFilters([]);
    $pager   = $pager ?? Paginator::fromTableRequest(Commission::countAdmin($filters));
    $commissions = $commissions ?? [];

    $hasFilters = $filters['type'] !== ''
        || $filters['level'] !== ''
        || $filters['status'] !== ''
        || $filters['from'] !== ''
        || $filters['to'] !== ''
        || $filters['q'] !== '';

    $types = ['' => 'All types'];
    foreach (Commission::TYPES as $type) {
        $types[$type] = commission_type_label($type);
    }

    /* PHP turns a numeric string key into an int, so the level list is a real
     * list of (value, label) pairs: the comparison against the string filter
     * stays strict. */
    $levels = [['value' => '', 'label' => 'All levels']];
    for ($level = Commission::LEVEL_DIRECT; $level <= Commission::LEVEL_MAX; $level++) {
        $levels[] = ['value' => (string) $level, 'label' => commission_level_label($level)];
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
            >
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
<?php if ($isRoyalty): ?>
                  <h4 class="mb-1">Royalty bonus</h4>
                  <p class="text-body-secondary mb-0">
                    Every Royalty award, one per member per closed month, with the closed-month cards and
                    the open-month preview. Never per order.
                  </p>
<?php elseif ($isTeamHarvest): ?>
                  <h4 class="mb-1">Team Harvest bonus</h4>
                  <p class="text-body-secondary mb-0">
                    One row per level a member completed, with the ten-level distribution and the open liability.
                    It never depends on purchases, rank or the month — only on who qualified, and when.
                  </p>
<?php elseif ($isRepeat): ?>
                  <h4 class="mb-1">Repeat-purchase bonus</h4>
                  <p class="text-body-secondary mb-0">
                    Every award a paid order earned, with its release state. One row per line, recipient and level 1–10.
                  </p>
<?php else: ?>
                  <h4 class="mb-1">Commissions</h4>
                  <p class="text-body-secondary mb-0">
                    Every referral award, credited or skipped. One row per activation, recipient and level.
                  </p>
<?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e($export_url) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-download me-1"></i> Export CSV
                  </a>
                  <a href="<?= e($wallet_url) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> E-wallet monitor
                  </a>
                </div>
              </div>

              <ul class="nav nav-pills mb-4 commission-tabs" role="list">
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e($package_url) ?>"
                    class="nav-link<?= $tab === 'package' ? ' active' : '' ?>"
<?= $tab === 'package' ? ' aria-current="page"' : '' ?>
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
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e($royalty_url) ?>"
                    class="nav-link<?= $isRoyalty ? ' active' : '' ?>"
<?= $isRoyalty ? ' aria-current="page"' : '' ?>
                  >
                    <i class="icon-base bx bx-crown me-1"></i> Royalty
                  </a>
                </li>
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e($team_harvest_url) ?>"
                    class="nav-link<?= $isTeamHarvest ? ' active' : '' ?>"
<?= $isTeamHarvest ? ' aria-current="page"' : '' ?>
                  >
                    <i class="icon-base bx bx-medal me-1"></i> Team Harvest
                  </a>
                </li>
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e($general_harvest_url) ?>"
                    class="nav-link<?= $isGeneralHarvest ? ' active' : '' ?>"
<?= $isGeneralHarvest ? ' aria-current="page"' : '' ?>
                  >
                    <i class="icon-base bx bx-grid-alt me-1"></i> General Harvest bonus
                  </a>
                </li>
              </ul>

<?php if ($isRepeat): ?>
<?= View::capture('admin/commissions/_repeat_stats', ['stats' => $stats]) ?>

<?= View::capture('admin/commissions/_repeat_periods', ['periods' => $periods]) ?>

              <div class="card">
<?= View::capture('admin/commissions/_repeat_header', ['pager' => $pager]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($ledger_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="repeat" />
                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="repeat-filter-q">Recipient, buyer, product or order</label>
                      <input
                        type="search"
                        name="q"
                        id="repeat-filter-q"
                        class="form-control"
                        value="<?= e((string) $filters['q']) ?>"
                        placeholder="Search the ledger…"
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
                      <a href="<?= e($ledger_url) ?>" class="btn btn-label-secondary ms-2">
                        <i class="icon-base bx bx-x me-1"></i> Clear
                      </a>
<?php endif; ?>
                    </div>
                  </form>
                  <div class="text-end">
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle commission-table mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Lvl</th>
                        <th scope="col">Recipient</th>
                        <th scope="col">Buyer</th>
                        <th scope="col">Product</th>
                        <th scope="col" class="text-end">Base</th>
                        <th scope="col" class="text-end">Rate</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col">Order</th>
                        <th scope="col" class="text-nowrap">Awarded</th>
                        <th scope="col" class="text-end">Wallet</th>
                      </tr>
                    </thead>
<?= View::capture('admin/commissions/_repeat_rows', [
    'awards'  => $awards,
    'filters' => $filters,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/commissions/_repeat_pagination', ['pager' => $pager]) ?>
              </div>
<?php elseif ($isRoyalty): ?>
<?= View::capture('admin/commissions/_royalty_ops', [
    'ops'         => $royalty_ops ?? null,
    'preview'     => $preview,
    'open_period' => $open_period,
]) ?>

<?= View::capture('admin/commissions/_royalty_stats', [
    'stats'        => $stats,
    'preview'      => $preview,
    'preview_mode' => $preview_mode,
    'open_period'  => $open_period,
]) ?>

<?= View::capture('admin/commissions/_royalty_periods', [
    'periods'  => $periods,
    'selected' => $filters['period'],
    'open'     => $open_period,
]) ?>

              <div class="card">
<?= View::capture('admin/commissions/_royalty_header', [
    'pager'         => $pager,
    'period'        => $filters['period'],
    'period_status' => $stats['period_status'] ?? null,
    'preview_mode'  => $preview_mode,
]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($ledger_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="royalty" />

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="royalty-filter-period">Period</label>
                      <select name="period" id="royalty-filter-period" class="form-select">
<?php foreach ($periodOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= $filters['period'] === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="royalty-filter-rank">Rank</label>
                      <select name="rank" id="royalty-filter-rank" class="form-select">
<?php foreach ($rankOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= $filters['rank'] === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="royalty-filter-status">Status</label>
                      <select name="status" id="royalty-filter-status" class="form-select">
<?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="royalty-filter-q">Member</label>
                      <input
                        type="search"
                        name="q"
                        id="royalty-filter-q"
                        class="form-control"
                        value="<?= e((string) $filters['q']) ?>"
                        placeholder="Username or full name…"
                      />
                    </div>

                    <div class="commission-filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e($ledger_url) ?>" class="btn btn-label-secondary ms-2">
                        <i class="icon-base bx bx-x me-1"></i> Clear
                      </a>
<?php endif; ?>
                    </div>
                  </form>
                  <div class="text-end">
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle commission-table mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Member</th>
                        <th scope="col">Rank</th>
                        <th scope="col" class="text-end">Group sales</th>
                        <th scope="col" class="text-end">Personal sales</th>
                        <th scope="col" class="text-end">Quota</th>
                        <th scope="col" class="text-end">Rates</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Ledger</th>
                        <th scope="col" class="text-nowrap">Paid</th>
                      </tr>
                    </thead>
<?= View::capture('admin/commissions/_royalty_rows', [
    'awards'       => $awards,
    'filters'      => $filters,
    'preview_mode' => $preview_mode,
    'open_period'  => $open_period,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/commissions/_royalty_pagination', ['pager' => $pager]) ?>
              </div>
<?php elseif ($isTeamHarvest): ?>
<?= View::capture('admin/commissions/_team_harvest_stats', ['stats' => $stats]) ?>

<?= View::capture('admin/commissions/_team_harvest_levels', [
    'levels'     => $levels,
    'max_levels' => $max_levels,
]) ?>

              <div class="card">
<?= View::capture('admin/commissions/_team_harvest_header', [
    'stats'    => $stats,
    'pager'    => $pager,
    'seeded'   => $seeded,
    'baseline' => $baseline,
    'enabled'  => $enabled,
]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($ledger_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="team_harvest" />

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="team-harvest-filter-q">Member</label>
                      <input
                        type="search"
                        name="q"
                        id="team-harvest-filter-q"
                        class="form-control"
                        value="<?= e((string) $filters['q']) ?>"
                        placeholder="Username…"
                      />
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="team-harvest-filter-level">Level</label>
                      <select name="level" id="team-harvest-filter-level" class="form-select">
<?php foreach ($levelOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= $filters['level'] === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="team-harvest-filter-status">Status</label>
                      <select name="status" id="team-harvest-filter-status" class="form-select">
<?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="team-harvest-filter-from">From</label>
                      <input type="date" name="from" id="team-harvest-filter-from" class="form-control" value="<?= e((string) $filters['from']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="team-harvest-filter-to">To</label>
                      <input type="date" name="to" id="team-harvest-filter-to" class="form-control" value="<?= e((string) $filters['to']) ?>" />
                    </div>

                    <div class="commission-filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e($ledger_url) ?>" class="btn btn-label-secondary ms-2">
                        <i class="icon-base bx bx-x me-1"></i> Clear
                      </a>
<?php endif; ?>
                    </div>
                  </form>
                  <div class="text-end">
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle commission-table mb-0">
                    <thead>
                      <tr>
                        <th scope="col" class="text-nowrap">Date</th>
                        <th scope="col">Member</th>
                        <th scope="col">Position</th>
                        <th scope="col">Level</th>
                        <th scope="col" class="text-end">Teammates</th>
                        <th scope="col" class="text-end">Threshold</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Wallet</th>
                      </tr>
                    </thead>
<?= View::capture('admin/commissions/_team_harvest_rows', [
    'awards'  => $awards,
    'filters' => $filters,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/commissions/_team_harvest_pagination', ['pager' => $pager]) ?>
              </div>
<?php elseif ($isGeneralHarvest): ?>
<?= View::capture('admin/commissions/_general_harvest_header', [
    'stats'   => $stats,
    'pager'   => $pager,
    'armed'   => (bool) ($stats['armed'] ?? false),
    'enabled' => (bool) ($stats['enabled'] ?? false),
]) ?>

<?= View::capture('admin/commissions/_general_harvest_stats', ['stats' => $stats]) ?>

<?= View::capture('admin/commissions/_general_harvest_levels', [
    'levels'     => $levels,
    'max_levels' => $max_levels,
]) ?>

              <div class="card">
                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($ledger_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="general_harvest" />

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="general-harvest-filter-q">Member</label>
                      <input
                        type="search"
                        name="q"
                        id="general-harvest-filter-q"
                        class="form-control"
                        value="<?= e((string) $filters['q']) ?>"
                        placeholder="Username…"
                      />
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="general-harvest-filter-level">Level</label>
                      <select name="level" id="general-harvest-filter-level" class="form-select">
<?php foreach ($levelOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= $filters['level'] === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="general-harvest-filter-status">Status</label>
                      <select name="status" id="general-harvest-filter-status" class="form-select">
<?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="general-harvest-filter-from">From</label>
                      <input type="date" name="from" id="general-harvest-filter-from" class="form-control" value="<?= e((string) $filters['from']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="general-harvest-filter-to">To</label>
                      <input type="date" name="to" id="general-harvest-filter-to" class="form-control" value="<?= e((string) $filters['to']) ?>" />
                    </div>

                    <div class="commission-filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e($ledger_url) ?>" class="btn btn-label-secondary ms-2">
                        <i class="icon-base bx bx-x me-1"></i> Clear
                      </a>
<?php endif; ?>
                    </div>
                  </form>
                  <div class="text-end">
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle commission-table mb-0">
                    <thead>
                      <tr>
                        <th scope="col" class="text-nowrap">Date</th>
                        <th scope="col">Member</th>
                        <th scope="col">Position</th>
                        <th scope="col">Node</th>
                        <th scope="col" class="text-nowrap">Level</th>
                        <th scope="col" class="text-end">Queue (N)</th>
                        <th scope="col" class="text-end">Threshold</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Wallet</th>
                      </tr>
                    </thead>
<?php
    $ledgerUrl = url('admin/commissions?ledger=general_harvest');
?>
<?= View::capture('admin/commissions/_general_harvest_rows', [
    'awards'  => $awards,
    'filters' => $filters,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/commissions/_general_harvest_pagination', ['pager' => $pager]) ?>
              </div>
<?php else: ?>
<?= View::capture('admin/commissions/_stats', ['stats' => $stats]) ?>

              <div class="card">
<?= View::capture('admin/commissions/_header', ['pager' => $pager]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($ledger_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="commission-filter-q">Member, source or package</label>
                      <input
                        type="search"
                        name="q"
                        id="commission-filter-q"
                        class="form-control"
                        value="<?= e($filters['q']) ?>"
                        placeholder="Search the ledger…"
                      />
                    </div>

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
<?php foreach ($levels as $option): ?>
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

                    <div class="commission-filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php if ($hasFilters): ?>
                      <a href="<?= e($ledger_url) ?>" class="btn btn-label-secondary ms-2">
                        <i class="icon-base bx bx-x me-1"></i> Clear
                      </a>
<?php endif; ?>
                    </div>
                  </form>
                  <div class="text-end">
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle commission-table mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Recipient</th>
                        <th scope="col">Source</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="commission-col-activation">Activation</th>
                        <th scope="col" class="text-nowrap commission-col-awarded">Awarded</th>
                        <th scope="col" class="text-end">Details</th>
                      </tr>
                    </thead>
<?= View::capture('admin/commissions/_rows', [
    'commissions' => $commissions,
    'filters'     => $filters,
]) ?>
                  </table>
                </div>

<?= View::capture('admin/commissions/_pagination', ['pager' => $pager]) ?>
              </div>

<?= View::partial('commission_detail', ['show_recipient' => true]) ?>
<?php endif; ?>
            </div>
