<?php
declare(strict_types=1);

/**
 * Admin settings — the seller identity and completion card.
 *
 * @var array<string, mixed> $settings
 * @var list<array{key:string,label:string,placeholder:string,type:string}> $seller_fields
 * @var array<string, array{label:string, rule:string, hint:string}> $fulfilment_fields
 * @var bool $autocomplete_enabled
 */

$settings       = $settings ?? [];
$sellerFields   = $seller_fields ?? [];
$fulfilmentFields = $fulfilment_fields ?? [];
$autocompleteChecked = old('shop_autocomplete_enabled', ($autocomplete_enabled ?? true) ? '1' : '0') === '1';

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};
?>
                    <div class="card-body" data-live-region="settings-fulfilment" data-live-hold="dirty">
                      <h6 class="mb-3">
                        <i class="icon-base bx bx-buildings me-1 text-body-secondary"></i> Seller identity
                      </h6>
                      <div class="row g-6">
<?php foreach ($sellerFields as $field): ?>
<?php $key = (string) $field['key']; ?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
<?php if (($field['type'] ?? 'text') === 'textarea'): ?>
                          <textarea name="<?= e($key) ?>" id="setting-<?= e($key) ?>" rows="3" maxlength="500"
                                    class="form-control <?= invalid_class($key) ?>"
                                    placeholder="<?= e((string) $field['placeholder']) ?>"><?= $value($key) ?></textarea>
<?php else: ?>
                          <input type="text" name="<?= e($key) ?>" id="setting-<?= e($key) ?>" maxlength="160"
                                 class="form-control <?= invalid_class($key) ?>"
                                 value="<?= $value($key) ?>"
                                 placeholder="<?= e((string) $field['placeholder']) ?>" />
<?php endif; ?>
<?php if (has_error($key)): ?>
                          <div class="invalid-feedback"><?= e(error_for($key)) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>

                      <hr class="my-5" />

                      <h6 class="mb-3">
                        <i class="icon-base bx bx-package me-1 text-body-secondary"></i> Completion
                      </h6>
                      <div class="row g-6">
<?php foreach ($fulfilmentFields as $key => $field): ?>
<?php $key = (string) $key; ?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <input type="number" min="1" max="365" step="1" name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
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

                      <div class="form-check form-switch mt-4">
                        <input class="form-check-input <?= invalid_class('shop_autocomplete_enabled') ?>" type="checkbox"
                               role="switch" name="shop_autocomplete_enabled" value="1" id="shop-autocomplete-enabled"
                               <?= $autocompleteChecked ? 'checked' : '' ?> />
                        <label class="form-check-label" for="shop-autocomplete-enabled">
                          Complete delivered orders automatically
                        </label>
                      </div>
                      <p class="text-body-secondary small mb-0 mt-2">
                        When this is off, every auto-complete run is skipped and a delivered order waits for the
                        member to confirm. The shipped → delivered steps are unaffected.
                      </p>
<?php if (has_error('shop_autocomplete_enabled')): ?>
                      <div class="text-danger small mt-1"><?= e(error_for('shop_autocomplete_enabled')) ?></div>
<?php endif; ?>
                    </div>
