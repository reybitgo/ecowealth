<?php
declare(strict_types=1);

/**
 * Admin settings — the shop switch and the order-cancellation switch.
 *
 * `shop_cancel_enabled` is the Phase 18 follow-up kill switch: off by
 * default, it closes the member/admin cancellation path (and with it the
 * wallet refund and the repeat-purchase escrow reversal). Automatic
 * expiry keeps its own switch on the Payment windows card.
 *
 * @var array<string, mixed> $settings
 */

$settings = $settings ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};

$cancelChecked = $value('shop_cancel_enabled') === '1';
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

                      <hr class="my-5" />

                      <div class="form-check form-switch">
                        <input class="form-check-input <?= invalid_class('shop_cancel_enabled') ?>" type="checkbox"
                               role="switch" name="shop_cancel_enabled" value="1" id="shop-cancel-enabled"
                               <?= $cancelChecked ? 'checked' : '' ?> />
                        <label class="form-check-label" for="shop-cancel-enabled">
                          Enable Cancel of Order
                        </label>
                      </div>
                      <p class="text-body-secondary small mb-0 mt-2">
                        <strong>Off by default.</strong> Members and administrators cannot cancel an order, so no
                        cancellation, wallet refund or repeat-purchase escrow reversal is issued. Turn it on only
                        for a special case that needs an order cancelled. Pending repeat-purchase bonuses still
                        release after delivery; automatic expiry of unpaid orders is controlled separately on the
                        Payment windows card.
                      </p>
<?php if (has_error('shop_cancel_enabled')): ?>
                      <div class="text-danger small mt-1"><?= e(error_for('shop_cancel_enabled')) ?></div>
<?php endif; ?>
                    </div>
