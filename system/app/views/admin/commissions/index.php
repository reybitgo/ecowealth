<?php
declare(strict_types=1);

/**
 * Admin commission ledger (plan §6, §7 Phase 10, Appendix A extension).
 *
 * The company-wide money trail: every credited award and every
 * skipped one, filterable by member/source/package, type, level,
 * status and date. Each row links to its recipient's wallet statement,
 * the activation that caused it and the drawer that shows the full
 * record.
 *
 * @var array{
 *   credited_total:float, this_month:float, credited:int, skipped:int,
 *   recipients:int, average:float, top_level:int
 * } $stats
 * @var array{type:string, level:string, status:string, from:string, to:string, q:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $commissions
 * @var string $ledger_url
 * @var string $wallet_url
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
        'value' => money($stats['credited_total']),
        'hint'  => (int) $stats['recipients'] . ' member' . ($stats['recipients'] === 1 ? '' : 's') . ' paid',
        'icon'  => 'bx-line-chart',
        'tone'  => 'success',
    ],
    [
        'label' => 'This month',
        'value' => money($stats['this_month']),
        'hint'  => 'Since the 1st',
        'icon'  => 'bx-calendar',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Awards',
        'value' => (string) $stats['credited'],
        'hint'  => $stats['credited'] === 0 ? 'Nothing paid yet' : 'Average ' . money($stats['average']),
        'icon'  => 'bx-receipt',
        'tone'  => 'info',
    ],
    [
        'label' => 'Skipped',
        'value' => (string) $stats['skipped'],
        'hint'  => 'Ineligible upline — no money moved',
        'icon'  => 'bx-block',
        'tone'  => 'warning',
    ],
];

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
?>
            <div class="container-xxl flex-grow-1 container-p-y commission-page">
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Commissions</h4>
                  <p class="text-body-secondary mb-0">
                    Every referral award, credited or skipped. One row per activation, recipient and level.
                  </p>
                </div>
                <a href="<?= e($wallet_url) ?>" class="btn btn-label-primary">
                  <i class="icon-base bx bx-wallet me-1"></i> E-wallet monitor
                </a>
              </div>

              <div class="row g-4 mb-4">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
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

              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-1">Commission ledger</h5>
                    <p class="text-body-secondary mb-0 small">
                      Awards are immutable; a correction is a new adjustment, never an edit.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> row<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>

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
                    <tbody>
<?php if ($commissions === []): ?>
                      <tr>
                        <td colspan="8" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-line-chart',
        'title'        => $hasFilters ? 'No commissions match these filters' : 'No commissions yet',
        'text'         => $hasFilters
            ? 'Try a different member, type, level, status or date range.'
            : 'A direct referral is credited when a sponsored member activates a package.',
        'action_href'  => $hasFilters ? 'admin/commissions' : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
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
        'recipient'      => '@' . (string) $commission['recipient_username'],
        'recipient_url'  => url('admin/wallets/' . (int) $commission['user_id']),
        'source'         => '@' . (string) $commission['source_username'],
        'package'        => (string) $commission['package_name'],
        'type'           => commission_type_label($type),
        'level'          => commission_level_label($level),
        'amount'         => money($amount),
        'status'         => $credited ? 'Credited to the wallet' : ucfirst($status) . ' — no money moved',
        'reason'         => (string) ($commission['reason'] ?? ''),
        'created'        => format_datetime((string) $commission['created_at']),
        'activation'     => 'Activation #' . (int) $commission['activation_id'],
        'activation_url' => url('admin/activations?q=' . rawurlencode((string) $commission['source_username'])),
        'wallet'         => $credited ? 'E-wallet entry' : 'No wallet movement',
        'wallet_url'     => $credited ? url('admin/wallets/' . (int) $commission['user_id']) : '',
    ];
?>
                      <tr>
                        <td>
                          <a href="<?= e(url('admin/members/' . (int) $commission['user_id'])) ?>" class="text-body fw-medium">
                            @<?= e((string) $commission['recipient_username']) ?>
                          </a>
                          <?php if (trim((string) ($commission['recipient_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $commission['recipient_name']) ?></div>
                          <?php endif; ?>
                          <div class="small text-body-secondary commission-meta-mobile">
                            #<?= (int) $commission['activation_id'] ?>
                            · <?= e(date('M j, Y', (int) strtotime((string) $commission['created_at']))) ?>
                          </div>
                        </td>
                        <td>
                          <span class="fw-medium">@<?= e((string) $commission['source_username']) ?></span>
                          <?php if (trim((string) ($commission['source_name'] ?? '')) !== ''): ?>
                          <div class="small text-body-secondary"><?= e((string) $commission['source_name']) ?></div>
                          <?php endif; ?>
                          <div class="small text-body-secondary"><?= e((string) $commission['package_name']) ?></div>
                        </td>
                        <td class="text-nowrap">
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
                        <td class="text-nowrap commission-col-activation">
                          <a
                            href="<?= e(url('admin/activations?q=' . rawurlencode((string) $commission['source_username']))) ?>"
                            class="text-body"
                          >#<?= (int) $commission['activation_id'] ?></a>
                          <div class="small text-body-secondary"><?= e(payment_method_label((string) $commission['activation_method'])) ?></div>
                        </td>
                        <td class="text-nowrap commission-col-awarded">
                          <div><?= e(date('M j, Y', (int) strtotime((string) $commission['created_at']))) ?></div>
                          <div class="small text-body-secondary"><?= e(date('g:i A', (int) strtotime((string) $commission['created_at']))) ?></div>
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

<?= View::partial('commission_detail', ['show_recipient' => true]) ?>
            </div>
