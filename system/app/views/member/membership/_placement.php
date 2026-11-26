<?php
declare(strict_types=1);

/**
 * Membership page — binary placement block (Phase 23C-S3).
 *
 * Shown when the member has no binary node and is activating a
 * binary-enabled package. The member chooses an upline and a side; the
 * controller places them inside the activation transaction, before
 * complete() runs the binary hook.
 *
 * @var bool $placement_needed
 */
?>
<?php if ($placement_needed): ?>
              <div class="card mb-6">
                <div class="card-header">
                  <h5 class="card-title mb-0"><i class="icon-base bx bx-shield-quarter me-1"></i> Binary placement</h5>
                </div>
                <div class="card-body">
                  <p class="text-body-secondary small">
                    Choose the member who places you in the binary tree and the side.
                    This cannot be changed once you have activated.
                  </p>

                  <label class="form-label">Binary upline</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text"><i class="icon-base bx bx-user"></i></span>
                    <input
                      type="text"
                      class="form-control<?= has_error('binary_upline') ? ' is-invalid' : '' ?>"
                      name="binary_upline"
                      value="<?= old('binary_upline') ?>"
                      placeholder="Username or referral code"
                      required
                    />
                  </div>
<?php if (has_error('binary_upline')): ?>
                  <div class="invalid-feedback"><?= e(error_for('binary_upline')) ?></div>
<?php endif; ?>

                  <label class="form-label">Side</label>
                  <div class="form-check mb-3">
                    <input
                      class="form-check-input"
                      id="binary-side-left"
                      name="binary_position"
                      value="left"
                      <?= (old('binary_position') ?? 'left') === 'left' ? 'checked' : '' ?>
                    />
                    <label class="form-check-label" for="binary-side-left">Left</label>
                  </div>
                  <div class="form-check mb-0">
                    <input
                      class="form-check-input"
                      id="binary-side-right"
                      name="binary_position"
                      value="right"
                      <?= (old('binary_position') ?? 'left') === 'right' ? 'checked' : '' ?>
                    />
                    <label class="form-check-label" for="binary-side-right">Right</label>
                  </div>
<?php if (has_error('binary_position')): ?>
                  <div class="invalid-feedback mt-2"><?= e(error_for('binary_position')) ?></div>
<?php endif; ?>
                </div>
              </div>
<?php endif; ?>
