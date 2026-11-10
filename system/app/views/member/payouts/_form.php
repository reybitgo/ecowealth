<?php
declare(strict_types=1);

/**
 * Payouts — the request form.
 *
 * Lives inside the `payout-request` live region (which is held while it
 * has unsaved input). One account box per channel: the browser posts all
 * four and the server reads the one that belongs to the selected method,
 * so switching channels without JavaScript still submits the right
 * number.
 *
 * `novalidate` is deliberate. The server owns every refusal — minimum,
 * withdrawable, channel, account format — and answers with the red flash
 * banner plus `is-invalid` + `invalid-feedback` on the field at fault.
 * With browser validation in charge a too-small amount would cancel the
 * POST and show nothing this app owns (the same defect QA12-03 fixed on
 * the transfer form). `min`/`max` stay for assistive technology and the
 * number spinner; assets/js/app-payout.js only previews the cost.
 *
 * @var array<string, mixed> $options
 * @var string $payout_url
 * @var list<array<string, mixed>> $methods
 */

$methods   = $methods ?? ($options['methods'] ?? []);
$min       = (float) ($options['min'] ?? 0);
$maxAmount = (float) ($options['max_amount'] ?? 0);
$usdtRate  = (float) ($options['usdt_rate'] ?? 0);

$available = array_values(array_filter(
    $methods,
    static fn (array $m): bool => (bool) ($m['available'] ?? false)
));

$selected = old('payout_method');

if ($selected === '' || !in_array($selected, array_column($available, 'key'), true)) {
    $selected = $available === [] ? '' : (string) $available[0]['key'];
}

$amount = old('amount');
?>
                <div class="card h-100 payout-form-card">
                  <div class="card-header">
                    <h5 class="mb-1">Request a payout</h5>
                    <p class="text-body-secondary small mb-0">
                      The amount leaves your withdrawable balance as soon as you submit — it is held until an
                      administrator sends it.
                    </p>
                  </div>
                  <div class="card-body">
                    <form
                      method="POST"
                      action="<?= e($payout_url . '/request') ?>"
                      id="payoutForm"
                      data-payout-form
                      novalidate
                      data-min="<?= e((string) $min) ?>"
                      data-max="<?= e((string) $maxAmount) ?>"
                      data-usdt-rate="<?= e((string) $usdtRate) ?>"
                      data-currency="<?= e(CURRENCY_SYMBOL) ?>"
                    >
                      <?= csrf_field() ?>

                      <div class="mb-4">
                        <label class="form-label" for="payoutAmount">Amount</label>
                        <div class="input-group has-validation">
                          <span class="input-group-text"><?= e(CURRENCY_SYMBOL) ?></span>
                          <input
                            type="number"
                            class="form-control <?= e(invalid_class('amount')) ?>"
                            id="payoutAmount"
                            name="amount"
                            value="<?= e($amount) ?>"
                            min="<?= e((string) $min) ?>"
                            max="<?= e((string) $maxAmount) ?>"
                            step="0.01"
                            inputmode="decimal"
                            placeholder="0.00"
                            data-payout-amount
                            required
                          />
