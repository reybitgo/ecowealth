<?php
declare(strict_types=1);

/**
 * Registration form (plan §5.10, §7 Phase 9).
 *
 * Rendered by public/register.php inside the auth layout. Four modes,
 * one sponsor link, no binary placement fields anywhere.
 *
 * @var string                     $mode            selected mode
 * @var string                     $prefill_sponsor sponsor username / referral code to prefill
 * @var bool                       $free_enabled    standard + pending available?
 * @var list<array<string, mixed>> $packages        active packages (PackageService::publicCards())
 * @var array<string, mixed>|null  $registrar       the signed-in member registering someone, or null
 * @var float                      $registrar_balance
 */

/* Field errors are shown under their own inputs; the banner above the form
 * only carries a generic prompt (or a global failure such as a database
 * problem). public/register.php deliberately no longer sets a danger flash
 * for the same failure — one notification, not two. */
$form_errors   = errors_all();
$global_errors = array_filter(
    $form_errors,
    static fn (string|int $field): bool => str_starts_with((string) $field, '_'),
    ARRAY_FILTER_USE_KEY
);
$field_errors  = array_diff_key($form_errors, $global_errors);

$modes = [];

if ($free_enabled) {
    $modes['standard'] = [
        'label' => 'Standard',
        'note'  => 'Free · shop only',
        'icon'  => 'bx-user-check',
        'text'  => 'Create a free shopping account. No package, no commission.',
    ];
}

$modes['code'] = [
    'label' => 'Registration code',
    'note'  => 'Code · active at once',
    'icon'  => 'bx-key',
    'text'  => 'Redeem the code your sponsor sold you; the package comes from the code.',
];

if ($registrar !== null) {
    $modes['ewallet'] = [
        'label' => 'E-wallet',
        'note'  => 'Signed-in sponsor pays',
        'icon'  => 'bx-wallet',
        'text'  => 'Pay the package price from your e-wallet; the new member is active immediately.',
    ];
}

if ($free_enabled) {
    $modes['pending'] = [
        'label' => 'Activate later',
        'note'  => 'Free · pending',
        'icon'  => 'bx-time-five',
        'text'  => 'Join free now and activate with a code, wallet or proof later.',
    ];
}

if (!array_key_exists($mode, $modes)) {
    $mode = array_key_first($modes) ?: 'code';
}

$fieldClass = static function (string $field): string {
    return 'form-control' . (has_error($field) ? ' is-invalid' : '');
};

$feedback = static function (string $field, string $attributes = ''): string {
    $message = error_for($field);

    return $message === '' ? '' : '<div class="invalid-feedback"' . $attributes . '>' . e($message) . '</div>';
};
?>
<script>
  /* Progressive enhancement marker: with JavaScript the mode panels are
   * shown one at a time. Without it every panel is visible and the form
   * still submits (the server reads only the selected mode's fields). */
  document.documentElement.classList.add('js');
</script>

<div class="card px-sm-6 px-0">
  <div class="card-body">
    <div class="app-brand justify-content-center">
      <a href="<?= e(url('/')) ?>" class="app-brand-link gap-2">
        <?php $brand_logo_height = 56; ?>
        <?php require dirname(__DIR__, 2) . '/layout/logo.php'; ?>
      </a>
    </div>

    <h4 class="mb-1"><?= $registrar === null ? 'Join EcoWealth' : 'Register a member' ?></h4>
<?php if ($registrar === null): ?>
    <p class="mb-5 text-body-secondary">
      One sponsor link, one membership record. Choose how you want to join —
      you can change your package path later from the membership page.
    </p>
<?php else: ?>
    <p class="mb-5 text-body-secondary">
      Create an account for someone you sponsor. You stay signed in as
      <strong>@<?= e((string) $registrar['username']) ?></strong>; the new member
      can sign in on their own afterwards.
    </p>
<?php endif; ?>

    <?= flash_render() ?>

<?php if ($global_errors !== []): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2 mb-5" role="alert">
      <i class="icon-base bx bx-x-circle fs-4 mt-1"></i>
      <div class="small">
        <strong><?= e((string) reset($global_errors)) ?></strong>
<?php if (count($global_errors) > 1): ?>
        <ul class="mb-0 ps-3 mt-1">
<?php foreach ($global_errors as $message): ?>
          <li><?= e($message) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
      </div>
    </div>
<?php elseif ($field_errors !== []): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2 mb-5" role="alert">
      <i class="icon-base bx bx-error-circle fs-4 mt-1"></i>
      <div class="small">
        Please fix the highlighted field<?= count($field_errors) === 1 ? '' : 's' ?> and try again.
      </div>
    </div>
