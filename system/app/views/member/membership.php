<?php
declare(strict_types=1);

/**
 * Membership page (plan §5.10, §7 Phase 9; reference views/member/activate.php).
 *
 * Pending / inactive members choose one of three activation paths —
 * registration code, e-wallet or a manual proof. Everything else sees
 * their current membership and the activation history.
 *
 * One live surface per member (`member.membership:{id}`). The heading,
 * current-membership card, activation-path block and history are regions;
 * the activation forms are held while the member has unsaved input, so a
 * background refresh never discards a typed code, reference or file.
 *
 * @var array<string, mixed>       $member
 * @var string                     $status
 * @var bool                       $has_package
 * @var bool                       $can_activate
 * @var bool                       $is_standard
 * @var list<array<string, mixed>> $packages         PackageService::publicCards()
 * @var float                      $balance
 * @var float                      $withdrawable
 * @var float|null                 $minimum_price
 * @var array<string, mixed>|null  $open_activation
 * @var list<array<string, mixed>> $history
 * @var Paginator                  $history_pager
 * @var string                     $referral_link
 * @var array<string, mixed>       $channels
 * @var bool                       $free_registration
 * @var string                     $display_name
 * @var string                     $status_label
 * @var string                     $status_tone
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

/* The controller passes these; recompute defensively when a caller
 * renders the template with a raw member row (view tests). */
$has_package  = $has_package ?? ($member['package_id'] !== null);
$status       = $status ?? (string) $member['member_status'];
$can_activate = $can_activate ?? in_array($status, ['pending', 'inactive'], true);
$is_standard  = $is_standard ?? ($status === 'active' && !$has_package);
$sponsorName  = $member['sponsor_username'] ?? null;

$display_name = $display_name ?? (
    trim((string) ($member['full_name'] ?? '')) !== ''
        ? (string) $member['full_name']
        : (string) $member['username']
);

$status_label = $status_label ?? match (true) {
    $status === 'active' && $has_package => 'Active associate',
    $status === 'active'                 => 'Standard member',
    $status === 'pending'                => 'Pending activation',
    $status === 'suspended'              => 'Suspended',
    default                              => ucfirst($status),
};

$status_tone = $status_tone ?? match ($status) {
    'active'    => 'success',
    'pending'   => 'warning',
    'suspended' => 'danger',
    default     => 'secondary',
};

$live = $live ?? [
    'surface'  => 'member.membership:' . (int) ($member['id'] ?? 0),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y membership-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <?= flash_render() ?>

              <div
                class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none"
                data-live-stale
                role="status"
                aria-live="polite"
              >
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>Your membership changed in another tab.</strong>
                  Your unsaved activation input was kept, so this page may be out of date.
                  <a href="<?= e(url('membership')) ?>" class="alert-link" data-live-reload>
                    Reload the page
                  </a>
                  to see the latest.
                </div>
              </div>

<?= View::capture('member/membership/_header', [
    'status'       => $status,
    'status_label' => $status_label,
    'status_tone'  => $status_tone,
]) ?>

<?= View::capture('member/membership/_current', [
    'member'       => $member,
    'display_name' => $display_name,
    'has_package'  => $has_package,
]) ?>

<?= View::capture('member/membership/_paths', [
    'status'            => $status,
    'has_package'       => $has_package,
    'can_activate'      => $can_activate,
    'is_standard'       => $is_standard,
    'packages'          => $packages,
    'balance'           => $balance,
    'withdrawable'      => $withdrawable,
    'minimum_price'     => $minimum_price,
    'open_activation'   => $open_activation,
    'channels'          => $channels,
    'free_registration' => $free_registration,
]) ?>

              <div class="row g-4">
                <!-- Referral -->
                <div class="col-lg-5">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Referral link</h5>
                      <small class="text-body-secondary">
                        Share it or give your code to a recruit.
                        <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                      </small>
                    </div>
                    <div class="card-body">
                      <label class="form-label small text-body-secondary" for="membershipReferral">Link</label>
                      <div class="referral-box">
                        <input
                          type="text"
                          class="form-control referral-link-input"
                          id="membershipReferral"
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
                        ><i class="icon-base bx bx-copy"></i></button>
                      </div>
                      <div class="mt-3 small">
                        Code <strong><?= e((string) ($member['referral_code'] ?? '—')) ?></strong>
                      </div>
                      <div class="mt-2 small text-body-secondary">
                        Sponsor:
                        <?= $sponsorName === null ? 'company' : e('@' . (string) $sponsorName) ?>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- History -->
                <div class="col-lg-7">
<?= View::capture('member/membership/_history', [
    'history'       => $history,
    'history_pager' => $history_pager,
]) ?>
                </div>
              </div>
            </div>
