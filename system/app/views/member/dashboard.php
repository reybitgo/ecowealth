<?php
declare(strict_types=1);

/**
 * Member dashboard (plan §7 Phase 9).
 *
 * Replaces the old standalone "Growth overview": package status, both
 * wallet buckets, the referral link, recent orders and commission
 * totals (zeros until Phase 10 ships the awards).
 *
 * One live surface per member (`member.dashboard:{id}`). Nine regions —
 * the header status pill, the KPI row, the E-wallet card, the membership
 * card, the recent-orders card, the earnings card, the Royalty tile, the
 * Team Harvest tile (Phase 20F) and the General Harvest tile (Phase 21F) —
 * re-render in place; the greeting, the referral card and the static copy
 * stay put.
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
 * @var array<string, mixed>|null  $royalty       the Royalty tile's figures (Phase 19E)
 * @var array<string, mixed>|null  $team_harvest  the Team Harvest tile's figures (Phase 20F)
 * @var array<string, mixed>|null  $general_harvest the General Harvest tile's figures (Phase 21F)
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
<?= View::partial('page_header', [
    'title'       => 'Welcome back, ' . $displayName . ($status === 'active' ? " \u{1F44B}" : ''),
    'description' => 'Your membership, wallet and orders in one place.'
        . ' <span class="small text-body-secondary" data-live-status aria-live="polite"></span>',
    'actions'     => View::capture('member/dashboard/_header', ['member' => $member]),
]) ?>

              <!-- Referral card -->
              <div class="card member-hero mb-6">
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
<?= View::partial('referral_link', [
    'link' => $referral_link,
    'code' => $referral_code,
    'id'   => 'referralLink',
    'note' => 'every signup through this link is recorded in your Team chain.',
]) ?>
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
    'pending_repeat' => $pending_repeat ?? null,
]) ?>

              <!-- Royalty -->
<?= View::capture('member/dashboard/_royalty', ['royalty' => $royalty ?? null]) ?>

              <!-- Team Harvest -->
<?= View::capture('member/dashboard/_team_harvest', ['team_harvest' => $team_harvest ?? null]) ?>

              <!-- General Harvest -->
<?= View::capture('member/dashboard/_general_harvest', ['general_harvest' => $general_harvest ?? null]) ?>

<?= View::capture('member/dashboard/_binary', ['binary' => $binary ?? null]) ?>

              <div class="row g-6 mb-6">
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
<?= View::capture('member/dashboard/_wallet_card', [
    'balance'        => $balance,
    'withdrawable'   => $withdrawable,
    'pending_repeat' => $pending_repeat ?? null,
]) ?>
                </div>
              </div>

              <div class="row g-6">
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
