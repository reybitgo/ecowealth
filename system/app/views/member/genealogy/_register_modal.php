<?php
declare(strict_types=1);

/**
 * Binary tree — in-node registration modal (Phase 22G-S4).
 *
 * A Bootstrap modal that posts to the existing register.php with the
 * placement fields locked to this slot. The upline and side are submitted
 * as hidden inputs; every other field is the standard registration form.
 * Without JavaScript the + link in _tree_actions.php is a plain link to
 * the prefilled register page; with JavaScript this modal opens and
 * submits via fetch.
 *
 * The slot values (upline, side) are set by JavaScript when a + link is
 * clicked. This partial renders once per page with empty defaults.
 */

$binary_enabled  = BinaryPlacementService::isEnabled();
$free_enabled    = setting_bool('free_registration_enabled', true);
$registrar       = auth_user();
$packages        = PackageService::publicCards();
$mode            = (string) old('mode', $free_enabled ? 'standard' : 'code');
$registrar_balance = $registrar === null
    ? 0.0
    : (new WalletService())->balance((int) $registrar['id']);

$fieldClass = static function (string $field): string {
    return 'form-control' . (has_error($field) ? ' is-invalid' : '');
};
$feedback = static function (string $field, string $attributes = ''): string {
    $message = error_for($field);
    return $message === '' ? '' : '<div class="invalid-feedback"' . $attributes . '>' . e($message) . '</div>';
};
$csrf   = csrf_field();
$return = url('genealogy?view=binary');

$modes = [];
if ($free_enabled) { $modes['standard'] = ['label'=>'Standard','note'=>'Free · shop only','icon'=>'bx-user-check','text'=>'Create a free shopping account. No package, no commission.']; }
$modes['code'] = ['label'=>'Registration code','note'=>'Code · active at once','icon'=>'bx-key','text'=>'Redeem the code your sponsor sold you; the package comes from the code.'];
if ($registrar !== null) { $modes['ewallet'] = ['label'=>'E-wallet','note'=>'Signed-in sponsor pays','icon'=>'bx-wallet','text'=>'Pay the package price from your e-wallet; the new member is active immediately.']; }
if ($free_enabled) { $modes['pending'] = ['label'=>'Activate later','note'=>'Free · pending','icon'=>'bx-time-five','text'=>'Join free now and activate with a code, wallet or proof later.']; }
if (!array_key_exists($mode, $modes)) { $mode = array_key_first($modes) ?: 'code'; }
?>
<div class="modal fade" id="binaryRegisterModal" tabindex="-1" aria-labelledby="binaryRegisterModalLabel" aria-hidden="true" data-register-url="<?= e(url('register')) ?>">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-semibold" id="binaryRegisterModalLabel">Register into this slot</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info d-flex align-items-start gap-2 mb-3 small" role="alert">
          <i class="icon-base bx bx-info-circle mt-1"></i>
          <div>
            <strong class="d-block">Placement locked</strong>
            <span id="binaryModalPlacement" class="text-body-secondary">Upline: <span class="fw-bold">—</span> &middot; Side: <span class="fw-badge">—</span></span>
          </div>
        </div>

        <form
          id="binaryRegisterForm"
          class="mb-4"
          action="<?= e(url('register')) ?>"
          method="POST"
          novalidate
          data-validate-code="<?= e(url('register/validate-code')) ?>"
          data-check-username="<?= e(url('register/check-username')) ?>"
          data-check-sponsor="<?= e(url('register/check-sponsor')) ?>"
        >
          <?= $csrf ?>
          <input type="hidden" name="return" value="<?= e($return) ?>" />
          <input type="hidden" name="binary_upline" id="binaryModalUplineInput" value="" />
          <input type="hidden" name="binary_position" id="binaryModalPositionInput" value="" />

          <!-- Mode selector -->
          <div class="mb-4">
            <label class="form-label fw-semibold">How do you want to join?</label>
            <div class="register-modes" role="radiogroup" aria-label="Registration mode">
<?php foreach ($modes as $value => $meta): ?>
              <label class="register-mode d-block mb-2">
                <input
                  type="radio"
                  name="mode"
                  value="<?= e($value) ?>"
                  class="register-mode-input"
                  data-mode="<?= e($value) ?>"
                  data-binary-modal-mode
<?= $mode === $value ? ' checked' : '' ?>
                />
                <span class="d-block">
                  <span class="fw-semibold"><?= e($meta['label']) ?></span>
                  <span class="text-body-secondary small"><?= e($meta['note']) ?></span>
                </span>
              </label>
