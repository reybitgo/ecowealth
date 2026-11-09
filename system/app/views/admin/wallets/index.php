<?php
declare(strict_types=1);

/**
 * Admin e-wallet monitor (plan §5.8, §7 Phase 5 + Phase 12; reference
 * views/admin/ewallet_monitor.php).
 *
 * Four numbers matter at a glance — total float, how much of it is
 * withdrawable, how much is locked to shop spending, and today's
 * movement — then four tabs: the member directory, every member
 * transfer, every admin top-up, and every ledger movement across all
 * wallets.
 *
 * The page is one live surface. The metric badges, stat cards, tab counts
 * and the active tab's rows are regions; the search/filter forms stay
 * outside them so a background refresh never steals focus or discards an
 * unsubmitted search. The ledger tab's statement card includes its filter
 * form, so it is marked `data-live-hold="dirty"` and the page shows a
 * stale banner instead of overwriting typed filters.
 *
 * @var array{
 *   total: float, withdrawable: float, locked: float, funded_members: int,
 *   topups: int, topup_total: float, topups_today: int, topup_today_total: float,
 *   transfers: int, transfer_volume: float, transfer_fees: float,
 *   transfers_today: int, transfer_today_volume: float,
 *   credits_today: float, debits_today: float
 * } $totals
 * @var string $tab
 * @var array<string, int> $tab_counts
 * @var string $query
 * @var string $funded
 * @var string $status
 * @var list<array<string, mixed>> $members
 * @var Paginator $members_pager
 * @var list<array<string, mixed>> $transfers
 * @var Paginator $transfers_pager
 * @var array{q:string, from:string, to:string} $transfer_filters
 * @var list<array<string, mixed>> $topups
 * @var Paginator $topups_pager
 * @var list<array<string, mixed>> $ledger
 * @var Paginator $ledger_pager
 * @var array{rows:int, credits:float, debits:float, net:float} $ledger_totals
 * @var array{type:string, ref_type:string, from:string, to:string, q:string} $ledger_filters
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => 'admin.wallets',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y wallet-monitor"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">E-wallet monitor</h4>
                  <p class="text-body-secondary mb-0">
                    The float, both buckets and every movement. Top-ups are audited; ledger rows are immutable.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
<?= View::capture('admin/wallets/_metrics', ['totals' => $totals]) ?>
              </div>

              <div
                class="alert alert-warning d-flex align-items-start gap-2 mb-6 d-none"
                data-live-stale
                role="status"
                aria-live="polite"
              >
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>The wallet data changed in another tab.</strong>
                  Your unsaved filters were kept, so the ledger may be out of date.
                  <a href="<?= e(url('admin/wallets')) ?>" class="alert-link" data-live-reload>
                    Reload the monitor
                  </a>
                  to see the latest.
                </div>
              </div>

<?= View::capture('admin/wallets/_stats', ['totals' => $totals]) ?>

<?= View::capture('admin/wallets/_tabs', ['tab' => $tab, 'tab_counts' => $tab_counts]) ?>

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
<?= View::capture('admin/wallets/_members', ['members' => $members]) ?>
                  </table>
                </div>

<?= View::capture('admin/wallets/_members_pagination', ['members_pager' => $members_pager]) ?>
              </div>

<?php elseif ($tab === 'transfers'): ?>
              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-0">Transfers</h5>
                    <p class="text-body-secondary small mb-0">
                      Member-to-member movements. Each one is one transfer row and three ledger rows
                      (sender debit, recipient credit, admin fee).
                    </p>
                  </div>
                  <form method="GET" action="<?= e(url('admin/wallets')) ?>" class="d-flex flex-wrap gap-2 wallet-search">
                    <input type="hidden" name="tab" value="transfers" />
                    <?= per_page_hidden() ?>
                    <input
                      type="search"
                      name="q"
                      class="form-control"
                      value="<?= e($query) ?>"
                      placeholder="Sender, recipient or note…"
                      aria-label="Search transfers"
                    />
                    <input type="date" name="from" class="form-control" value="<?= e($transfer_filters['from']) ?>" aria-label="From date" />
                    <input type="date" name="to" class="form-control" value="<?= e($transfer_filters['to']) ?>" aria-label="To date" />
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($query !== '' || $transfer_filters['from'] !== '' || $transfer_filters['to'] !== ''): ?>
                    <a href="<?= e(url('admin/wallets?tab=transfers')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Sender</th>
                        <th scope="col"><span class="visually-hidden">Direction</span></th>
                        <th scope="col">Recipient</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Fee</th>
                        <th scope="col">Note</th>
                      </tr>
                    </thead>
<?= View::capture('admin/wallets/_transfers', ['transfers' => $transfers]) ?>
                  </table>
                </div>

<?= View::capture('admin/wallets/_transfers_pagination', ['transfers_pager' => $transfers_pager]) ?>
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
<?= View::capture('admin/wallets/_topups', ['topups' => $topups]) ?>
                  </table>
                </div>

<?= View::capture('admin/wallets/_topups_pagination', ['topups_pager' => $topups_pager]) ?>
              </div>

<?php else: ?>
<?= View::capture('admin/wallets/_ledger', [
    'ledger_filters' => $ledger_filters,
    'ledger'         => $ledger,
    'ledger_pager'   => $ledger_pager,
    'ledger_totals'  => $ledger_totals,
]) ?>
<?php endif; ?>
            </div>
