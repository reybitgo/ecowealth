<?php
declare(strict_types=1);

/**
 * Checkout — the payment-method card body.
 *
 * A held region: a background refresh never replaces a radio choice the
 * member has made but not submitted. The wallet option's availability
 * follows the live balance, so a top-up in another tab can enable it.
 *
 * @var array<string, array{label:string, icon:string, hint:string, available:bool, balance?:float}> $payment_methods
 * @var array<string, mixed> $posted
 */

$posted          = $posted ?? [];
$payment_methods = $payment_methods ?? [];

$selectedMethod = (string) ($posted['payment_method'] ?? '');

if (!isset($payment_methods[$selectedMethod]) || $payment_methods[$selectedMethod]['available'] !== true) {
    $selectedMethod = '';
    foreach ($payment_methods as $key => $method) {
        if ($method['available']) {
            $selectedMethod = $key;
            break;
        }
    }
}
?>
                      <div class="card-body" data-live-region="checkout-payment" data-live-hold="dirty">
                        <div class="row g-3">
<?php foreach ($payment_methods as $methodKey => $method): ?>
                          <div class="col-12 col-md-6">
                            <label class="checkout-payment-option<?= $method['available'] ? '' : ' is-disabled' ?>">
                              <input
                                type="radio"
                                name="payment_method"
                                value="<?= e($methodKey) ?>"
                                class="form-check-input mt-0"
                                <?= $selectedMethod === $methodKey ? 'checked' : '' ?>
                                <?= $method['available'] ? '' : 'disabled' ?>
                                required
                              />
                              <span class="checkout-payment-icon"><i class="icon-base <?= e(icon_class($method['icon'])) ?>"></i></span>
                              <span class="checkout-payment-text">
                                <span class="d-block fw-medium"><?= e($method['label']) ?></span>
<?php if (isset($method['balance'])): ?>
                                <span class="checkout-payment-balance<?= $method['available'] ? '' : ' is-short' ?>" data-wallet-balance>
                                  Balance <?= e(money((float) $method['balance'])) ?>
                                </span>
<?php endif; ?>
                                <span class="d-block small text-body-secondary"><?= e($method['hint']) ?></span>
                              </span>
                            </label>
                          </div>
<?php endforeach; ?>
                        </div>
<?php if (has_error('payment_method')): ?>
                        <div class="text-danger small mt-2"><?= e(error_for('payment_method')) ?></div>
<?php endif; ?>
                      </div>