<?php endforeach; ?>
            </div>
          </div>

          <!-- Sponsor / upline (prefilled by JS, read-only) -->
          <div class="mb-4">
            <label class="form-label" for="binaryModalSponsor">Sponsor / upline</label>
            <div class="input-group">
              <span class="input-group-text"><i class="icon-base bx bx-user-check"></i></span>
              <input
                type="text"
                class="form-control"
                id="binaryModalSponsor"
                name="sponsor"
                value=""
                readonly
                autocomplete="off"
              />
            </div>
          </div>

          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="binaryModalUsername">Username</label>
              <input
                type="text"
                class="<?= $fieldClass('username') ?>"
                id="binaryModalUsername"
                name="username"
                value="<?= old('username') ?>"
                autocomplete="username"
                data-binary-modal-username
                required
              />
              <?= $feedback('username') ?>
              <div class="form-text" data-binary-modal-username-hint>3–50 letters, numbers, dot, underscore or dash.</div>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="binaryModalEmail">Email</label>
              <input
                type="email"
                class="<?= $fieldClass('email') ?>"
                id="binaryModalEmail"
                name="email"
                value="<?= old('email') ?>"
                autocomplete="email"
                required
              />
              <?= $feedback('email') ?>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="binaryModalFullName">Full name <span class="text-body-secondary">(optional)</span></label>
              <input
                type="text"
                class="<?= $fieldClass('full_name') ?>"
                id="binaryModalFullName"
                name="full_name"
                value="<?= old('full_name') ?>"
                autocomplete="name"
              />
              <?= $feedback('full_name') ?>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="binaryModalPhone">Contact number <span class="text-body-secondary">(optional)</span></label>
              <input
                type="text"
                class="<?= $fieldClass('phone') ?>"
                id="binaryModalPhone"
                name="phone"
                value="<?= old('phone') ?>"
                placeholder="0917 000 0000"
                autocomplete="tel"
              />
              <?= $feedback('phone') ?>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="binaryModalPassword">Password</label>
              <div class="input-group input-group-merge">
                <input
                  type="password"
                  class="<?= $fieldClass('password') ?>"
                  id="binaryModalPassword"
                  name="password"
                  autocomplete="new-password"
                  required
                />
                <span class="input-group-text cursor-pointer" data-password-toggle><i class="icon-base bx bx-hide"></i></span>
              </div>
              <?= $feedback('password') ?>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="binaryModalPasswordConfirm">Confirm password</label>
              <div class="input-group input-group-merge">
                <input
                  type="password"
                  class="<?= $fieldClass('password_confirm') ?>"
                  id="binaryModalPasswordConfirm"
                  name="password_confirm"
                  autocomplete="new-password"
                  required
                />
                <span class="input-group-text cursor-pointer" data-password-toggle><i class="icon-base bx bx-hide"></i></span>
              </div>
              <?= $feedback('password_confirm') ?>
            </div>
          </div>

          <!-- Code panel -->
          <div class="mode-panel mt-4" data-mode-panel="code" data-binary-modal-panel>
            <div class="border rounded-3 p-3 p-sm-4 bg-lighter">
              <label class="form-label fw-semibold" for="binaryModalRegCode">Registration code</label>
              <div class="input-group">
                <span class="input-group-text"><i class="icon-base bx bx-key"></i></span>
                <input
                  type="text"
                  class="<?= $fieldClass('reg_code') ?>"
                  id="binaryModalRegCode"
                  name="reg_code"
                  value="<?= old('reg_code') ?>"
                  placeholder="XXXX-XXXX-XXXX"
                  maxlength="14"
                  autocomplete="off"
                  data-binary-modal-code
                />
                <button class="btn btn-outline-primary" type="button" data-binary-modal-validate-code>Validate</button>
              </div>
              <?= $feedback('reg_code', ' data-binary-modal-code-feedback') ?>
              <div class="form-text" data-binary-modal-code-hint>The code determines the package you activate.</div>
              <div class="alert alert-success d-none mt-3 mb-0 py-2 small" data-binary-modal-code-ok></div>
            </div>
          </div>

          <!-- E-wallet panel -->
          <div class="mode-panel mt-4" data-mode-panel="ewallet" data-binary-modal-panel>
            <div class="border rounded-3 p-3 p-sm-4 bg-lighter">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <label class="form-label fw-semibold mb-0">Package to activate</label>
                <span class="badge bg-label-primary">Your balance: <?= e(money($registrar_balance)) ?></span>
              </div>

<?php if ($packages === []): ?>
              <div class="alert alert-warning mb-0 py-2 small">No packages are available right now.</div>
<?php else: ?>
              <div class="register-packages">
<?php foreach ($packages as $index => $package): ?>
                <label class="register-package d-block mb-2">
                  <input
                    type="radio"
                    name="package_id"
                    value="<?= (int) $package['id'] ?>"
                    class="register-package-input"
                    data-binary-modal-package
<?= (string) old('package_id') === (string) $package['id'] ? ' checked' : ($index === 0 && old('package_id') === '' && $mode === 'ewallet' ? ' checked' : '') ?>
                  />
                  <span class="d-flex align-items-start justify-content-between gap-2">
                    <span class="fw-semibold"><?= e((string) $package['name']) ?></span>
                    <span class="fw-semibold text-primary text-nowrap"><?= e(money((float) $package['price'])) ?></span>
                  </span>
                  <span class="d-block small text-body-secondary mt-1">
                    Product value <?= e(money((float) $package['product_value'])) ?> &middot; direct referral <?= e(money((float) $package['direct_referral_bonus'])) ?>
                  </span>
                  <span class="d-block small text-body-secondary"><?= (int) $package['funded_levels'] ?> paying levels up to 10</span>
                </label>
<?php endforeach; ?>
              </div>
<?php endif; ?>
              <?= $feedback('package_id') ?>
            </div>
          </div>

          <!-- Terms -->
          <div class="my-4">
            <div class="form-check mb-0">
              <input
                class="form-check-input<?= has_error('terms') ? ' is-invalid' : '' ?>"
                type="checkbox"
                id="binaryModalTerms"
                name="terms"
                value="1"
                data-binary-modal-terms
                required
              />
              <label class="form-check-label" for="binaryModalTerms">
                I agree to the <a href="javascript:;">privacy policy &amp; terms</a>
              </label>
              <?= $feedback('terms') ?>
            </div>
          </div>

          <div class="global-error alert alert-danger d-none mt-3" role="alert"></div>

          <button class="btn btn-primary w-100" type="submit" id="binaryModalSubmit" data-binary-modal-submit>
            Create account
          </button>
        </form>

        <div class="mt-3 text-center">
          <a href="#" id="binaryModalFullFormLink" class="text-decoration-none small">
            Open full registration form &rarr;
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
