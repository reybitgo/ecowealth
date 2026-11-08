<?php
declare(strict_types=1);

/**
 * Admin member detail (plan §7 Phase 9; reference views/admin/user_view.php).
 *
 * Everything the back office needs about one membership in one place:
 * account, membership, wallet, orders, team and the audit trail.
 *
 * One live surface per member (`admin.members:{id}`). The identity bar, the
 * three summary cards, the activation history and both side cards are
 * regions; a background refresh on one member never touches another
 * member's open page.
 *
 * @var array<string, mixed>       $member
 * @var int                        $member_id
 * @var string                     $display_name
 * @var string                     $status_label
 * @var bool                       $is_suspended
 * @var string                     $referral_link
 * @var float                      $balance
 * @var float                      $withdrawable
 * @var list<array<string, mixed>> $activations
 * @var Paginator                  $activations_pager
 * @var array{total:int, open:int, completed:int, spent:float} $orders
 * @var array{count:int, credited:float, this_month:float, last_at:?string} $commissions
 * @var array{direct:int, team:int, levels:int} $team
 * @var list<array<string, mixed>> $upline
 * @var list<array<string, mixed>> $audits
 * @var string                     $statement_url
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$live = $live ?? [
    'surface'  => 'admin.members:' . (int) ($member['id'] ?? 0),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

/* The controller hands these in; a direct view render (a test, a future
 * caller) still gets the same values from the member row alone. */
$member_id   = (int) ($member_id ?? $member['id'] ?? 0);
$display_name = $display_name ?? (
    trim((string) ($member['full_name'] ?? '')) !== ''
        ? (string) $member['full_name']
        : (string) $member['username']
);

$status = (string) ($member['member_status'] ?? '');

$status_label = $status_label ?? match (true) {
    $status === 'active' && $member['package_id'] !== null => 'Active associate',
    $status === 'active'                                  => 'Standard member',
    $status === 'pending'                                 => 'Pending activation',
    $status === 'suspended'                               => 'Suspended',
    default                                               => ucfirst($status),
};

$is_suspended  = $is_suspended ?? ($status === 'suspended');
$referral_link = $referral_link ?? ($member['referral_code'] === null
    ? ''
    : absolute_url('register?ref=' . rawurlencode((string) $member['referral_code'])));
?>
            <div
              class="container-xxl flex-grow-1 container-p-y member-profile"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

              <div class="text-end">
                <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
              </div>

<?= View::capture('admin/members/_heading', [
    'member'        => $member,
    'member_id'     => $member_id,
    'display_name'  => $display_name,
    'status_label'  => $status_label,
    'is_suspended'  => $is_suspended,
    'statement_url' => $statement_url,
]) ?>

              <div class="row g-4 mb-4">
                <!-- Account -->
                <div class="col-xl-4">
<?= View::capture('admin/members/_account', [
    'member'        => $member,
    'display_name'  => $display_name,
    'referral_link' => $referral_link,
]) ?>
                </div>

                <!-- Membership -->
                <div class="col-xl-4">
<?= View::capture('admin/members/_membership', [
    'member'       => $member,
    'status_label' => $status_label,
    'team'         => $team,
]) ?>
                </div>

                <!-- Wallet -->
                <div class="col-xl-4">
<?= View::capture('admin/members/_wallet', [
    'member'        => $member,
    'balance'       => $balance,
    'withdrawable'  => $withdrawable,
    'orders'        => $orders,
    'commissions'   => $commissions,
    'activations'   => $activations,
    'statement_url' => $statement_url,
]) ?>
                </div>
              </div>

              <div class="row g-4">
                <!-- Activations -->
                <div class="col-xl-8">
<?= View::capture('admin/members/_activations', [
    'activations'       => $activations,
    'activations_pager' => $activations_pager,
]) ?>
                </div>

                <!-- Upline + audit -->
                <div class="col-xl-4">
<?= View::capture('admin/members/_upline', ['upline' => $upline]) ?>

<?= View::capture('admin/members/_audit', ['audits' => $audits]) ?>
                </div>
              </div>
            </div>
