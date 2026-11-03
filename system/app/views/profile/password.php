<?php
declare(strict_types=1);

/**
 * Change password — the member's own account (Phase 1).
 * Reference: admin_change_password.
 */

$me = auth_user();
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="row justify-content-center">
                <div class="col-md-8 col-xl-6">
                  <div class="card">
                    <div class="card-header">
                      <h5 class="mb-1">Change password</h5>
                      <p class="text-body-secondary mb-0 small">
                        Signed in as <strong><?= e($me['username'] ?? '') ?></strong>.
                        Changing your password signs out other remembered devices.
                      </p>
                    </div>
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
                          <a href="<?= e(url('dashboard')) ?>" class="btn btn-label-secondary">Cancel</a>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            </div>
