<?php
declare(strict_types=1);

/**
 * Member dashboard (plan §7 Phase 9).
 *
 * Replaces the old standalone "Growth overview": package status, both
 * wallet buckets, the referral link, recent orders and commission
 * totals (zeros until Phase 10 ships the awards).
 *
 * One live surface per member (`member.dashboard:{id}`). Five regions —
 * the KPI row, the E-wallet card, the membership card, the recent-orders
 * card and the earnings card — re-render in place; the greeting, the
 * referral card and the static copy stay put.
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
$displayName  = trim((string) ($member['full_name'] ?? '')) ?: (string) $member['username'];
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
<?= View::capture('member/dashboard/_header', ['member' => $member]) ?>
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
<?= View::capture('member/dashboard/_kpis', [
    'member'      => $member,
    'commissions' => $commissions,
    'team'        => $team,
    'balance'     => $balance,
    'withdrawable' => $withdrawable,
]) ?>

              <div class="row g-4 mb-4">
                <!-- Membership -->
                <div class="col-xl-6">
<?= View::capture('member/dashboard/_membership', [
    'member'           => $member,
    'open_activation'  => $open_activation,
    'activation_count' => $activation_count,
]) ?>
                </div>

                <!-- Wallet -->
                <div class="col-xl-6">
<?= View::capture('member/dashboard/_wallet_card', ['balance' => $balance, 'withdrawable' => $withdrawable]) ?>
                </div>
              </div>

              <div class="row g-4">
                <!-- Recent orders -->
                <div class="col-xl-8">
<?= View::capture('member/dashboard/_orders', [
    'orders'      => $orders,
    'order_stats' => $order_stats,
]) ?>
                </div>

                <!-- Earnings / team -->
                <div class="col-xl-4">
<?= View::capture('member/dashboard/_earnings', [
    'commissions'    => $commissions,
    'team'           => $team,
    'recent_history' => $recent_history,
    'unilevel'       => $unilevel ?? null,
]) ?>
                </div>
              </div>
            </div>
