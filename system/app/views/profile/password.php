<?php
declare(strict_types=1);

/**
 * Change password — shared by the member portal and the back office.
 * The controller picks the layout, so an administrator keeps their
 * sidebar and navigation context here.
 *
 * @var array<string, mixed>|null $user
 */

$me = $user ?? auth_user();
?>
            <div class="container-xxl flex-grow-1 container-p-y">
<?= View::partial('page_header', [
    'title'       => 'Change password',
    'description' => 'Signed in as <strong>' . e($me['username'] ?? '') . '</strong>.'
        . ' Changing your password signs out other remembered devices.',
    'actions'     => '<a href="' . e(url('profile')) . '" class="btn btn-label-secondary">'
        . '<i class="icon-base bx bx-arrow-back me-1"></i> Back to profile</a>',
]) ?>

              <div class="row justify-content-center">
                <div class="col-md-8 col-xl-6">
                  <div class="card">
                    <div class="card-body">
                      <form method="POST" action="<?= e(url('profile/password')) ?>" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-4">
                          <label for="current_password" class="form-label">Current password</label>
                          <input
                            type="password"
                            class="form-control <?= e(invalid_class('current_password')) ?>"
                            id="current_password"
                            name="current_password"
                            autocomplete="current-password"
                            required
                          />
                          <?php if (has_error('current_password')): ?>
                            <div class="invalid-feedback"><?= e(error_for('current_password')) ?></div>
                          <?php endif; ?>
                        </div>

                        <div class="mb-4">
                          <label for="new_password" class="form-label">New password</label>
                          <input
                            type="password"
                            class="form-control <?= e(invalid_class('new_password')) ?>"
                            id="new_password"
                            name="new_password"
                            autocomplete="new-password"
                            minlength="8"
                            required
                          />
                          <div class="form-text">At least 8 characters.</div>
                          <?php if (has_error('new_password')): ?>
                            <div class="invalid-feedback"><?= e(error_for('new_password')) ?></div>
                          <?php endif; ?>
                        </div>

                        <div class="mb-5">
                          <label for="confirm_password" class="form-label">Confirm new password</label>
                          <input
                            type="password"
                            class="form-control <?= e(invalid_class('confirm_password')) ?>"
                            id="confirm_password"
                            name="confirm_password"
                            autocomplete="new-password"
                            minlength="8"
                            required
                          />
                          <?php if (has_error('confirm_password')): ?>
                            <div class="invalid-feedback"><?= e(error_for('confirm_password')) ?></div>
                          <?php endif; ?>
                        </div>

                        <div class="d-flex gap-2">
                          <button type="submit" class="btn btn-primary">Update password</button>
                          <a href="<?= e(url('profile')) ?>" class="btn btn-label-secondary">Cancel</a>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            </div>
