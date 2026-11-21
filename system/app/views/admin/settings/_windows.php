<?php
declare(strict_types=1);

/**
 * Admin settings — the payment window card.
 *
 * @var array<string, mixed> $settings
 * @var array<string, array{label:string, rule:string, hint:string}> $window_fields
 * @var bool $expiry_enabled
 */

$settings     = $settings ?? [];
$windowFields = $window_fields ?? [];
$expiryChecked = old('shop_expiry_enabled', ($expiry_enabled ?? true) ? '1' : '0') === '1';

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};
?>
                    <div class="card-body" data-live-region="settings-windows" data-live-hold="dirty">
                      <div class="row g-6">
<?php foreach ($windowFields as $key => $field): ?>
<?php $key = (string) $key; ?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <input type="number" min="1" step="1" name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
                                 class="form-control <?= invalid_class($key) ?>"
                                 value="<?= $value($key) ?>" />
<?php if (has_error($key)): ?>
                          <div class="invalid-feedback"><?= e(error_for($key)) ?></div>
<?php else: ?>
                          <div class="form-text"><?= e((string) $field['hint']) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>

                      <hr class="my-5" />

                      <div class="form-check form-switch">
                        <input class="form-check-input <?= invalid_class('shop_expiry_enabled') ?>" type="checkbox"
                               role="switch" name="shop_expiry_enabled" value="1" id="shop-expiry-enabled"
                               <?= $expiryChecked ? 'checked' : '' ?> />
                        <label class="form-check-label" for="shop-expiry-enabled">
                          Expire unpaid orders automatically
                        </label>
                      </div>
                      <p class="text-body-secondary small mb-0 mt-2">
                        When this is off, every shop-expiry run is skipped — no order is cancelled by the
                        timer. Payment proofs and the admin review keep working, so nothing is lost; the
                        windows above still apply when you switch it back on.
                      </p>
<?php if (has_error('shop_expiry_enabled')): ?>
                      <div class="text-danger small mt-1"><?= e(error_for('shop_expiry_enabled')) ?></div>
<?php endif; ?>
                    </div>
