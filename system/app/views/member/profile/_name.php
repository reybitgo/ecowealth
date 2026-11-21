<?php
declare(strict_types=1);

/**
 * Profile — the full-name form.
 *
 * A held region: a background refresh never replaces a name the member
 * has typed but not submitted.
 *
 * @var array<string, mixed> $user
 */

$fullName = old('full_name') !== ''
    ? old('full_name')
    : e((string) ($user['full_name'] ?? ''));
?>
                    <div class="card-body" data-live-region="profile-name" data-live-hold="dirty">
                      <form method="POST" action="<?= e(url('profile')) ?>">
                        <?= csrf_field() ?>
                        <div class="mb-4">
                          <label class="form-label" for="full_name">Full name</label>
                          <input type="text" id="full_name" name="full_name" maxlength="100" required
                                 class="form-control <?= e(invalid_class('full_name')) ?>" value="<?= $fullName ?>" />
<?php if (has_error('full_name')): ?>
                          <div class="invalid-feedback"><?= e(error_for('full_name')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="mb-4">
                          <label class="form-label" for="profile-username">Username</label>
                          <input type="text" id="profile-username" class="form-control" value="<?= e((string) $user['username']) ?>" disabled />
                        </div>
                        <div class="mb-4">
                          <label class="form-label" for="profile-email">Email</label>
                          <input type="email" id="profile-email" class="form-control" value="<?= e((string) $user['email']) ?>" disabled />
                        </div>
                        <button type="submit" class="btn btn-primary">Save profile</button>
                      </form>
                    </div>
