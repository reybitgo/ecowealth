<?php
declare(strict_types=1);

/**
 * Admin settings — the shop switch.
 *
 * @var array<string, mixed> $settings
 */

$settings = $settings ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};
?>
                    <div class="card-body" data-live-region="settings-shop" data-live-hold="dirty">
                      <div class="form-check form-switch">
                        <input class="form-check-input <?= invalid_class('shop_enabled') ?>" type="checkbox"
                               role="switch" name="shop_enabled" value="1" id="shop-enabled"
                               <?= $value('shop_enabled') === '1' ? 'checked' : '' ?> />
                        <label class="form-check-label" for="shop-enabled">
                          The shop is open for orders
                        </label>
                      </div>
                      <p class="text-body-secondary small mb-0 mt-2">
                        Closing the shop blocks new cart additions and checkouts. Uploading a payment proof and
                        the payment review still work, so pending orders are never stranded.
                      </p>
<?php if (has_error('shop_enabled')): ?>
                      <div class="text-danger small mt-1"><?= e(error_for('shop_enabled')) ?></div>
<?php endif; ?>
                    </div>
