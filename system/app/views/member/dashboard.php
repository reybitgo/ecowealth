<?php
declare(strict_types=1);

/**
 * Member dashboard (plan §7 Phase 9).
 *
 * Replaces the old standalone "Growth overview": package status, both
 * wallet buckets, the referral link, recent orders and commission
 * totals (zeros until Phase 10 ships the awards).
 *
 * @var array<string, mixed>       $member
 * @var float                      $balance
 * @var float                      $withdrawable
 * @var list<array<string, mixed>> $orders
 * @var array{total:int, open:int, completed:int, spent:float} $order_stats
 * @var array{count:int, credited:float, this_month:float, last_at:?string} $commissions
 * @var array{direct:int, team:int, levels:int} $team
 * @var array<string, mixed>|null  $open_activation
 * @var array<string, mixed>|null  $last_activation
 * @var int                        $activation_count
 * @var list<array<string, mixed>> $recent_history
 * @var string                     $referral_code
 * @var string                     $referral_link
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => Live::memberDashboardSurface((int) ($member['id'] ?? 0)),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$status       = (string) $member['member_status'];
$hasPackage   = $member['package_id'] !== null;
$displayName  = trim((string) ($member['full_name'] ?? '')) ?: (string) $member['username'];

$statusTone = match ($status) {
    'active'    => 'success',
    'pending'   => 'warning',
    'suspended' => 'danger',
    default     => 'secondary',
};

$statusLabel = match (true) {
    $status === 'active' && $hasPackage => 'Active associate',
    $status === 'active'                => 'Standard member',
    $status === 'pending'               => 'Pending activation',
    $status === 'suspended'             => 'Suspended',
    default                             => ucfirst($status),
};

$kpis = [
    [
        'label'  => 'Membership',
        'value'  => $hasPackage ? (string) ($member['package_name'] ?? 'Package') : 'No package',
        'sub'    => $statusLabel,
        'icon'   => $hasPackage ? 'bx-medal' : 'bx-user-check',
        'tone'   => $hasPackage ? 'primary' : 'info',
    ],
    [
        'label'  => 'Commissions',
        'value'  => money($commissions['credited']),
        'sub'    => $commissions['count'] === 0
            ? 'No awards yet'
            : money($commissions['this_month']) . ' this month',
        'icon'   => 'bx-line-chart',
        'tone'   => 'warning',
    ],
    [
        'label'  => 'Team',
        'value'  => (string) $team['team'],
        'sub'    => $team['direct'] . ' direct · ' . $team['levels'] . ' level' . ($team['levels'] === 1 ? '' : 's'),
        'icon'   => 'bx-git-branch',
        'tone'   => 'info',
    ],
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

              <div class="d-flex align-items-start align-items-sm-center justify-content-between flex-column flex-sm-row gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Welcome back, <?= e($displayName) ?><?= $status === 'active' ? ' &#x1f44b;' : '' ?></h4>
                  <p class="text-body-secondary mb-0">
                    Your membership, wallet and orders in one place.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <?= status_badge($status, [
                      'active'    => [$statusLabel, $statusTone],
                      'pending'   => ['Pending activation', $statusTone],
                      'suspended' => ['Suspended', $statusTone],
                  ]) ?>
                  <a href="<?= e(url('shop')) ?>" class="btn btn-sm btn-primary">
                    <i class="icon-base bx bx-store me-1"></i> Shop
                  </a>
                </div>
              </div>

              <!-- Referral card -->
              <div class="card member-hero mb-4">
                <div class="card-body">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-5">
                      <div class="d-flex align-items-center gap-3">
                        <div class="avatar avatar-lg">
                          <span class="avatar-initial rounded-circle bg-label-primary fw-semibold fs-4">
                            <?= e(initials((string) $member['username'])) ?>
                          </span>
                        </div>
                        <div>
                          <h5 class="mb-1"><?= e($displayName) ?></h5>
                          <div class="text-body-secondary small">
                            @<?= e((string) $member['username']) ?>
                            · sponsored by
                            <?= $member['sponsor_username'] === null
                                ? '<span class="text-body-secondary">company</span>'
                                : e('@' . (string) $member['sponsor_username']) ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="col-lg-7">
                      <label class="form-label small text-body-secondary mb-1" for="referralLink">
                        Your referral link
                      </label>
                      <div class="referral-box">
                        <input
                          type="text"
                          class="form-control referral-link-input"
                          id="referralLink"
                          value="<?= e($referral_link) ?>"
                          readonly
                          onfocus="this.select()"
                        />
                        <button
                          type="button"
                          class="btn btn-icon btn-text-secondary"
                          data-copy="<?= e($referral_link) ?>"
                          data-copy-tooltip="Copy link"
                          data-bs-toggle="tooltip"
                          title="Copy link"
                          aria-label="Copy referral link"
                        >
                          <i class="icon-base bx bx-copy"></i>
                        </button>
                      </div>
                      <div class="small text-body-secondary mt-2">
                        Referral code <strong><?= e($referral_code) ?></strong> · every signup through this link is
                        recorded under your sponsor chain.
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- KPIs -->
              <div class="row g-4 mb-4">
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[0]]) ?>
<?= View::capture('member/dashboard/_wallet_kpi', ['balance' => $balance, 'withdrawable' => $withdrawable]) ?>
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[1]]) ?>
<?= View::capture('member/dashboard/_kpi', ['kpi' => $kpis[2]]) ?>
              </div>

              <div class="row g-4 mb-4">
                <!-- Membership -->
                <div class="col-xl-6">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">Membership</h5>
                      <a href="<?= e(url('membership')) ?>" class="btn btn-sm btn-label-primary">Manage</a>
                    </div>
                    <div class="card-body">
<?php if ($hasPackage): ?>
                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-3 border-bottom">
                        <div>
                          <h5 class="mb-0"><?= e((string) $member['package_name']) ?></h5>
                          <small class="text-body-secondary">Associate package</small>
                        </div>
                        <span class="badge bg-label-success">Active</span>
                      </div>
<?php elseif ($status === 'pending'): ?>
                      <div class="alert alert-warning d-flex align-items-start gap-2 py-3" role="alert">
                        <i class="icon-base bx bx-time-five fs-5"></i>
                        <div class="small">
                          Your account is waiting for activation.
<?php if ($open_activation !== null): ?>
                          A <strong><?= e(str_replace('_', ' ', (string) $open_activation['status'])) ?></strong>
                          request for <?= e((string) $open_activation['package_name']) ?> is with the review team.
<?php else: ?>
                          Choose a package, redeem a code or upload a payment proof on the membership page.
<?php endif; ?>
                        </div>
                      </div>
<?php elseif ($status === 'suspended'): ?>
                      <div class="alert alert-danger d-flex align-items-start gap-2 py-3" role="alert">
                        <i class="icon-base bx bx-block fs-5"></i>
                        <div class="small">This account is suspended. Contact support to restore it.</div>
                      </div>
<?php else: ?>
                      <div class="alert alert-info d-flex align-items-start gap-2 py-3" role="alert">
                        <i class="icon-base bx bx-store fs-5"></i>
                        <div class="small">
                          You have a standard membership: shopping is open, and no package is attached.
                          Contact support to add an associate package.
                        </div>
                      </div>
<?php endif; ?>

                      <div class="row g-3">
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Member since</div>
                            <div class="status-tile-value"><?= e(format_datetime((string) $member['created_at'])) ?></div>
                          </div>
                        </div>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Activated</div>
                            <div class="status-tile-value"><?= e(format_datetime($member['activated_at'] === null ? null : (string) $member['activated_at'])) ?></div>
                          </div>
                        </div>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Sponsor</div>
                            <div class="status-tile-value">
                              <?= $member['sponsor_username'] === null ? '—' : e('@' . (string) $member['sponsor_username']) ?>
                            </div>
                          </div>
                        </div>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Activation requests</div>
                            <div class="status-tile-value"><?= (int) $activation_count ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Wallet -->
                <div class="col-xl-6">
<?= View::capture('member/dashboard/_wallet_card', ['balance' => $balance, 'withdrawable' => $withdrawable]) ?>
                </div>
              </div>

              <div class="row g-4">
                <!-- Recent orders -->
                <div class="col-xl-8">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="card-title mb-0">Recent orders</h5>
                        <small class="text-body-secondary">
                          <?= (int) $order_stats['total'] ?> order<?= $order_stats['total'] === 1 ? '' : 's' ?>
                          · <?= (int) $order_stats['open'] ?> open
                          · <?= e(money($order_stats['spent'])) ?> paid
                        </small>
                      </div>
                      <a href="<?= e(url('orders')) ?>" class="btn btn-sm btn-label-primary">All orders</a>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Order</th>
                            <th>Placed</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($orders === []): ?>
                          <tr>
                            <td colspan="4" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-receipt',
        'title'        => 'No orders yet',
        'text'         => 'Your product orders will appear here once you check out.',
        'action_href'  => 'shop',
        'action_label' => 'Browse the shop',
    ]);
?>
                            </td>
                          </tr>
<?php endif; ?>
<?php foreach ($orders as $order): ?>
                          <tr>
                            <td>
                              <a href="<?= e(url('orders/' . (int) $order['id'])) ?>" class="text-body fw-medium">
                                <?= e((string) $order['order_no']) ?>
                              </a>
                              <div class="member-meta">
                                <?= (int) ($order['item_count'] ?? 0) ?> item<?= (int) ($order['item_count'] ?? 0) === 1 ? '' : 's' ?>
                              </div>
                            </td>
                            <td class="text-nowrap">
                              <span class="member-meta"><?= e(format_datetime((string) $order['created_at'])) ?></span>
                            </td>
                            <td class="text-end text-nowrap"><?= e(money((float) $order['total'])) ?></td>
                            <td><?= status_badge((string) $order['status']) ?></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <!-- Earnings / team -->
                <div class="col-xl-4">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">Earnings</h5>
                      <div class="d-flex gap-2">
                        <a href="<?= e(url('genealogy')) ?>" class="btn btn-sm btn-label-secondary">Genealogy</a>
                        <a href="<?= e(url('earnings')) ?>" class="btn btn-sm btn-label-primary">All awards</a>
                      </div>
                    </div>
                    <div class="card-body">
                      <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="avatar">
                          <span class="avatar-initial rounded bg-label-warning">
                            <i class="icon-base bx bx-line-chart"></i>
                          </span>
                        </span>
                        <div>
                          <div class="h4 mb-0"><?= e(money($commissions['credited'])) ?></div>
                          <small class="text-body-secondary">Total credited</small>
                        </div>
                      </div>

                      <ul class="list-unstyled mb-0 small">
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">This month</span>
                          <strong><?= e(money($commissions['this_month'])) ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Awards</span>
                          <strong><?= (int) $commissions['count'] ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Direct recruits</span>
                          <strong><?= (int) $team['direct'] ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1">
                          <span class="text-body-secondary">Team size</span>
                          <strong><?= (int) $team['team'] ?></strong>
                        </li>
                      </ul>

<?php if ($recent_history !== []): ?>
                      <hr class="my-3" />
                      <h6 class="mb-2">Recent activations</h6>
                      <ul class="list-unstyled mb-0 small">
<?php foreach ($recent_history as $activation): ?>
                        <li class="d-flex align-items-center justify-content-between gap-2 py-1">
                          <span class="text-truncate">
                            <?= e((string) $activation['package_name']) ?>
                            <span class="text-body-secondary d-block member-meta">
                              <?= e(format_datetime((string) $activation['created_at'])) ?>
                            </span>
                          </span>
                          <?= status_badge((string) $activation['status']) ?>
                        </li>
<?php endforeach; ?>
                      </ul>
<?php else: ?>
                      <div class="alert alert-info py-2 small mb-0 mt-3">
                        No commissions yet. A direct referral is credited the moment someone you sponsored
                        activates a package — <a href="<?= e(url('earnings')) ?>">see earnings</a>.
                      </div>
<?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