<?php endif; ?>

    <form
      id="registerForm"
      class="mb-4"
      action="<?= e(url('register')) ?>"
      method="POST"
      novalidate
      data-validate-code="<?= e(url('register/validate-code')) ?>"
      data-check-username="<?= e(url('register/check-username')) ?>"
      data-check-sponsor="<?= e(url('register/check-sponsor')) ?>"
    >
      <?= csrf_field() ?>

      <!-- Mode selector -->
      <div class="mb-5">
        <label class="form-label fw-semibold">How do you want to join?</label>
        <div class="register-modes" role="radiogroup" aria-label="Registration mode">
<?php foreach ($modes as $value => $meta): ?>
          <label class="register-mode">
            <input
              type="radio"
              name="mode"
              value="<?= e($value) ?>"
              class="register-mode-input"
              data-mode="<?= e($value) ?>"
<?= $mode === $value ? ' checked' : '' ?>
            />
            <span class="register-mode-body">
              <span class="register-mode-icon"><i class="icon-base <?= e(icon_class($meta['icon'])) ?>"></i></span>
              <span class="register-mode-text">
                <span class="register-mode-label"><?= e($meta['label']) ?></span>
                <span class="register-mode-note"><?= e($meta['note']) ?></span>
                <span class="register-mode-hint"><?= e($meta['text']) ?></span>
              </span>
            </span>
          </label>
<?php endforeach; ?>
<?php if ($registrar === null): ?>
          <div class="register-mode register-mode-muted">
            <span class="register-mode-body">
              <span class="register-mode-icon"><i class="icon-base bx bx-wallet"></i></span>
              <span class="register-mode-text">
                <span class="register-mode-label">E-wallet</span>
                <span class="register-mode-note">Signed-in sponsor pays</span>
                <span class="register-mode-hint">
                  <a href="<?= e(url('login')) ?>">Sign in</a> to register someone from your e-wallet.
                </span>
              </span>
            </span>
          </div>
<?php endif; ?>
        </div>
      </div>

      <!-- Sponsor -->
      <div class="mb-4">
        <label class="form-label" for="sponsor">
          Sponsor username or referral code
          <span class="text-body-secondary">(optional)</span>
        </label>
        <div class="input-group">
          <span class="input-group-text"><i class="icon-base bx bx-user-check"></i></span>
          <input
            type="text"
            class="<?= $fieldClass('sponsor') ?>"
            id="sponsor"
            name="sponsor"
            value="<?= old('sponsor', $prefill_sponsor) ?>"
            placeholder="e.g. juan or JUAN0001"
            autocomplete="off"
            data-sponsor-input
          />
        </div>
        <?= $feedback('sponsor') ?>
        <div class="form-text" data-sponsor-hint>
          Leave this blank to join under the company account.
        </div>
      </div>

      <div class="row g-4">
        <div class="col-sm-6">
          <label class="form-label" for="username">Username</label>
          <input
            type="text"
            class="<?= $fieldClass('username') ?>"
            id="username"
            name="username"
            value="<?= old('username') ?>"
            autocomplete="username"
            data-username-input
            required
          />
          <?= $feedback('username') ?>
          <div class="form-text" data-username-hint>3–50 letters, numbers, dot, underscore or dash.</div>
        </div>

        <div class="col-sm-6">
          <label class="form-label" for="email">Email</label>
          <input
            type="email"
            class="<?= $fieldClass('email') ?>"
            id="email"
            name="email"
            value="<?= old('email') ?>"
            autocomplete="email"
            required
          />
          <?= $feedback('email') ?>
        </div>

        <div class="col-sm-6">
          <label class="form-label" for="full_name">Full name <span class="text-body-secondary">(optional)</span></label>
          <input
            type="text"
            class="<?= $fieldClass('full_name') ?>"
            id="full_name"
            name="full_name"
            value="<?= old('full_name') ?>"
            autocomplete="name"
          />
          <?= $feedback('full_name') ?>
        </div>

        <div class="col-sm-6">
          <label class="form-label" for="phone">Contact number <span class="text-body-secondary">(optional)</span></label>
          <input
            type="text"
            class="<?= $fieldClass('phone') ?>"
            id="phone"
            name="phone"
            value="<?= old('phone') ?>"
            placeholder="0917 000 0000"
            autocomplete="tel"
          />
          <?= $feedback('phone') ?>
        </div>

        <div class="col-sm-6">
          <label class="form-label" for="password">Password</label>
          <div class="input-group input-group-merge">
            <input
              type="password"
              class="<?= $fieldClass('password') ?>"
              id="password"
              name="password"
              autocomplete="new-password"
              required
            />
            <span class="input-group-text cursor-pointer" data-password-toggle><i class="icon-base bx bx-hide"></i></span>
          </div>
          <?= $feedback('password') ?>
        </div>

        <div class="col-sm-6">
          <label class="form-label" for="password_confirm">Confirm password</label>
          <div class="input-group input-group-merge">
            <input
              type="password"
              class="<?= $fieldClass('password_confirm') ?>"
              id="password_confirm"
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
      <div class="mode-panel mt-4" data-mode-panel="code">
        <div class="border rounded-3 p-3 p-sm-4 bg-lighter">
          <label class="form-label fw-semibold" for="reg_code">Registration code</label>
          <div class="input-group">
            <span class="input-group-text"><i class="icon-base bx bx-key"></i></span>
            <input
              type="text"
              class="<?= $fieldClass('reg_code') ?>"
              id="reg_code"
              name="reg_code"
              value="<?= old('reg_code') ?>"
              placeholder="XXXX-XXXX-XXXX"
              maxlength="14"
              autocomplete="off"
              data-code-input
            />
            <button class="btn btn-outline-primary" type="button" data-code-validate>Validate</button>
          </div>
          <?= $feedback('reg_code', ' data-code-feedback') ?>
          <div class="form-text" data-code-hint>The code determines the package you activate.</div>
          <div class="alert alert-success d-none mt-3 mb-0 py-2 small" data-code-ok></div>
        </div>
      </div>

      <!-- E-wallet panel -->
      <div class="mode-panel mt-4" data-mode-panel="ewallet">
        <div class="border rounded-3 p-3 p-sm-4 bg-lighter">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <label class="form-label fw-semibold mb-0">Package to activate</label>
            <span class="badge bg-label-primary">
              Your balance: <?= e(money($registrar_balance)) ?>
            </span>
          </div>

