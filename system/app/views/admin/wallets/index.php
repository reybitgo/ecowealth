<?php
declare(strict_types=1);

/**
 * Admin e-wallet monitor (plan §5.8, §7 Phase 5; reference
 * views/admin/ewallet_monitor.php).
 *
 * Four numbers matter at a glance — total float, how much of it is
 * withdrawable, how much is locked to shop spending, and what was
 * topped up today — then three tabs: the member directory, every
 * admin top-up, and every ledger movement across all wallets.
 *
 * @var array{
 *   total: float, withdrawable: float, locked: float, funded_members: int,
 *   topups: int, topup_total: float, topups_today: int, topup_today_total: float,
 *   credits_today: float, debits_today: float
 * } $totals
 * @var string $tab
 * @var array<string, int> $tab_counts
 * @var string $query
 * @var string $funded
 * @var string $status
 * @var list<array<string, mixed>> $members
 * @var Paginator $members_pager
 * @var list<array<string, mixed>> $topups
 * @var Paginator $topups_pager
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $ledger_pager
 * @var array{rows:int, credits:float, debits:float, net:float} $ledger_totals
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $ledger_filters
 */

$cards = [
    [
        'label' => 'Total float',
        'value' => money((float) $totals['total']),
        'hint'  => $totals['funded_members'] . ' funded wallet' . ($totals['funded_members'] === 1 ? '' : 's'),
        'icon'  => 'bx-wallet',
        'tone'  => 'primary',
    ],
    [
        'label' => 'Withdrawable',
        'value' => money((float) $totals['withdrawable']),
        'hint'  => 'Cashable by members',
        'icon'  => 'bx-money-withdraw',
        'tone'  => 'success',
    ],
    [
        'label' => 'Non-withdrawable',
        'value' => money((float) $totals['locked']),
        'hint'  => 'Spendable in the shop only',
        'icon'  => 'bx-lock-alt',
        'tone'  => 'warning',
    ],
    [
        'label' => 'Top-ups today',
        'value' => money((float) $totals['topup_today_total']),
        'hint'  => $totals['topups_today'] . ' today · ' . money((float) $totals['topup_total']) . ' lifetime',
        'icon'  => 'bx-plus-circle',
        'tone'  => 'info',
    ],
];

$tabs = [
    'members' => ['label' => 'Members', 'icon' => 'bx-group'],
    'topups'  => ['label' => 'Top-ups', 'icon' => 'bx-plus-circle'],
    'ledger'  => ['label' => 'All movements', 'icon' => 'bx-transfer'],
];
?>
            <div class="container-xxl flex-grow-1 container-p-y wallet-monitor">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">E-wallet monitor</h4>
                  <p class="text-body-secondary mb-0">
                    The float, both buckets and every movement. Top-ups are audited; ledger rows are immutable.
                  </p>
                </div>
                <div class="text-md-end">
                  <span class="badge bg-label-secondary me-1">Credits today <?= e(money((float) $totals['credits_today'])) ?></span>
                  <span class="badge bg-label-secondary">Debits today <?= e(money((float) $totals['debits_today'])) ?></span>
                </div>
              </div>

              <div class="row g-6 mb-6">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl-3">
                  <div class="card h-100 wallet-stat-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                        <span class="text-body-secondary small"><?= e($card['label']) ?></span>
                      </div>
                      <h4 class="mb-2 wallet-stat-value"><?= e($card['value']) ?></h4>
                      <p class="text-body-secondary small mb-0"><?= e($card['hint']) ?></p>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <ul class="nav nav-pills mb-4 wallet-tabs" role="tablist">
<?php foreach ($tabs as $key => $meta): ?>
                <li class="nav-item" role="presentation">
                  <a
                    class="nav-link <?= $tab === $key ? 'active' : '' ?>"
                    href="<?= e(url('admin/wallets?tab=' . $key)) ?>"
                    role="tab"
                    aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
                  >
                    <i class="icon-base <?= e(icon_class($meta['icon'])) ?> me-1"></i> <?= e($meta['label']) ?>
                    <span class="badge rounded-pill bg-label-primary ms-1"><?= (int) ($tab_counts[$key] ?? 0) ?></span>
                  </a>
                </li>
<?php endforeach; ?>
              </ul>

<?php if ($tab === 'members'): ?>
              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <h5 class="mb-0">Members</h5>
                  <form method="GET" action="<?= e(url('admin/wallets')) ?>" class="d-flex flex-wrap gap-2 wallet-search">
                    <input type="hidden" name="tab" value="members" />
                    <?= per_page_hidden() ?>
                    <input
                      type="search"
                      name="q"
                      class="form-control"
                      value="<?= e($query) ?>"
                      placeholder="Username, name or email…"
                      aria-label="Search members"
                    />
                    <select name="funded" class="form-select" aria-label="Filter by balance">
                      <option value="">All members</option>
                      <option value="1" <?= $funded === '1' ? 'selected' : '' ?>>Funded only</option>
                    </select>
                    <select name="status" class="form-select" aria-label="Filter by status">
                      <option value="">All statuses</option>
