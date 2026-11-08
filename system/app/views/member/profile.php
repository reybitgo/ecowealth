<?php
declare(strict_types=1);

/**
 * Member profile + address book (plan §5.3, Phase 6; reference
 * views/member/profile.php).
 *
 * The address book saves the delivery details checkout uses. Editing
 * an address reopens the same form; one address is the default and
 * checkout pre-fills from it.
 *
 * One live surface per member (`member.profile:{id}`). The full-name,
 * payout-account and address forms are held regions, so a background
 * refresh never discards typed input; the address-book list is replaced
 * freely, and an `edit` id that no longer exists drops out of the URL.
 *
 * @var array<string, mixed> $user
 * @var list<array<string, mixed>> $addresses
 * @var array<string, mixed>|null $editing
 * @var array{gcash_number:string, maya_number:string, usdt_trc20_address:string, usdt_bep20_address:string} $payments
 * @var array<string, mixed> $posted
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$posted   = $posted ?? [];
$editing  = $editing ?? null;
$addresses = $addresses ?? [];
$payments = $payments ?? [
    'gcash_number'       => '',
    'maya_number'        => '',
    'usdt_trc20_address' => '',
    'usdt_bep20_address' => '',
];

$live = $live ?? [
    'surface'  => Live::profileSurface((int) ($user['id'] ?? 0)),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y profile-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none" data-live-stale role="status" aria-live="polite">
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>Your profile changed in another tab.</strong>
                  Your unsaved input was kept, so this page may be out of date.
                  <a href="<?= e(url('profile')) ?>" class="alert-link" data-live-reload>
                    Reload the page
                  </a>
                  to see the latest.
                </div>
              </div>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Profile</h4>
                  <p class="text-body-secondary mb-0">
                    Your account details, payout accounts and the addresses checkout pre-fills.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e(url('wallet')) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> E-wallet
                  </a>
                  <a href="<?= e(url('profile/password')) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-lock-alt me-1"></i> Change password
                  </a>
                </div>
              </div>

              <div class="row g-6">
                <div class="col-lg-4">
                  <div class="card mb-6">
                    <div class="card-body text-center">
                      <div class="avatar avatar-xl mx-auto mb-3">
                        <span class="avatar-initial rounded-circle bg-label-primary fs-4"><?= e(initials((string) $user['username'])) ?></span>
                      </div>
                      <h5 class="mb-1"><?= e((string) $user['username']) ?></h5>
                      <p class="text-body-secondary small mb-3"><?= e((string) $user['email']) ?></p>
                      <div class="d-flex justify-content-center gap-2">
                        <span class="badge bg-label-info"><?= e(ucfirst((string) $user['role'])) ?></span>
                        <span class="badge bg-label-secondary">Member since <?= e(date('M Y', (int) strtotime((string) $user['created_at']))) ?></span>
                      </div>
                    </div>
                  </div>

                  <div class="card">
                    <div class="card-header"><h5 class="mb-0">Account details</h5></div>
<?= View::capture('member/profile/_name', ['user' => $user]) ?>
                  </div>
                </div>

                <div class="col-lg-8">
                  <div class="card mb-6" id="payout-details">
                    <div class="card-header">
                      <h5 class="mb-1">Payout accounts</h5>
                      <p class="text-body-secondary small mb-0">
                        Where the company sends your withdrawals. The GCash, Maya and bank details shown on an
                        order are the company's receiving accounts — these are your own payout accounts.
                      </p>
                    </div>
<?= View::capture('member/profile/_payments', [
    'payments' => $payments,
    'posted'   => $posted,
]) ?>
                  </div>

<?= View::capture('member/profile/_addresses', ['addresses' => $addresses]) ?>

<?= View::capture('member/profile/_address_form', [
    'addresses' => $addresses,
    'editing'   => $editing,
    'posted'    => $posted,
]) ?>
                </div>
              </div>
            </div>
