<?php
declare(strict_types=1);

/**
 * Membership page (plan §5.10, §7 Phase 9; reference views/member/activate.php).
 *
 * Pending / inactive members choose one of three activation paths —
 * registration code, e-wallet or a manual proof. Everything else sees
 * their current membership and the activation history.
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
 * @var string                     $referral_link
 * @var array<string, mixed>       $channels
 * @var bool                       $free_registration
 */

$displayName = trim((string) ($member['full_name'] ?? '')) ?: (string) $member['username'];

/* The controller passes these; recompute defensively when a caller
 * renders the template with a raw member row (view tests). */
$has_package  = $has_package ?? ($member['package_id'] !== null);
$status       = $status ?? (string) $member['member_status'];
$can_activate = $can_activate ?? in_array($status, ['pending', 'inactive'], true);
$is_standard  = $is_standard ?? ($status === 'active' && !$has_package);
$sponsorName  = $member['sponsor_username'] ?? null;

$statusLabel = match (true) {
    $status === 'active' && $has_package => 'Active associate',
    $status === 'active'                 => 'Standard member',
    $status === 'pending'                => 'Pending activation',
    $status === 'suspended'              => 'Suspended',
    default                              => ucfirst($status),
};

$statusTone = match ($status) {
    'active'    => 'success',
    'pending'   => 'warning',
    'suspended' => 'danger',
    default     => 'secondary',
};
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Membership</h4>
                  <p class="text-body-secondary mb-0">
                    Your associate status, package and activation history.
                  </p>
                </div>
                <?= status_badge($status, ['active' => [$statusLabel, $statusTone]]) ?>
              </div>

              <!-- Current membership -->
              <div class="card member-hero mb-4">
                <div class="card-body">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-6">
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
                            · joined <?= e(format_datetime((string) $member['created_at'])) ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="col-lg-6">
                      <div class="row g-3">
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Package</div>
                            <div class="status-tile-value">
                              <?= $has_package ? e((string) $member['package_name']) : 'None' ?>
                            </div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Activated</div>
                            <div class="status-tile-value">
                              <?= e(format_datetime($member['activated_at'] === null ? null : (string) $member['activated_at'])) ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

<?php if ($can_activate && $open_activation === null): ?>
              <!-- Activation paths -->
              <div class="row g-4 mb-4">
                <!-- 1. Registration code -->
                <div class="col-lg-4">
                  <div class="activation-method h-100 d-flex flex-column">
                    <h6><i class="icon-base bx bx-key text-primary"></i> Registration code</h6>
                    <p class="text-body-secondary small mb-3">
                      Redeem the code you received from your sponsor. The package comes from the code.
                    </p>
                    <p class="text-body-secondary small mb-3">
                      No code yet? Your sponsor sells them offline — ask for one, then enter it here.
                    </p>
                    <form method="POST" action="<?= e(url('membership/activate')) ?>" class="mt-auto">
                      <?= csrf_field() ?>
                      <input type="hidden" name="method" value="code" />
                      <label class="form-label" for="act-code">Code</label>
                      <input
                        type="text"
                        class="form-control<?= has_error('reg_code') ? ' is-invalid' : '' ?>"
                        id="act-code"
                        name="reg_code"
                        value="<?= old('reg_code') ?>"
                        placeholder="XXXX-XXXX-XXXX"
                        maxlength="14"
                        autocomplete="off"
                        required
                      />
<?php if (has_error('reg_code')): ?>
                      <div class="invalid-feedback"><?= e(error_for('reg_code')) ?></div>
<?php endif; ?>
                      <button type="submit" class="btn btn-primary w-100 mt-3">
                        <i class="icon-base bx bx-check me-1"></i> Activate with code
                      </button>
                    </form>
                  </div>
                </div>

                <!-- 2. E-wallet -->
                <div class="col-lg-4">
                  <div class="activation-method h-100 d-flex flex-column">
                    <h6><i class="icon-base bx bx-wallet text-success"></i> E-wallet</h6>
                    <p class="text-body-secondary small mb-3">
                      Pay the package price from your own wallet. Balance:
                      <strong><?= e(money($balance)) ?></strong>
                      (<?= e(money($withdrawable)) ?> withdrawable).
                    </p>
<?php if ($packages === []): ?>
                    <div class="alert alert-warning py-2 small mb-0 mt-auto">
                      No package is available for activation right now.
                    </div>
<?php else: ?>
                    <form method="POST" action="<?= e(url('membership/activate')) ?>" class="mt-auto">
                      <?= csrf_field() ?>
                      <input type="hidden" name="method" value="ewallet" />
                      <label class="form-label" for="act-package">Package</label>
                      <select
                        class="form-select<?= has_error('package_id') ? ' is-invalid' : '' ?>"
                        id="act-package"
                        name="package_id"
                        required
                      >
                        <option value="">Choose a package…</option>
