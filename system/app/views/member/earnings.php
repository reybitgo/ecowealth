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
 * @var array{
 *   count:int, credited:float, this_month:float, direct:float,
 *   indirect:float, skipped:int, last_at:?string
 * } $totals
 * @var array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $commissions
 * @var string $wallet_url
 * @var string $genealogy_url
 * @var string $statement_url
 */

$hasFilters = $filters['type'] !== ''
    || $filters['level'] !== ''
    || $filters['status'] !== ''
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['q'] !== '';

$cards = [
    [
        'label' => 'Total credited',
        'value' => money($totals['credited']),
        'hint'  => $totals['count'] === 0
            ? 'No awards yet'
            : (string) $totals['count'] . ' award' . ($totals['count'] === 1 ? '' : 's') . ' · withdrawable',
        'icon'  => 'bx-line-chart',
        'tone'  => 'success',
    ],
    [
        'label' => 'This month',
        'value' => money($totals['this_month']),
        'hint'  => $totals['last_at'] === null ? 'Nothing credited yet' : 'Last award ' . time_ago($totals['last_at']),
        'icon'  => 'bx-calendar',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Direct referral',
        'value' => money($totals['direct']),
        'hint'  => 'Level 1 — your personally sponsored associates',
        'icon'  => 'bx-user-plus',
        'tone'  => 'info',
    ],
    [
        'label' => 'Indirect referral',
        'value' => money($totals['indirect']),
        'hint'  => 'Levels 2–10 of the unilevel ladder',
        'icon'  => 'bx-git-branch',
        'tone'  => 'warning',
    ],
];

/* PHP turns a numeric string key into an int, so the level list is a real
 * list of (value, label) pairs: the comparison against the string filter
 * stays strict. */
$levels = [['value' => '', 'label' => 'All levels']];
for ($level = Commission::LEVEL_DIRECT; $level <= Commission::LEVEL_MAX; $level++) {
    $levels[] = ['value' => (string) $level, 'label' => commission_level_label($level)];
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
            <div class="container-xxl flex-grow-1 container-p-y commission-page">
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Earnings</h4>
                  <p class="text-body-secondary mb-0">
                    Every commission comes from one activation and moves real wallet money. Awards are final.
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e($genealogy_url) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-git-branch me-1"></i> Genealogy
                  </a>
                  <a href="<?= e($wallet_url) ?>" class="btn btn-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> Open e-wallet
                  </a>
                </div>
              </div>

              <div class="row g-4 mb-4">
<?php foreach ($cards as $card): ?>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100 commission-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-2 commission-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

<?php if ($totals['skipped'] > 0): ?>
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
                <i class="icon-base bx bx-info-circle fs-4"></i>
                <div class="small">
                  <strong><?= (int) $totals['skipped'] ?> award<?= $totals['skipped'] === 1 ? ' was' : 's were' ?> skipped.</strong>
                  Your upline position was not eligible (no active package) when the activation happened, so no
                  money moved. Skipped awards are kept for the audit trail and never paid later.
                </div>
              </div>
<?php endif; ?>

<?php if ($pager->total === 0 && !$hasFilters): ?>
              <div class="card">
                <div class="card-body py-2">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-line-chart',
        'title'        => 'No commissions yet',
        'text'         => 'A ₱500 direct referral lands here the moment someone you sponsored activates a package. '
            . 'Your e-wallet balance and statement are the other half of the picture.',
        'action_href'  => 'wallet',
        'action_label' => 'Open e-wallet',
    ]);
?>
                </div>
              </div>
<?php else: ?>
              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-1">Awards</h5>
                    <p class="text-body-secondary mb-0 small">
                      One row per activation, recipient and level — never paid twice.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> award<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>

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
                    <tbody>
<?php if ($commissions === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-search',
        'title'        => 'No awards match these filters',
        'text'         => 'Try a different type, level, status or date range.',
        'action_href'  => 'earnings',
        'action_label' => 'Clear filters',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($commissions as $commission): ?>
<?php
    $id       = (int) $commission['id'];
    $status   = (string) $commission['status'];
    $amount   = (float) $commission['amount'];
    $level    = (int) $commission['level'];
    $type     = (string) $commission['type'];
    $credited = $status === 'credited';
    $detail   = [
        'id'             => '#' . $id,
        'source'         => '@' . (string) $commission['source_username'],
        'package'        => (string) $commission['package_name'],
        'type'           => commission_type_label($type),
        'level'          => commission_level_label($level),
        'amount'         => money($amount),
        'status'         => $credited ? 'Credited to your e-wallet' : ucfirst($status) . ' — no money moved',
        'reason'         => (string) ($commission['reason'] ?? ''),
        'created'        => format_datetime((string) $commission['created_at']),
        'activation'     => 'Activation #' . (int) $commission['activation_id'],
        'activation_url' => url('membership'),
        'wallet'         => $credited ? 'E-wallet statement' : 'No wallet movement',
        'wallet_url'     => $credited ? $wallet_url : '',
    ];
?>
                      <tr>
                        <td class="text-nowrap commission-col-date">
                          <div><?= e(date('M j, Y', (int) strtotime((string) $commission['created_at']))) ?></div>
                          <div class="small text-body-secondary"><?= e(date('g:i A', (int) strtotime((string) $commission['created_at']))) ?></div>
                        </td>
                        <td class="commission-col-source">
                          <span class="fw-medium">@<?= e((string) $commission['source_username']) ?></span>
                          <?php if (trim((string) ($commission['source_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $commission['source_name']) ?></div>
                          <?php endif; ?>
                          <div class="small text-body-secondary commission-meta-mobile">
                            <?= e((string) $commission['package_name']) ?>
                            · <?= e(commission_type_label($type)) ?>
                            · <?= e(commission_level_label($level)) ?>
                            · <?= e(date('M j, Y', (int) strtotime((string) $commission['created_at']))) ?>
                          </div>
                        </td>
                        <td class="text-nowrap commission-col-package"><?= e((string) $commission['package_name']) ?></td>
                        <td class="text-nowrap commission-col-type">
                          <span class="d-inline-flex align-items-center gap-2">
                            <i class="icon-base <?= e(commission_type_icon($type)) ?>"></i>
                            <span>
                              <?= e(commission_type_label($type)) ?>
                              <span class="d-block small text-body-secondary"><?= e(commission_level_label($level)) ?></span>
                            </span>
                          </span>
                        </td>
                        <td class="text-end text-nowrap fw-semibold <?= $credited ? 'commission-amount-credit' : 'text-body-secondary' ?>">
                          <?= e($credited ? money($amount, true) : money($amount)) ?>
<?php if (!$credited): ?>
                          <div class="small text-body-secondary fw-normal">not paid</div>
<?php endif; ?>
                        </td>
                        <td>
                          <?= status_badge($status) ?>
<?php if ($status === 'skipped' && (string) ($commission['reason'] ?? '') !== ''): ?>
                          <div class="small text-body-secondary commission-reason"><?= e((string) $commission['reason']) ?></div>
<?php endif; ?>
                        </td>
                        <td class="text-end">
                          <button
                            type="button"
                            class="btn btn-sm btn-icon btn-label-secondary"
                            data-bs-toggle="tooltip"
                            title="Commission detail"
                            aria-label="Commission detail"
                            data-commission="<?= e((string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
                          >
                            <i class="icon-base bx bx-show"></i>
                          </button>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Awards']) ?>
                </div>
              </div>

<?= View::partial('commission_detail', ['show_recipient' => false]) ?>
<?php endif; ?>
            </div>
