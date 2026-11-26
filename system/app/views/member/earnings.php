<?php
declare(strict_types=1);

/**
 * Member earnings statement (plan §6, §7 Phase 10; reference
 * views/member/earnings.php; Phase 17E adds the repeat-purchase tab of
 * plan §9; Phase 19E adds the Royalty tab; Phase 20F adds the Team
 * Harvest tab).
 *
 * Four tabs, one URL:
 *
 *   Package commissions      every activation award, credited or
 *                            skipped, with its level breakdown.
 *   Repeat-purchase bonus    every repeat-purchase award the member
 *                            earned from their Team chain, with the
 *                            two-clock Active panel and the 1–10
 *                            breakdown.
 *   Royalty                  the monthly award: the rank card (rank,
 *                            next-rank progress, this month's team
 *                            sales against the quota and the
 *                            "if this month closed now" projection),
 *                            the five counters and one row per closed
 *                            month.
 *   Team Harvest             the position and the gate that opened it,
 *                            the next level's progress, the whole 3×10
 *                            matrix with its empty slots and their
 *                            amounts, the roster of qualified teammates
 *                            and one row per level paid (or recorded
 *                            before go-live).
 *   General Harvest          the member's position on the shared board,
 *                            the ten blocks with filled/slots and the
 *                            amount each level pays, the next block's
 *                            progress and how far the queue still has to
 *                            grow, the paginated award rows and the CSV.
 *
 * The package tab is the Phase 10–11 statement, unchanged below the
 * new tab pills. The repeat tab is Team chain directed (plan §9): the
 * member's own purchases never pay them, they only set the Active flag
 * for the income their Team chain's purchases produce. The Royalty tab
 * is monthly and team-wide: group sales are the member's own purchases
 * plus every member below them, paid once per closed month (plan §12).
 * The Team Harvest tab is structural: it pays on qualification, never on
 * a purchase, and each level pays once, ever (plan §10).
 *
 * One live surface per member (`member.earnings:{id}`). The summary
 * cards, the rank card, the Team Harvest cards, the status panel, the
 * breakdown, the card headings, the rows and the pagination are regions;
 * the filter forms and the tab pills stay outside them, so a background
 * refresh never discards an unsubmitted filter and never rewrites the tab
 * the member is on.
 *
 * @var string $tab 'package' (default), 'repeat', 'royalty' or 'team_harvest'
 * @var string $wallet_url
 * @var string $genealogy_url
 * @var string $package_url
 * @var string $repeat_url
 * @var string $royalty_url
 * @var string $team_harvest_url
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
 *
 * Royalty tab:
 * @var array<string, mixed> $royalty    RoyaltyService::statusFor()
 * @var array<string, mixed> $stats      the five counters
 * @var array<string, mixed> $totals     RoyaltyAward::totalsForUser()
 * @var array{period:string, status:string, rank:string, q:string} $filters
 * @var list<string>               $periods  the member's own closed months
 * @var list<array<string, mixed>> $awards
 *
 * Team Harvest tab (Phase 20F):
 * @var array<string, mixed>       $team_harvest TeamHarvestService::statusFor()
 * @var array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $awards       one row per decided level
 * @var list<array<string, mixed>> $roster       a page of HarvestQueue::matrix()
 * @var Paginator                  $roster_pager
 *
 * General Harvest tab (Phase 21F):
 * @var array<string, mixed>       $general_harvest GeneralHarvestService::statusFor()
 * @var array{user_id:int, level:string, status:string, from:string, to:string, q:string} $filters
 * @var list<array<string, mixed>> $awards       one row per decided level
 * @var Paginator                  $pager
 */