<?php if ($packages === []): ?>
          <div class="alert alert-warning mb-0 py-2 small">
            No packages are available right now. Join with a registration code or activate later.
          </div>
<?php else: ?>
          <div class="register-packages">
<?php foreach ($packages as $index => $package): ?>
            <label class="register-package">
              <input
                type="radio"
                name="package_id"
                value="<?= (int) $package['id'] ?>"
                class="register-package-input"
<?= (string) old('package_id') === (string) $package['id'] ? ' checked' : ($index === 0 && old('package_id') === '' && $mode === 'ewallet' ? ' checked' : '') ?>
              />
              <span class="register-package-body">
                <span class="d-flex align-items-start justify-content-between gap-2">
                  <span class="fw-semibold"><?= e((string) $package['name']) ?></span>
                  <span class="fw-semibold text-primary text-nowrap"><?= e(money((float) $package['price'])) ?></span>
                </span>
                <span class="d-block small text-body-secondary mt-1">
                  Product value <?= e(money((float) $package['product_value'])) ?>
                  · direct referral <?= e(money((float) $package['direct_referral_bonus'])) ?>
                </span>
                <span class="d-block small text-body-secondary">
                  <?= (int) $package['funded_levels'] ?> paying levels up to 10
                </span>
              </span>
            </label>
<?php endforeach; ?>
          </div>
          <?= $feedback('package_id') ?>
          <div class="alert alert-info mt-3 mb-0 py-2 small">
            The package price is debited from your e-wallet once, and the company e-wallet is credited as revenue.
          </div>
<?php endif; ?>
        </div>
      </div>

      <!-- Standard panel -->
      <div class="mode-panel mt-4" data-mode-panel="standard">
        <div class="alert alert-info d-flex align-items-start gap-2 mb-0" role="alert">
          <i class="icon-base bx bx-info-circle fs-5"></i>
          <div class="small">
            A standard membership is free and lets you shop immediately. It carries no package
            and pays no referral commissions.
          </div>
        </div>
      </div>

      <!-- Pending panel -->
      <div class="mode-panel mt-4" data-mode-panel="pending">
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-0" role="alert">
          <i class="icon-base bx bx-time-five fs-5"></i>
          <div class="small">
            Your account is created as <strong>pending</strong>. Activate any time from
            <em>Membership</em> with a registration code, your e-wallet or a payment proof.
          </div>
        </div>
      </div>

      <!-- Terms -->
      <div class="my-6">
        <div class="form-check mb-0">
          <input
            class="form-check-input<?= has_error('terms') ? ' is-invalid' : '' ?>"
            type="checkbox"
            id="terms-conditions"
            name="terms"
            value="1"
            data-terms
            required
          />
          <label class="form-check-label" for="terms-conditions">
            I agree to the <a href="javascript:;">privacy policy &amp; terms</a>
          </label>
          <?= $feedback('terms') ?>
        </div>
      </div>

      <button class="btn btn-primary d-grid w-100" type="submit" id="register-submit" data-register-submit>
        Create account
      </button>
    </form>

<?php if ($registrar === null): ?>
    <p class="text-center mb-2">
      <span>Already have an account?</span>
      <a href="<?= e(url('login')) ?>"><span>Sign in instead</span></a>
    </p>
<?php else: ?>
    <p class="text-center mb-0">
      <a href="<?= e(url('dashboard')) ?>" class="small">Back to dashboard</a>
    </p>
<?php endif; ?>
  </div>
</div>
