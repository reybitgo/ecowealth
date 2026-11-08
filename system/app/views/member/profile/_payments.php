<?php
declare(strict_types=1);

/**
 * Profile — the payout-accounts form.
 *
 * A held region: a background refresh never replaces the payout details
 * the member has typed but not submitted.
 *
 * @var array{gcash_number:string, maya_number:string, usdt_trc20_address:string, usdt_bep20_address:string} $payments
 * @var array<string, mixed> $posted
 */

$posted   = $posted ?? [];
$payments = $payments ?? [
    'gcash_number'       => '',
    'maya_number'        => '',
    'usdt_trc20_address' => '',
    'usdt_bep20_address' => '',
];

$paymentValue = static function (string $key) use ($posted, $payments): string {
    if (array_key_exists($key, $posted)) {
        return e((string) $posted[$key]);
    }

    return e((string) ($payments[$key] ?? ''));
};
?>
                    <div class="card-body" data-live-region="profile-payments" data-live-hold="dirty">
<?php if (has_error('payments')): ?>
                      <div class="alert alert-danger py-2" role="alert"><?= e(error_for('payments')) ?></div>
<?php endif; ?>
                      <form method="POST" action="<?= e(url('profile/payment')) ?>">
                        <?= csrf_field() ?>
                        <div class="row g-4">
                          <div class="col-md-6">
                            <label class="form-label" for="gcash_number">GCash number</label>
                            <input
                              type="tel"
                              inputmode="tel"
                              id="gcash_number"
                              name="gcash_number"
                              maxlength="20"
                              class="form-control <?= e(invalid_class('gcash_number')) ?>"
                              value="<?= $paymentValue('gcash_number') ?>"
                              placeholder="0917 123 4567"
                              autocomplete="tel"
                            />
<?php if (has_error('gcash_number')): ?>
                            <div class="invalid-feedback"><?= e(error_for('gcash_number')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="maya_number">Maya number</label>
                            <input
                              type="tel"
                              inputmode="tel"
                              id="maya_number"
                              name="maya_number"
                              maxlength="20"
                              class="form-control <?= e(invalid_class('maya_number')) ?>"
                              value="<?= $paymentValue('maya_number') ?>"
                              placeholder="0917 123 4567"
                              autocomplete="tel"
                            />
<?php if (has_error('maya_number')): ?>
                            <div class="invalid-feedback"><?= e(error_for('maya_number')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="usdt_trc20_address">USDT (TRC20) address</label>
                            <input
                              type="text"
                              id="usdt_trc20_address"
                              name="usdt_trc20_address"
                              maxlength="100"
                              class="form-control font-monospace <?= e(invalid_class('usdt_trc20_address')) ?>"
                              value="<?= $paymentValue('usdt_trc20_address') ?>"
                              placeholder="T…"
                              spellcheck="false"
                            />
<?php if (has_error('usdt_trc20_address')): ?>
                            <div class="invalid-feedback"><?= e(error_for('usdt_trc20_address')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="usdt_bep20_address">USDT (BEP20) address</label>
                            <input
                              type="text"
                              id="usdt_bep20_address"
                              name="usdt_bep20_address"
                              maxlength="100"
                              class="form-control font-monospace <?= e(invalid_class('usdt_bep20_address')) ?>"
                              value="<?= $paymentValue('usdt_bep20_address') ?>"
                              placeholder="0x…"
                              spellcheck="false"
                            />
<?php if (has_error('usdt_bep20_address')): ?>
                            <div class="invalid-feedback"><?= e(error_for('usdt_bep20_address')) ?></div>
<?php endif; ?>
                          </div>
                        </div>
                        <p class="text-body-secondary small mt-3 mb-4">
                          These details are only used for payouts. Leave a field empty to remove it.
                        </p>
                        <button type="submit" class="btn btn-primary">Save payout details</button>
                      </form>
                    </div>