<?php foreach (['active' => 'Active', 'pending' => 'Pending', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label): ?>
                      <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($query !== '' || $funded !== '' || $status !== ''): ?>
                    <a href="<?= e(url('admin/wallets?tab=members')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Member</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Total</th>
                        <th scope="col" class="text-end">Withdrawable</th>
                        <th scope="col" class="text-end">Non-withdrawable</th>
                        <th scope="col">Last movement</th>
                        <th scope="col" class="text-end">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
<?php if ($members === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-group',
        'title' => 'No members match these filters',
        'text'  => 'Try a different search, or clear the filters to list every wallet.',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($members as $member): ?>
<?php
    $memberId     = (int) $member['id'];
    $memberTotal  = round((float) $member['ewallet_balance'], 2);
    $memberWith   = round((float) $member['withdrawable_balance'], 2);
    $memberLocked = round($memberTotal - $memberWith, 2);

    /* Status, not role: the wallet detail page already shows the role,
     * and the monitor needs to distinguish pending from active members. */
    $memberStatus = (string) ($member['member_status'] ?? '');

    if ($memberStatus === '') {
        $memberStatus = (int) ($member['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
    }
?>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center gap-3">
                            <span class="avatar avatar-sm">
                              <span class="avatar-initial rounded-circle bg-label-primary">
                                <?= e(initials((string) $member['username'])) ?>
                              </span>
                            </span>
                            <div class="min-w-0">
                              <a href="<?= e(url('admin/wallets/' . $memberId)) ?>" class="text-body fw-medium">
                                <?= e((string) $member['username']) ?>
                              </a>
                              <div class="small text-body-secondary">
                                <?= e(trim((string) ($member['full_name'] ?? '')) !== '' ? (string) $member['full_name'] : (string) $member['email']) ?>
                              </div>
                            </div>
                          </div>
                        </td>
                        <td><?= status_badge($memberStatus) ?></td>
                        <td class="text-end text-nowrap fw-semibold"><?= e(money($memberTotal)) ?></td>
                        <td class="text-end text-nowrap"><?= e(money($memberWith)) ?></td>
                        <td class="text-end text-nowrap text-body-secondary"><?= e(money($memberLocked)) ?></td>
                        <td class="text-nowrap small text-body-secondary">
                          <?= e($member['last_movement_at'] === null ? '—' : time_ago((string) $member['last_movement_at'])) ?>
                        </td>
                        <td class="text-end">
                          <a href="<?= e(url('admin/wallets/' . $memberId)) ?>" class="btn btn-sm btn-label-primary">
                            <i class="icon-base bx bx-wallet me-1"></i> Open
                          </a>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $members_pager, 'pager_label' => 'Members']) ?>
                </div>
              </div>

<?php elseif ($tab === 'topups'): ?>
              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-0">Admin top-ups</h5>
                    <p class="text-body-secondary small mb-0">Every credit is a top-up row plus one ledger row plus an audit entry.</p>
                  </div>
                  <form method="GET" action="<?= e(url('admin/wallets')) ?>" class="d-flex flex-wrap gap-2 wallet-search">
                    <input type="hidden" name="tab" value="topups" />
                    <?= per_page_hidden() ?>
                    <input
                      type="search"
                      name="q"
                      class="form-control"
                      value="<?= e($query) ?>"
                      placeholder="Member, admin or note…"
                      aria-label="Search top-ups"
                    />
                    <button type="submit" class="btn btn-label-primary">Search</button>
<?php if ($query !== ''): ?>
                    <a href="<?= e(url('admin/wallets?tab=topups')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Recipient</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">Credited by</th>
                        <th scope="col">Note</th>
                      </tr>
                    </thead>
                    <tbody>
<?php if ($topups === []): ?>
                      <tr>
                        <td colspan="5" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-plus-circle',
        'title' => 'No top-ups yet',
        'text'  => 'Open a member’s wallet to credit it. Every top-up lands here and in the audit log.',
        'action_href'  => 'admin/wallets',
        'action_label' => 'Open the member directory',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($topups as $topup): ?>
                      <tr>
                        <td class="text-nowrap small"><?= e(format_datetime((string) $topup['created_at'])) ?></td>
                        <td>
                          <a href="<?= e(url('admin/wallets/' . (int) $topup['recipient_id'])) ?>" class="text-body fw-medium">
                            <?= e((string) $topup['recipient_username']) ?>
                          </a>
                          <?php if (trim((string) ($topup['recipient_name'] ?? '')) !== ''): ?>
                            <div class="small text-body-secondary"><?= e((string) $topup['recipient_name']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap fw-semibold wallet-amount-positive"><?= e(money((float) $topup['amount'], true)) ?></td>
                        <td><span class="badge bg-label-info"><?= e((string) $topup['admin_username']) ?></span></td>
                        <td class="small text-body-secondary wallet-ref-note"><?= e(trim((string) ($topup['note'] ?? '')) !== '' ? (string) $topup['note'] : '—') ?></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $topups_pager, 'pager_label' => 'Top-ups']) ?>
                </div>
              </div>

<?php else: ?>
<?=
    View::partial('wallet_statement', [
        'filters'         => $ledger_filters,
        'ledger'          => $ledger,
        'pager'           => $ledger_pager,
        'totals'          => $ledger_totals,
        'statement_url'   => url('admin/wallets?tab=ledger'),
        'statement_title' => 'All wallet movements',
        'show_member'     => true,
        'empty_title'     => 'No movements match these filters',
        'empty_text'      => 'Every credit and debit across all wallets appears here, newest first.',
        'hidden_params'   => ['tab' => 'ledger'],
    ])
?>
<?php endif; ?>
            </div>