<?php if (has_error('amount')): ?>
                          <div class="invalid-feedback"><?= e(error_for('amount')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="form-text">
                          Minimum <?= e(money($min)) ?> · Withdrawable <?= e(money($maxAmount)) ?>
                        </div>
                      </div>

                      <fieldset class="mb-4">
                        <legend class="form-label float-none w-100 mb-2">Send to</legend>
                        <div class="row g-3">
<?php foreach ($methods as $method): ?>
<?php
    $key         = (string) $method['key'];
    $isAvailable = (bool) ($method['available'] ?? false);
    $checked     = $selected === $key;
    $value       = old('account_' . $key, (string) ($method['account'] ?? ''));
?>
                          <div class="col-12 col-sm-6">
                            <div class="form-check payout-method <?= $isAvailable ? '' : 'payout-method-disabled' ?>">
                              <input
                                class="form-check-input"
                                type="radio"
                                name="payout_method"
                                id="method_<?= e($key) ?>"
                                value="<?= e($key) ?>"
                                data-payout-method
                                data-channel="<?= e($key) ?>"
                                data-fee-pct="<?= e((string) $method['fee_pct']) ?>"
                                data-usdt="<?= $method['usdt'] ? '1' : '0' ?>"
                                data-gas="<?= e((string) $method['gas_fee']) ?>"
                                <?= $checked ? 'checked' : '' ?>
                                <?= $isAvailable ? '' : 'disabled' ?>
                              />
                              <label class="form-check-label w-100" for="method_<?= e($key) ?>">
                                <span class="d-flex align-items-center gap-2">
                                  <i class="icon-base <?= e(icon_class((string) $method['icon'])) ?>"></i>
                                  <span class="fw-medium"><?= e((string) $method['label']) ?></span>
                                </span>
                                <span class="d-block small text-body-secondary">
<?php if ($isAvailable): ?>
<?php if ((float) $method['fee_pct'] > 0): ?>
                                  Fee <?= e(rtrim(rtrim(number_format((float) $method['fee_pct'], 2, '.', ''), '0'), '.')) ?>%
<?php else: ?>
                                  No service fee
<?php endif; ?>
<?php if ($method['usdt']): ?>
                                  · network fee <?= e(number_format((float) $method['gas_fee'], 4, '.', ',')) ?> USDT
<?php endif; ?>
<?php else: ?>
                                  <?= e((string) $method['reason']) ?>
<?php endif; ?>
                                </span>
                              </label>
                            </div>
                          </div>
<?php endforeach; ?>
                        </div>
<?php if (has_error('payout_method')): ?>
                        <div class="text-danger small mt-2"><?= e(error_for('payout_method')) ?></div>
<?php endif; ?>
                      </fieldset>

<?php foreach ($methods as $method): ?>
<?php
    $key      = (string) $method['key'];
    $fieldKey = 'account_' . $key;
    $isShown  = $key === $selected;
?>
                      <div
                        class="mb-4 payout-account-group"
                        data-payout-channel-group="<?= e($key) ?>"
                        <?= $isShown ? '' : 'hidden' ?>
                      >
                        <label class="form-label" for="<?= e($fieldKey) ?>"><?= e((string) $method['label']) ?> account</label>
                        <input
                          type="text"
                          class="form-control <?= e(invalid_class($fieldKey) . ' ' . invalid_class('account')) ?> <?= $method['usdt'] ? 'font-monospace' : '' ?>"
                          id="<?= e($fieldKey) ?>"
                          name="<?= e($fieldKey) ?>"
                          value="<?= e(old($fieldKey, (string) ($method['account'] ?? ''))) ?>"
                          placeholder="<?= e((string) $method['account_hint']) ?>"
                          maxlength="120"
                          inputmode="<?= $method['usdt'] ? 'text' : 'tel' ?>"
                          spellcheck="false"
                        />
                        <div class="form-text">
                          <?= e((string) $method['account_hint']) ?>.
                          <?php if ((string) ($method['account'] ?? '') === ''): ?>
                            You can save it on your <a href="<?= e(url('profile')) ?>">profile</a> so it is filled in next time.
                          <?php endif; ?>
                        </div>
<?php if (has_error($fieldKey)): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for($fieldKey)) ?></div>
<?php endif; ?>
                      </div>
<?php endforeach; ?>

<?php if (has_error('account') && !has_error('account_gcash') && !has_error('account_maya') && !has_error('account_usdt_trc20') && !has_error('account_usdt_bep20')): ?>
                      <div class="alert alert-danger py-2" role="alert"><?= e(error_for('account')) ?></div>
<?php endif; ?>

                      <div class="card bg-label-secondary border-0 mb-4 payout-preview" data-payout-preview>
                        <div class="card-body py-3">
                          <div class="d-flex justify-content-between small">
                            <span class="text-body-secondary">You request</span>
                            <strong data-payout-preview-amount><?= e(money(0)) ?></strong>
                          </div>
                          <div class="d-flex justify-content-between small">
                            <span class="text-body-secondary">Service fee</span>
                            <strong data-payout-preview-fee><?= e(money(0)) ?></strong>
                          </div>
                          <hr class="my-2" />
                          <div class="d-flex justify-content-between">
                            <span class="text-body-secondary">You receive</span>
                            <strong class="text-primary" data-payout-preview-net><?= e(money(0)) ?></strong>
                          </div>
                          <div class="d-flex justify-content-between small mt-1 d-none" data-payout-preview-usdt-row>
                            <span class="text-body-secondary">In USDT</span>
                            <strong class="text-success" data-payout-preview-usdt>0.0000 USDT</strong>
                          </div>
                          <p class="small text-body-secondary mb-0 mt-2 d-none" data-payout-preview-warning role="status" aria-live="polite"></p>
                        </div>
                      </div>

                      <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                          <i class="icon-base bx bx-money-withdraw me-1"></i> Request payout
                        </button>
                      </div>
                      <p class="small text-body-secondary text-center mb-0 mt-2">
                        One request at a time. A rejected request returns the held amount to your wallet.
                      </p>
                    </form>
                  </div>
                </div>