$live = $live ?? [
    'surface'  => 'member.earnings:0',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$tab          = in_array($tab ?? 'package', ['repeat', 'royalty', 'team_harvest', 'general_harvest', 'binary'], true) ? (string) $tab : 'package';
$isRepeat     = $tab === 'repeat';
$isRoyalty    = $tab === 'royalty';
$isTeam       = $tab === 'team_harvest';
$isGeneral    = $tab === 'general_harvest';
$isBinary     = $tab === 'binary';
$package_url  = $package_url ?? url('earnings');
$repeat_url   = $repeat_url ?? url('earnings?ledger=repeat');
$royalty_url  = $royalty_url ?? url('earnings?ledger=royalty');
$team_harvest_url = $team_harvest_url ?? url('earnings?ledger=team_harvest');
$general_harvest_url = $general_harvest_url ?? url('earnings?ledger=general_harvest');
$binary_url          = $binary_url ?? url('earnings?ledger=binary');
$statement_url = $statement_url ?? match ($tab) {
    'repeat'       => $repeat_url,
    'royalty'      => $royalty_url,
    'team_harvest' => $team_harvest_url,
    'general_harvest' => $general_harvest_url,
    default        => $package_url,
};
$export_url   = $export_url ?? url('earnings/export');

if ($isGeneral) {
    $general_harvest = $general_harvest ?? (new GeneralHarvestService())->statusFor((int) ($user_id ?? 0));
    $filters         = $filters ?? GeneralHarvestAward::filterDefaults();
    $awards          = $awards ?? [];
    $pager           = $pager ?? Paginator::fromTableRequest(count($awards));
}

if ($isTeam) {
    $team_harvest = $team_harvest ?? (new TeamHarvestService())->statusFor((int) ($user_id ?? 0));
    $filters      = $filters ?? TeamHarvestAward::filterDefaults();
    $awards       = $awards ?? [];
    $roster       = $roster ?? [];
    $pager        = $pager ?? Paginator::fromTableRequest(count($awards));
    $roster_pager = $roster_pager ?? Paginator::make(count($roster));

    $hasFilters = (string) $filters['level'] !== ''
        || (string) $filters['status'] !== ''
        || (string) $filters['from'] !== ''
        || (string) $filters['to'] !== '';

    $levelOptions = [['value' => '', 'label' => 'All levels']];
    for ($level = TeamHarvestLevel::LEVEL_MIN; $level <= TeamHarvestLevel::LEVEL_MAX; $level++) {
        $levelOptions[] = ['value' => (string) $level, 'label' => 'Level ' . $level];
    }

    /* Member-facing status labels (Appendix C.7): the two stored states
     * are the only ones a row can carry. */
    $statusOptions = ['' => 'All statuses'];
    foreach (TeamHarvestAward::STATUSES as $statusKey) {
        $statusOptions[$statusKey] = TeamHarvestAward::MEMBER_STATUS_LABELS[$statusKey] ?? ucfirst($statusKey);
    }
} elseif ($isRoyalty) {
    $royalty  = $royalty ?? (new RoyaltyService())->statusFor((int) ($user_id ?? 0));
    $filters  = $filters ?? RoyaltyAward::filterDefaults();
    $awards   = $awards ?? [];
    $periods  = $periods ?? [];
    $pager    = $pager ?? Paginator::fromTableRequest(count($awards));
    $stats    = $stats ?? [];

    $hasFilters = (string) $filters['period'] !== ''
        || (string) $filters['status'] !== ''
        || (string) $filters['rank'] !== '';

    $rankOptions = [['value' => '', 'label' => 'All ranks']];
    foreach ([RoyaltyRank::DIRECTOR, RoyaltyRank::MANAGER, RoyaltyRank::SUPERVISOR] as $rankLevel) {
        $rankOptions[] = ['value' => (string) $rankLevel, 'label' => RoyaltyRank::NAMES[$rankLevel]];
    }

    /* Member-facing status labels (Appendix C.8): a skipped month reads
     * "Not paid", never the admin's "Skipped". */
    $statusOptions = ['' => 'All statuses'];
    foreach (RoyaltyAward::STATUSES as $statusKey) {
        $statusOptions[$statusKey] = RoyaltyAward::MEMBER_STATUS_LABELS[$statusKey] ?? ucfirst($statusKey);
    }

    $periodOptions = [['value' => '', 'label' => 'All months']];
    foreach ($periods as $periodKey) {
        $ts = strtotime((string) $periodKey . '-01 12:00:00');
        $periodOptions[] = [
            'value' => (string) $periodKey,
            'label' => $ts === false ? (string) $periodKey : date('F Y', $ts),
        ];
    }
} elseif ($isRepeat) {
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
} elseif (!$isBinary) {
    /* Package commissions — the default tab. The binary tab keeps its own
     * filter shape (`kind`, `status`, `side`, `source`, no `type`) and builds
     * its own $hasFilters from $binaryFilters before it renders. */
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

$headerTitle = match (true) {
    $isTeam    => 'Your Team Harvest bonus',
    $isRoyalty => 'Your Royalty bonus',
    $isRepeat  => 'Repeat-purchase bonus',
    default    => 'Earnings',
};
$headerDescription = match (true) {
    $isTeam => 'A private 3×10 matrix: levels fill with qualified teammates and each level pays once, when its block is full.',
    $isRoyalty => "Royalty is paid once a month, after the month closes, when your rank and your team's group sales meet the quota.",
    $isRepeat => 'Every repeat-purchase award your <strong>Team chain</strong>\'s paid purchases earned you.',
    default => 'Every commission comes from one activation and moves real wallet money. Awards are final.',
};
$headerDescription .= ' <span class="small text-body-secondary" data-live-status aria-live="polite"></span>';
?>
            <div
              class="container-xxl flex-grow-1 container-p-y commission-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
<?php if ($isRepeat || $isRoyalty || $isTeam): ?>
              data-live-config="<?= e(Live::GENEALOGY_TEAM) ?>"
<?php endif; ?>
            >
              <?= flash_render() ?>

<?= View::partial('page_header', [
    'title'       => $headerTitle,
    'description' => $headerDescription,
    'actions'     => '<div class="d-flex flex-wrap gap-2">'
        . '<a href="' . e($export_url) . '" class="btn btn-label-secondary">'
        . '<i class="icon-base bx bx-download me-1"></i> Export CSV</a>'
        . (($isRepeat || $isRoyalty || $isTeam)
            ? '<a href="' . e($genealogy_url ?? url('genealogy')) . '" class="btn btn-label-secondary">'
                . '<i class="icon-base bx bx-git-branch me-1"></i> Genealogy</a>'
            : '')
        . '<a href="' . e($wallet_url) . '" class="btn btn-primary">'
        . '<i class="icon-base bx bx-wallet me-1"></i> Open e-wallet</a>'
        . '</div>',
]) ?>

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
                    class="nav-link<?= $isTeam ? ' active' : '' ?>"
<?= $isTeam ? ' aria-current="page"' : '' ?>
                  >
                    <i class="icon-base bx bx-trophy me-1"></i> Team Harvest
                  </a>
                </li>
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e($general_harvest_url) ?>"
                    class="nav-link<?= $isGeneral ? ' active' : '' ?>"
<?= $isGeneral ? ' aria-current="page"' : '' ?>
                  >
                    <i class="icon-base bx bx-grid-alt me-1"></i> General Harvest
                  </a>
                </li>
<?php if (BinaryService::enabled()): ?>
                <li class="nav-item" role="listitem">
                  <a
                    href="<?= e($binary_url) ?>"
                    class="nav-link<?= $isBinary ? ' active' : '' ?>"
<?= $isBinary ? ' aria-current="page"' : '' ?>
                  >
                    <i class="icon-base bx bx-git-branch me-1"></i> Binary bonus
                  </a>
                </li>
<?php endif; ?>
              </ul>

<?php if ($isTeam): ?>
<?php $teamQueued = (bool) ($team_harvest['queued'] ?? false); ?>
<?php if (!$teamQueued): ?>
<?= View::capture('member/earnings/_team_progress', ['team_harvest' => $team_harvest]) ?>
<?php else: ?>
<?= View::capture('member/earnings/_team_stats', ['team_harvest' => $team_harvest]) ?>

<?= View::capture('member/earnings/_team_progress', ['team_harvest' => $team_harvest]) ?>

<?= View::capture('member/earnings/_team_matrix', [
    'team_harvest' => $team_harvest,
    'roster'       => $roster,
    'roster_pager' => $roster_pager,
    'user_id'      => (int) ($user_id ?? 0),
]) ?>

              <div class="card">
<?= View::capture('member/earnings/_team_header', [
    'team_harvest' => $team_harvest,
    'pager'        => $pager,
]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($statement_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="team_harvest" />

                    <div class="commission-filter-field">
                      <label class="form-label" for="team-filter-level">Level</label>
                      <select name="level" id="team-filter-level" class="form-select">
<?php foreach ($levelOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= $filters['level'] === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="team-filter-status">Status</label>
                      <select name="status" id="team-filter-status" class="form-select">
<?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="team-filter-from">From</label>
                      <input type="date" name="from" id="team-filter-from" class="form-control" value="<?= e((string) $filters['from']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="team-filter-to">To</label>
                      <input type="date" name="to" id="team-filter-to" class="form-control" value="<?= e((string) $filters['to']) ?>" />
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
                        <th scope="col">Level</th>
                        <th scope="col" class="text-end">Teammates at award</th>
                        <th scope="col" class="text-end commission-col-quota">Threshold</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Wallet</th>
                      </tr>
                    </thead>
<?= View::capture('member/earnings/_team_rows', [
    'awards'     => $awards,
    'filters'    => $filters,
    'wallet_url' => $wallet_url,
]) ?>
                  </table>
                </div>

<?= View::capture('member/earnings/_team_pagination', ['pager' => $pager]) ?>
              </div>
<?php endif; ?>

              <!-- Member FAQ (plan Appendix C.9): the six questions every member asks. -->
              <div class="card mt-4">
                <div class="card-header">
                  <h5 class="card-title mb-0">Team Harvest questions</h5>
                </div>
                <div class="card-body">
                  <div class="row g-4 small">
                    <div class="col-md-6">
                      <p class="mb-3">
                        <strong class="d-block">How do I qualify?</strong>
                        <?= (int) ($team_harvest['gate'] ?? TeamHarvestLevel::qualifyDirects()) ?> members register
                        with you as their sponsor. That is all.
                      </p>
                      <p class="mb-3">
                        <strong class="d-block">Who counts as my teammate?</strong>
                        A member below you, in any generation, who qualified <strong>after</strong> you.
                      </p>
                      <p class="mb-0">
                        <strong class="d-block">Does buying anything help?</strong>
                        No. Purchases play no part in this bonus.
                      </p>
                    </div>
                    <div class="col-md-6">
                      <p class="mb-3">
                        <strong class="d-block">When am I paid?</strong>
                        The moment a level fills — the money is in your e-wallet immediately.
                      </p>
                      <p class="mb-3">
                        <strong class="d-block">Why is somebody in my team not in my matrix?</strong>
                        They qualified before you did.
                      </p>
                      <p class="mb-0">
                        <strong class="d-block">Why did my level not pay?</strong>
                        Either the level is not full (see the count above), the amount for it is zero, or it
                        was already complete before the plan started paying (marked <strong>Before
                        go-live</strong>).
                      </p>
                    </div>
                  </div>
                </div>
              </div>
<?php elseif ($isRoyalty): ?>
<?= View::capture('member/earnings/_royalty_rank', ['royalty' => $royalty]) ?>

<?= View::capture('member/earnings/_royalty_stats', ['stats' => $stats]) ?>

              <div class="card">
<?= View::capture('member/earnings/_royalty_header', ['pager' => $pager]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($statement_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="royalty" />

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="royalty-filter-period">Month</label>
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
                        <th scope="col" class="commission-col-period">Month</th>
                        <th scope="col">Rank</th>
                        <th scope="col" class="text-end commission-col-gs">Group sales</th>
                        <th scope="col" class="text-end commission-col-ps">Personal sales</th>
                        <th scope="col" class="text-end commission-col-quota">Quota</th>
                        <th scope="col" class="text-end commission-col-rates">Rates</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-nowrap commission-col-paid">Paid</th>
                      </tr>
                    </thead>
<?= View::capture('member/earnings/_royalty_rows', [
    'awards'           => $awards,
    'filters'          => $filters,
    'payments_enabled' => (bool) ($royalty['payments_enabled'] ?? true),
]) ?>
                  </table>
                </div>

<?= View::capture('member/earnings/_royalty_pagination', ['pager' => $pager]) ?>
              </div>
<?php elseif ($isRepeat): ?>
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
<?php elseif ($isGeneral): ?>
<?php $generalQueued = (bool) ($general_harvest['queued'] ?? false); ?>
<?php if (!$generalQueued): ?>
<?= View::capture('member/earnings/_general_progress', ['general_harvest' => $general_harvest]) ?>
<?php else: ?>
              <?= View::capture('member/earnings/_general_stats', ['general_harvest' => $general_harvest]) ?>
              <?= View::capture('member/earnings/_general_blocks', ['general_harvest' => $general_harvest]) ?>
              <?= View::capture('member/earnings/_general_progress', ['general_harvest' => $general_harvest]) ?>
              <div class="card">
<?= View::capture('member/earnings/_general_header', [
    'general_harvest' => $general_harvest,
]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($statement_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="general_harvest" />

                    <div class="commission-filter-field">
                      <label class="form-label" for="general-filter-level">Level</label>
                      <select name="level" id="general-filter-level" class="form-select">
                        <option value="">All levels</option>
<?php for ($level = 1; $level <= 10; $level++): ?>
                          <option value="<?= $level ?>" <?= $filters['level'] === (string) $level ? 'selected' : '' ?>>Level <?= $level ?></option>
<?php endfor; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="general-filter-status">Status</label>
                      <select name="status" id="general-filter-status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="credited" <?= $filters['status'] === 'credited' ? 'selected' : '' ?>>Paid</option>
                        <option value="baseline" <?= $filters['status'] === 'baseline' ? 'selected' : '' ?>>Before go-live</option>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="general-filter-from">From</label>
                      <input type="date" name="from" id="general-filter-from" class="form-control" value="<?= e((string) $filters['from']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="general-filter-to">To</label>
                      <input type="date" name="to" id="general-filter-to" class="form-control" value="<?= e((string) $filters['to']) ?>" />
                    </div>

                    <div class="commission-filter-actions">
                      <button type="submit" class="btn btn-primary">Filter</button>
<?php $hasFilters = (string) $filters['level'] !== '' || (string) $filters['status'] !== '' || (string) $filters['from'] !== '' || (string) $filters['to'] !== ''; ?>
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
                        <th scope="col">Level</th>
                        <th scope="col" class="text-end">Queue at award</th>
                        <th scope="col" class="text-end commission-col-quota">Threshold</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Wallet</th>
                      </tr>
                    </thead>
<?= View::capture('member/earnings/_general_rows', [
    'awards'     => $awards,
    'filters'    => $filters,
    'wallet_url' => $wallet_url,
]) ?>
                  </table>
                </div>

<?= View::capture('member/earnings/_general_pagination', ['pager' => $pager]) ?>
              </div>

<?= View::capture('member/earnings/_general_roster', [
    'roster' => $general_harvest['roster'] ?? null,
]) ?>
<?php endif; ?>
<?php elseif ($isBinary): ?>
<?php
    $binaryNode   = BinaryNode::forUser((int) ($user_id ?? 0));
    $binaryTotals = $totals ?? BinaryAward::totalsForUser((int) ($user_id ?? 0));
    $binaryPending= $pending ?? BinaryAward::pendingSummaryForUser((int) ($user_id ?? 0));
    $binaryPager  = $pager ?? Paginator::fromTableRequest(count($awards ?? []));
    $binaryFilters= $filters ?? BinaryAward::filterDefaults();
    $binaryAwards = $awards ?? [];
    $binarySourceOptions = $source_options ?? [];

    $hasFilters = (string) $binaryFilters['kind'] !== ''
        || (string) $binaryFilters['status'] !== ''
        || (string) $binaryFilters['side'] !== ''
        || (string) $binaryFilters['product_id'] !== ''
        || (string) $binaryFilters['from'] !== ''
        || (string) $binaryFilters['to'] !== ''
        || (string) $binaryFilters['q'] !== '';
?>
<?php if ($binaryNode === null): ?>
              <div class="card mt-4">
                <div class="card-header">
                  <h5 class="card-title mb-0">Product binary</h5>
                </div>
                <div class="card-body text-center py-5">
                  <i class="icon-base bx bx-user-x text-body-muted mb-3" style="font-size: 2.25rem;"></i>
                  <h5 class="mb-2">You are not placed in the binary tree yet</h5>
                  <p class="text-body-secondary mb-3">
                    Your purchases will not place points until you are placed. Ask your upline or an
                    administrator to place you in the tree.
                  </p>
                  <a href="<?= e(url('shop')) ?>" class="btn btn-primary">
                    <i class="icon-base bx bx-store me-1"></i> Keep shopping
                  </a>
                </div>
              </div>
<?php else: ?>
              <div class="row g-6 mb-6">
<?= View::capture('member/earnings/_binary_stats', [
    'totals'  => $binaryTotals,
    'pending' => $binaryPending,
    'stats'   => $stats ?? [],
]) ?>
<?= View::capture('member/earnings/_binary_self', ['totals' => $binaryTotals]) ?>
<?php
    $packageCard = $package_card ?? null;
    if ($packageCard !== null && $packageCard['has_package'] === true):
?>
<?= View::capture('member/earnings/_binary_package', [
    'package_card' => $packageCard,
]) ?>
<?php endif; ?>
              </div>

<?php
    $leftPts  = (int) ($binaryNode['left_points']  ?? 0);
    $rightPts = (int) ($binaryNode['right_points'] ?? 0);
    $pairPts  = (int) setting_int('binary_pair_points', 1000);
?>
<?= View::capture('member/earnings/_binary_progress', [
    'left_points'  => $leftPts,
    'right_points' => $rightPts,
    'pair_points'  => $pairPts,
]) ?>
<?= View::capture('member/earnings/_binary_teams', [
    'leftTeam'  => $left_team  ?? [],
    'rightTeam' => $right_team ?? [],
    'left_points'  => $leftPts,
    'right_points' => $rightPts,
]) ?>

              <div class="card">
<?= View::capture('member/earnings/_binary_header', ['pager' => $binaryPager]) ?>

                <div class="card-body border-bottom">
                  <form method="GET" action="<?= e($statement_url) ?>" class="d-flex flex-wrap align-items-end gap-3 commission-filters">
                    <?= per_page_hidden() ?>
                    <input type="hidden" name="ledger" value="binary" />

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="binary-filter-kind">Kind</label>
                      <select name="kind" id="binary-filter-kind" class="form-select">
                        <option value="">All kinds</option>
                        <option value="self" <?= $binaryFilters['kind'] === 'self' ? 'selected' : '' ?>>Self bonus</option>
                        <option value="pair" <?= $binaryFilters['kind'] === 'pair' ? 'selected' : '' ?>>Pair</option>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="binary-filter-status">Status</label>
                      <select name="status" id="binary-filter-status" class="form-select">
                        <option value="">All statuses</option>
                        <option value="pending" <?= $binaryFilters['status'] === 'pending' ? 'selected' : '' ?>>Pending release</option>
                        <option value="credited" <?= $binaryFilters['status'] === 'credited' ? 'selected' : '' ?>>Credited</option>
                        <option value="cancelled" <?= $binaryFilters['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="voided" <?= $binaryFilters['status'] === 'voided' ? 'selected' : '' ?>>Voided</option>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="binary-filter-side">Side</label>
                      <select name="side" id="binary-filter-side" class="form-select">
                        <option value="">All sides</option>
                        <option value="left" <?= $binaryFilters['side'] === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="right" <?= $binaryFilters['side'] === 'right' ? 'selected' : '' ?>>Right</option>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="binary-filter-product">Product</label>
                      <select name="product_id" id="binary-filter-product" class="form-select">
                        <option value="">All products</option>
<?php foreach (Product::all() as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" <?= $binaryFilters['product_id'] === (string) $product['id'] ? 'selected' : '' ?>><?= e((string) $product['name']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field">
                      <label class="form-label" for="binary-filter-source">Source</label>
                      <select name="source" id="binary-filter-source" class="form-select">
                        <option value="">All sources</option>
<?php foreach ($binarySourceOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= $binaryFilters['source'] === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="binary-filter-from">From</label>
                      <input type="date" name="from" id="binary-filter-from" class="form-control" value="<?= e((string) $binaryFilters['from']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-date">
                      <label class="form-label" for="binary-filter-to">To</label>
                      <input type="date" name="to" id="binary-filter-to" class="form-control" value="<?= e((string) $binaryFilters['to']) ?>" />
                    </div>

                    <div class="commission-filter-field commission-filter-wide">
                      <label class="form-label" for="binary-filter-q">Order, product or teammate</label>
                      <input
                        type="search"
                        name="q"
                        id="binary-filter-q"
                        class="form-control"
                        value="<?= e((string) $binaryFilters['q']) ?>"
                        placeholder="Search your awards…"
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
                        <th scope="col" class="commission-col-kind">Kind</th>
                        <th scope="col" class="commission-col-buyer">Buyer</th>
                        <th scope="col" class="commission-col-product">Product</th>
                        <th scope="col" class="text-nowrap commission-col-side">Side</th>
                        <th scope="col" class="text-end commission-col-points">Points</th>
                        <th scope="col" class="text-end">Pairs</th>
                        <th scope="col" class="text-end commission-col-rate">Rate</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="commission-col-source">Source</th>
                        <th scope="col" class="commission-col-order">Order</th>
                      </tr>
                    </thead>
<?= View::capture('member/earnings/_binary_rows', [
    'awards'     => $binaryAwards,
    'filters'    => $binaryFilters,
    'wallet_url' => $wallet_url,
    'source_options' => $binarySourceOptions,
]) ?>
                  </table>
                </div>

<?= View::capture('member/earnings/_binary_pagination', ['pager' => $binaryPager]) ?>
              </div>
<?php endif; ?>
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
