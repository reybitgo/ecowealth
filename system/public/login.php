<?php
declare(strict_types=1);

/* ==============================================================
 *  Sign-in page
 * ============================================================== */
require __DIR__ . '/../app/bootstrap.php';

require_guest();
old_clear();

$error     = null;
$lockedOut = false;

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $identifier = trim((string) ($_POST['email-username'] ?? ''));
        $password   = (string) ($_POST['password'] ?? '');
        $remember   = !empty($_POST['remember-me']);

        if ($identifier === '' || $password === '') {
            $error = 'Please enter your email/username and password.';
        } elseif (login_attempts_left() <= 0) {
            $lockedOut = true;
        } elseif (auth_login($identifier, $password, $remember)) {
            old_clear();
            flash_set('success', 'Welcome back, ' . (auth_user()['username'] ?? 'friend') . '! You are signed in.');
            redirect('dashboard');
        } else {
            if (login_attempts_left() <= 0) {
                $lockedOut = true;
            } else {
                $left = login_attempts_left();
                $error = 'Invalid credentials. Please check your email/username and password.'
                    . ($left <= 2 ? sprintf(' %d attempt%s left before a temporary lockout.', $left, $left === 1 ? '' : 's') : '');
            }
        }

        if ($error !== null || $lockedOut) {
            old_set(['email-username' => $identifier]);
        }
    }
}

$attemptsLeft = login_attempts_left();
$page_title   = 'Sign in';

require __DIR__ . '/../app/layout/auth_header.php';
?>
          <!-- Sign in -->
          <div class="card px-sm-6 px-0">
            <div class="card-body">
              <!-- Logo -->
              <div class="app-brand justify-content-center">
                <a href="<?= e(url('/')) ?>" class="app-brand-link gap-2">
                  <?php $brand_logo_height = 56; ?>
                  <?php require __DIR__ . '/../app/layout/logo.php'; ?>
                </a>
              </div>
              <!-- /Logo -->
              <h4 class="mb-1">Sign in to <?= e(APP_NAME) ?></h4>
              <p class="mb-6">
                Manage your membership, orders and e-wallet.
              </p>

              <?= flash_render() ?>

              <?php if ($error !== null): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2 mb-6" role="alert">
                  <i class="icon-base bx bx-x-circle fs-4"></i><span><?= e($error) ?></span>
                </div>
              <?php elseif ($lockedOut || $attemptsLeft <= 0): ?>
                <div class="alert alert-warning d-flex align-items-center gap-2 mb-6" role="alert">
                  <i class="icon-base bx bx-error fs-4"></i>
                  <span>Too many failed attempts. Please wait 15 minutes before trying again.</span>
                </div>
              <?php elseif (APP_DEBUG): ?>
                <?php /* Development helper only — never rendered for a deployed (APP_ENV=production) build. */ ?>
                <div class="alert alert-info d-flex align-items-start gap-2 mb-6" role="alert">
                  <i class="icon-base bx bx-info-circle fs-4"></i>
                  <div>
                    <strong>Demo account</strong><br />
                    Email / username: <code>admin</code> &nbsp;&middot;&nbsp; Password: <code>Admin@123</code>
                  </div>
                </div>
              <?php endif; ?>

              <form
                id="formAuthentication"
                class="mb-6"
                action="<?= e(url('login')) ?>"
                method="POST"
              >
                <?= csrf_field() ?>
                <div class="mb-6 form-control-validation">
                  <label for="email" class="form-label">Email or Username</label>
                  <input
                    type="text"
                    class="form-control"
                    id="email"
                    name="email-username"
                    placeholder="Enter your email or username"
                    value="<?= old('email-username') ?>"
                    autofocus
                  />
                </div>
                <div class="mb-6 form-password-toggle form-control-validation">
                  <label class="form-label" for="password">Password</label>
                  <div class="input-group input-group-merge">
                    <input
                      type="password"
                      id="password"
                      class="form-control"
                      name="password"
                      placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                      aria-describedby="password"
                    />
                    <span class="input-group-text cursor-pointer"
                      ><i class="icon-base bx bx-hide"></i
                    ></span>
                  </div>
                </div>
                <div class="mb-7">
                  <div class="form-check mb-0">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      id="remember-me"
                      name="remember-me"
                      value="1"
                    />
                    <label class="form-check-label" for="remember-me">
                      Remember me
                    </label>
                  </div>
                  <p class="small text-body-secondary mt-3 mb-0">
                    Forgot your password? Contact your administrator to have it reset.
                  </p>
                </div>
                <div class="mb-6">
                  <button class="btn btn-primary d-grid w-100" type="submit" <?= $attemptsLeft <= 0 ? 'disabled' : '' ?>>
                    Sign in
                  </button>
                </div>
              </form>
              <p class="text-center">
                <span>New to <?= e(APP_NAME) ?>?</span>
                <a href="<?= e(url('register')) ?>">
                  <span>Create an account</span>
                </a>
              </p>
            </div>
          </div>
          <!-- /Sign in -->
<?php require __DIR__ . '/../app/layout/auth_footer.php'; ?>
