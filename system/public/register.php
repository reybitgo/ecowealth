<?php
declare(strict_types=1);

/* ==============================================================
 *  Registration page
 * ============================================================== */
require __DIR__ . '/../app/bootstrap.php';

require_guest();

/* Registration is part of the public site: it follows maintenance mode
 * (the reference closed it with a seat-limit gate instead). */
MaintenanceGate::handle();

old_clear();

$errors = [];

if (is_post()) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
        old_set([
            'username' => $_POST['username'] ?? '',
            'email'    => $_POST['email'] ?? '',
        ]);
    } else {
        $username = (string) ($_POST['username'] ?? '');
        $email    = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm-password'] ?? '');
        $terms    = !empty($_POST['terms']);

        if (!$terms) {
            $errors[] = 'You must agree to the privacy policy & terms.';
        }

        $errors = array_merge(
            $errors,
            auth_register($username, $email, $password, $confirm)
        );

        if ($errors === []) {
            old_clear();
            flash_set('success', 'Account created successfully! You can now sign in.');
            redirect('login');
        }

        old_set(['username' => $username, 'email' => $email]);
    }
}

$page_title = 'Register';

require __DIR__ . '/../app/layout/auth_header.php';
?>
          <!-- Register -->
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
              <h4 class="mb-1">Adventure starts here &#x1f680;</h4>
              <p class="mb-6">Make your app management easy and fun!</p>

              <?= flash_render() ?>

              <?php if ($errors !== []): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 mb-6" role="alert">
                  <i class="icon-base bx bx-x-circle fs-4 mt-1"></i>
                  <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                      <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>

              <form
                id="formAuthentication"
                class="mb-6"
                action="<?= e(url('register')) ?>"
                method="POST"
              >
                <?= csrf_field() ?>
                <div class="mb-6 form-control-validation">
                  <label for="username" class="form-label">Username</label>
                  <input
                    type="text"
                    class="form-control"
                    id="username"
                    name="username"
                    placeholder="Enter your username"
                    value="<?= old('username') ?>"
                    autofocus
                  />
                </div>
                <div class="mb-6 form-control-validation">
                  <label for="email" class="form-label">Email</label>
                  <input
                    type="text"
                    class="form-control"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    value="<?= old('email') ?>"
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
                <div class="mb-6 form-password-toggle form-control-validation">
                  <label class="form-label" for="confirm-password">Confirm Password</label>
                  <div class="input-group input-group-merge">
                    <input
                      type="password"
                      id="confirm-password"
                      class="form-control"
                      name="confirm-password"
                      placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                      aria-describedby="confirm-password"
                    />
                    <span class="input-group-text cursor-pointer"
                      ><i class="icon-base bx bx-hide"></i
                    ></span>
                  </div>
                </div>
                <div class="my-7 form-control-validation">
                  <div class="form-check mb-0">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      id="terms-conditions"
                      name="terms"
                      value="1"
                    />
                    <label class="form-check-label" for="terms-conditions">
                      I agree to
                      <a href="javascript:;">privacy policy &amp; terms</a>
                    </label>
                  </div>
                </div>
                <button class="btn btn-primary d-grid w-100" type="submit">Sign up</button>
              </form>
              <p class="text-center">
                <span>Already have an account?</span>
                <a href="<?= e(url('login')) ?>">
                  <span>Sign in instead</span>
                </a>
              </p>
            </div>
          </div>
          <!-- /Register -->
<?php require __DIR__ . '/../app/layout/auth_footer.php'; ?>