<?php foreach ($packages as $package): ?>
                        <option
                          value="<?= (int) $package['id'] ?>"
<?= (string) old('package_id') === (string) $package['id'] ? ' selected' : '' ?>
                        ><?= e((string) $package['name']) ?> — <?= e(money((float) $package['price'])) ?></option>
<?php endforeach; ?>
                      </select>
<?php if (has_error('package_id')): ?>
                      <div class="invalid-feedback"><?= e(error_for('package_id')) ?></div>
<?php endif; ?>
                      <button
                        type="submit"
                        class="btn btn-primary w-100 mt-3"
                        <?= $balance < (float) ($minimum_price ?? 0) ? 'disabled title="Top up your e-wallet first"' : '' ?>
                      >
                        <i class="icon-base bx bx-credit-card me-1"></i> Pay from e-wallet
                      </button>
<?php if ($balance < (float) ($minimum_price ?? 0)): ?>
                      <div class="form-text text-danger mt-2">
                        Your balance does not cover the cheapest package (<?= e(money((float) ($minimum_price ?? 0))) ?>).
                        <a href="<?= e(url('wallet')) ?>">Open the e-wallet</a>.
                      </div>
<?php endif; ?>
                    </form>
<?php endif; ?>
                  </div>
                </div>

                <!-- 3. Manual proof -->
                <div class="col-lg-4">
                  <div class="activation-method h-100 d-flex flex-column">
                    <h6><i class="icon-base bx bx-receipt text-warning"></i> Payment proof</h6>
                    <p class="text-body-secondary small mb-3">
                      Send the package price to a company account, then upload the proof for review.
                    </p>
                    <form
                      method="POST"
                      action="<?= e(url('membership/activate')) ?>"
                      enctype="multipart/form-data"
                      class="mt-auto"
                    >
                      <?= csrf_field() ?>
                      <input type="hidden" name="method" value="manual" />

                      <label class="form-label" for="act-channel">Channel</label>
                      <select
                        class="form-select<?= has_error('channel') ? ' is-invalid' : '' ?>"
                        id="act-channel"
                        name="channel"
                        required
                      >
                        <option value="gcash" <?= old('channel') === 'gcash' ? 'selected' : '' ?>>GCash</option>
                        <option value="maya" <?= old('channel') === 'maya' ? 'selected' : '' ?>>Maya</option>
                        <option value="bank" <?= old('channel') === 'bank' ? 'selected' : '' ?>>Bank transfer</option>
                      </select>
<?php if (has_error('channel')): ?>
                      <div class="invalid-feedback"><?= e(error_for('channel')) ?></div>
<?php endif; ?>

                      <label class="form-label mt-2" for="act-package-manual">Package</label>
                      <select
                        class="form-select<?= has_error('package_id') ? ' is-invalid' : '' ?>"
                        id="act-package-manual"
                        name="package_id"
                        required
                      >
                        <option value="">Choose a package…</option>
<?php foreach ($packages as $package): ?>
                        <option value="<?= (int) $package['id'] ?>"><?= e((string) $package['name']) ?> — <?= e(money((float) $package['price'])) ?></option>
<?php endforeach; ?>
                      </select>

                      <label class="form-label mt-2" for="act-reference">Reference number</label>
                      <input
                        type="text"
                        class="form-control<?= has_error('reference_no') ? ' is-invalid' : '' ?>"
                        id="act-reference"
                        name="reference_no"
                        value="<?= old('reference_no') ?>"
                        maxlength="40"
                        required
                      />
<?php if (has_error('reference_no')): ?>
                      <div class="invalid-feedback"><?= e(error_for('reference_no')) ?></div>
<?php endif; ?>

                      <label class="form-label mt-2" for="act-proof">Proof (JPG, PNG, WebP or PDF)</label>
                      <input
                        type="file"
                        class="form-control<?= has_error('proof_image') ? ' is-invalid' : '' ?>"
                        id="act-proof"
                        name="proof"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        required
                      />
<?php if (has_error('proof_image')): ?>
                      <div class="invalid-feedback"><?= e(error_for('proof_image')) ?></div>
