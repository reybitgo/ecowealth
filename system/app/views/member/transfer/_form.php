<?php
declare(strict_types=1);

/**
 * Send funds — the transfer form.
 *
 * Never inside a live region: a background refresh must not clear a
 * half-typed recipient, amount or note. The fee preview is computed by
 * assets/js/app-transfer.js from the data attributes; without
 * JavaScript the server still enforces every rule.
 *
 * The member re-enters their password (plan D22: required, reference
 * behaviour) because a transfer is irreversible.
 *
 * @var array<string, mixed> $limits
 * @var string $transfer_url
 * @var string $prefill_to
 */

$fee     = (float) ($limits['fee'] ?? 0);
$staff   = (bool) ($limits['staff'] ?? false);
$minimum = (float) ($limits['min'] ?? 0);
$maxSend = (float) ($limits['max_send'] ?? 0);
$recipient = old('recipient', $prefill_to);
$amount    = old('amount');
$note      = old('note');
?>
                <div class="card h-100">
                  <div class="card-header">
                    <h5 class="mb-1">Transfer details</h5>
                    <p class="text-body-secondary small mb-0">Confirm with your password — a completed transfer cannot be reversed.</p>
                  </div>
                  <div class="card-body">
                    <form
                      method="POST"
                      action="<?= e($transfer_url) ?>"
                      id="transferForm"
                      data-transfer-form
                      data-fee="<?= e((string) $fee) ?>"
                      data-min="<?= e((string) $minimum) ?>"
                      data-max="<?= e((string) $maxSend) ?>"
                      data-currency="<?= e(CURRENCY_SYMBOL) ?>"
                    >
                      <?= csrf_field() ?>

                      <div class="mb-4">
                        <label class="form-label" for="transferRecipient">Recipient username</label>
                        <div class="input-group has-validation">
                          <span class="input-group-text">@</span>
                          <input
                            type="text"
                            class="form-control <?= e(invalid_class('recipient')) ?>"
                            id="transferRecipient"
                            name="recipient"
                            value="<?= e($recipient) ?>"
                            placeholder="username"
                            maxlength="50"
                            autocomplete="off"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                          />
<?php if (has_error('recipient')): ?>
                          <div class="invalid-feedback"><?= e(error_for('recipient')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="form-text">The member’s username, not their email address.</div>
                      </div>

                      <div class="mb-4">
                        <label class="form-label" for="transferAmount">Amount</label>
                        <div class="input-group has-validation">
                          <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                          <input
                            type="number"
                            class="form-control <?= e(invalid_class('amount')) ?>"
                            id="transferAmount"
                            name="amount"
                            value="<?= e($amount) ?>"
                            min="<?= e((string) $minimum) ?>"
                            step="0.01"
                            inputmode="decimal"
                            placeholder="0.00"
                            required
                          />
<?php if (has_error('amount')): ?>
                          <div class="invalid-feedback"><?= e(error_for('amount')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="form-text">
                          Minimum <?= e(money($minimum)) ?> · Available to send <?= e(money($maxSend)) ?>
                        </div>
                      </div>

                      <div class="mb-4">
                        <label class="form-label" for="transferNote">Note <span class="text-body-secondary fw-normal">(optional)</span></label>
                        <input
                          type="text"
                          class="form-control <?= e(invalid_class('note')) ?>"
                          id="transferNote"
                          name="note"
                          value="<?= e($note) ?>"
                          maxlength="255"
                          placeholder="What is this for?"
                        />
<?php if (has_error('note')): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for('note')) ?></div>
<?php endif; ?>
                      </div>

                      <div class="mb-4">
                        <label class="form-label" for="transferPassword">Confirm with your password</label>
                        <div class="input-group has-validation">
                          <input
                            type="password"
                            class="form-control <?= e(invalid_class('password')) ?>"
                            id="transferPassword"
                            name="password"
                            autocomplete="current-password"
                            placeholder="Your account password"
                            required
                          />
                          <button
                            type="button"
                            class="btn btn-label-secondary"
                            data-transfer-password-toggle
                            aria-controls="transferPassword"
                            aria-pressed="false"
                            aria-label="Show password"
                          >
                            <i class="icon-base bx bx-show"></i>
                          </button>
<?php if (has_error('password')): ?>
                          <div class="invalid-feedback"><?= e(error_for('password')) ?></div>
<?php endif; ?>
                        </div>
                      </div>

                      <div class="card bg-label-secondary border-0 mb-4 transfer-preview" data-transfer-preview>
                        <div class="card-body py-3">
                          <div class="d-flex justify-content-between small">
                            <span class="text-body-secondary">You send</span>
                            <strong data-transfer-preview-send><?= e(money(0)) ?></strong>
                          </div>
                          <div class="d-flex justify-content-between small">
                            <span class="text-body-secondary">Fee</span>
                            <strong data-transfer-preview-fee><?= e($staff ? 'Waived' : ($fee > 0 ? money($fee) : money(0))) ?></strong>
                          </div>
                          <hr class="my-2" />
                          <div class="d-flex justify-content-between">
                            <span class="text-body-secondary">Total debit</span>
                            <strong class="text-primary" data-transfer-preview-total><?= e(money(0)) ?></strong>
                          </div>
                          <p class="small text-body-secondary mb-0 mt-2 d-none" data-transfer-preview-warning role="status" aria-live="polite"></p>
                        </div>
                      </div>

                      <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                          <i class="icon-base bx bx-paper-plane me-1"></i> Send transfer
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
