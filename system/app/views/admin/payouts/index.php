<?php
declare(strict_types=1);

/**
 * Admin payout queue (plan §5.9, §7 Phase 13; reference
 * views/admin/payouts.php).
 *
 * The queue is worked left to right: what is waiting (pending), what has
 * been approved and is being sent off-platform, what is done, what was
 * refused. Each row carries its own action, and every action is one
 * POST with a CSRF token.
 *
 * The page is one live surface. The stat cards, the tab counts and the
 * active tab's rows are regions; the filter form stays outside them so a
 * background refresh never steals focus or discards an unsubmitted
 * search.
 *
 * @var string $tab
 * @var array<string, mixed> $filters
 * @var list<array<string, mixed>> $queue
 * @var Paginator $pager
 * @var array{rows:int, amount:float, fees:float, net:float} $queue_totals
 * @var array{pending:int, approved:int, completed:int, rejected:int, all:int} $tab_counts
 * @var array<string, mixed> $totals
 * @var array<string, mixed> $facts
 * @var string $queue_url
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => Live::ADMIN_PAYOUTS,
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y wallet-monitor payout-queue"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Payouts</h4>
                  <p class="text-body-secondary mb-0">
                    Approve, reject or settle withdrawal requests. The money is held when the member asks, refunded on
                    rejection and never debited twice.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <a href="<?= e(url('admin/wallets')) ?>" class="btn btn-label-primary">
                  <i class="icon-base bx bx-wallet me-1"></i> E-wallet monitor
                </a>
              </div>

<?= View::capture('admin/payouts/_stats', ['totals' => $totals, 'facts' => $facts]) ?>

<?= View::capture('admin/payouts/_tabs', ['tab' => $tab, 'tab_counts' => $tab_counts]) ?>

              <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-0">
<?php if ($tab === ''): ?>
                      All requests
<?php else: ?>
                      <?= e(ucfirst($tab)) ?> requests
<?php endif; ?>
                    </h5>
                    <p class="text-body-secondary small mb-0">
                      <?= (int) $queue_totals['rows'] ?> row<?= (int) $queue_totals['rows'] === 1 ? '' : 's' ?> ·
                      <?= e(money((float) $queue_totals['amount'])) ?> gross ·
                      <?= e(money((float) $queue_totals['fees'])) ?> in fees ·
                      <?= e(money((float) $queue_totals['net'])) ?> net
                    </p>
                  </div>
                  <form method="GET" action="<?= e($queue_url) ?>" class="d-flex flex-wrap gap-2 wallet-search">
                    <input type="hidden" name="status" value="<?= e($tab) ?>" />
                    <?= per_page_hidden() ?>
                    <input
                      type="search"
                      name="q"
                      class="form-control"
                      value="<?= e($filters['q']) ?>"
                      placeholder="Member, account or note…"
                      aria-label="Search payout requests"
                    />
                    <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>" aria-label="From date" />
                    <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>" aria-label="To date" />
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== ''): ?>
                    <a href="<?= e($queue_url . '?status=' . rawurlencode($tab)) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 payout-queue-table">
                    <thead>
                      <tr>
                        <th scope="col">Requested</th>
                        <th scope="col">Member</th>
                        <th scope="col">Method / account</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col" class="text-end">Fee / net</th>
                        <th scope="col" class="text-end">Sends</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Actions</th>
                      </tr>
                    </thead>
<?= View::capture('admin/payouts/_rows', [
    'queue'  => $queue,
    'tab'    => $tab,
    'totals' => $queue_totals,
]) ?>
                  </table>
                </div>
              </div>

<?= View::capture('admin/payouts/_pagination', ['pager' => $pager]) ?>
            </div>