<?php endif; ?>

                      <button type="submit" class="btn btn-primary w-100 mt-3">
                        <i class="icon-base bx bx-upload me-1"></i> Submit for review
                      </button>
                    </form>
                  </div>
                </div>
              </div>

              <!-- Receiving accounts -->
              <div class="card mb-4">
                <div class="card-header">
                  <h5 class="card-title mb-0">Company receiving accounts</h5>
                  <small class="text-body-secondary">Use these for the payment-proof path.</small>
                </div>
                <div class="card-body">
                  <div class="row g-3">
                    <div class="col-md-4">
                      <div class="status-tile">
                        <div class="status-tile-label">GCash</div>
                        <div class="status-tile-value"><?= e($channels['gcash']['number'] !== '' ? (string) $channels['gcash']['number'] : 'Not configured') ?></div>
                        <small class="text-body-secondary"><?= e((string) $channels['gcash']['name']) ?></small>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="status-tile">
                        <div class="status-tile-label">Maya</div>
                        <div class="status-tile-value"><?= e($channels['maya']['number'] !== '' ? (string) $channels['maya']['number'] : 'Not configured') ?></div>
                        <small class="text-body-secondary"><?= e((string) $channels['maya']['name']) ?></small>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="status-tile">
                        <div class="status-tile-label">Bank transfer</div>
                        <div class="status-tile-value"><?= e($channels['bank']['bank'] !== '' ? (string) $channels['bank']['bank'] : 'Not configured') ?></div>
                        <small class="text-body-secondary">
                          <?= e((string) $channels['bank']['account_name']) ?>
                          <?= $channels['bank']['account_number'] !== '' ? ' · ' . e((string) $channels['bank']['account_number']) : '' ?>
                        </small>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
<?php elseif ($open_activation !== null): ?>
              <div class="alert alert-info d-flex align-items-start gap-2 mb-4" role="alert">
                <i class="icon-base bx bx-time-five fs-4"></i>
                <div>
                  <strong>Activation in progress.</strong>
                  <div class="small">
                    Your <?= e(str_replace('_', ' ', (string) $open_activation['status'])) ?> request for
                    <?= e((string) $open_activation['package_name']) ?> (<?= e(money((float) $open_activation['amount'])) ?>)
                    was created <?= e(format_datetime((string) $open_activation['created_at'])) ?>.
                    <?= (string) $open_activation['status'] === 'pending_review'
                        ? 'An administrator will review the payment proof.'
                        : 'Complete the payment to finish the activation.' ?>
                  </div>
                </div>
              </div>
<?php elseif ($status === 'suspended'): ?>
              <div class="alert alert-danger d-flex align-items-start gap-2 mb-4" role="alert">
                <i class="icon-base bx bx-block fs-4"></i>
                <div class="small">
                  This account is suspended, so it cannot be activated. Please contact support.
                </div>
              </div>
<?php elseif ($is_standard): ?>
              <div class="alert alert-info d-flex align-items-start gap-2 mb-4" role="alert">
                <i class="icon-base bx bx-store fs-4"></i>
                <div class="small">
                  <strong>Standard membership.</strong>
                  Shopping and your wallet are fully open; no associate package is attached, so no referral
                  commissions are earned. Self-service activation applies to pending accounts — contact support
                  to add a package to this account.
                </div>
              </div>
<?php elseif ($has_package): ?>
              <div class="alert alert-success d-flex align-items-start gap-2 mb-4" role="alert">
                <i class="icon-base bx bx-check-shield fs-4"></i>
                <div class="small">
                  <strong>Active associate.</strong>
                  Your referral link below is live, and every activation in your team is recorded on the genealogy page.
                </div>
              </div>
<?php endif; ?>

              <div class="row g-4">
                <!-- Referral -->
                <div class="col-lg-5">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Referral link</h5>
                      <small class="text-body-secondary">Share it or give your code to a recruit.</small>
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
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Activation history</h5>
                      <small class="text-body-secondary">Every request, in order.</small>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Package</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                            <th>When</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($history === []): ?>
                          <tr>
                            <td colspan="5" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-medal',
        'title' => 'No activation requests yet',
        'text'  => 'Requests appear here as soon as you activate a package.',
    ]);
?>
                            </td>
                          </tr>
<?php endif; ?>
<?php foreach ($history as $activation): ?>
                          <tr>
                            <td>
                              <span class="text-nowrap"><?= e((string) $activation['package_name']) ?></span>
                              <div class="member-meta">#<?= (int) $activation['id'] ?></div>
                            </td>
                            <td><?= e(payment_method_label((string) $activation['payment_method'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(money((float) $activation['amount'])) ?></td>
                            <td>
                              <?= status_badge((string) $activation['status']) ?>
<?php if ((string) $activation['status'] === 'rejected' && (string) ($activation['reject_reason'] ?? '') !== ''): ?>
                              <div class="member-meta"><?= e((string) $activation['reject_reason']) ?></div>
<?php endif; ?>
                            </td>
                            <td class="text-nowrap"><span class="member-meta"><?= e(format_datetime((string) $activation['created_at'])) ?></span></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php if (isset($history_pager) && $history_pager instanceof Paginator): ?>
                    <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $history_pager, 'pager_label' => 'Requests']) ?>
                    </div>
<?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
