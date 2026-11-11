<?php
declare(strict_types=1);

/**
 * Admin settings — the compensation switches (Phase 9–11 keys, exposed
 * in Phase 15).
 *
 * @var array<string, mixed> $settings
 * @var list<array{key:string, label:string, rule:string, suffix:string, hint:string}> $commission_fields
 * @var array<string, array{label:string, hint:string}> $commission_toggles
 */

$settings          = $settings ?? [];
$commissionFields  = $commission_fields ?? [];
$commissionToggles = $commission_toggles ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};

$checked = static function (string $key) use ($settings): bool {
    return old($key, (string) ($settings[$key] ?? '0')) === '1';
};
?>
                    <div class="card-body" data-live-region="settings-commissions" data-live-hold="dirty">
                      <div class="row g-6">
<?php foreach ($commissionFields as $field): ?>
<?php $key = (string) $field['key']; ?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <div class="input-group">
                            <input type="number" min="2" max="10" step="1" name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
                                   class="form-control <?= invalid_class($key) ?>" value="<?= $value($key) ?>" />
                            <span class="input-group-text"><?= e((string) $field['suffix']) ?></span>
                          </div>
<?php if (has_error($key)): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for($key)) ?></div>
<?php else: ?>
                          <div class="form-text"><?= e((string) $field['hint']) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>

                      <hr class="my-5" />

                      <div class="row g-4">
<?php foreach ($commissionToggles as $key => $toggle): ?>
<?php $key = (string) $key; ?>
                        <div class="col-12">
                          <div class="form-check form-switch">
                            <input class="form-check-input <?= invalid_class($key) ?>" type="checkbox"
                                   role="switch" name="<?= e($key) ?>" value="1" id="setting-<?= e($key) ?>"
                                   <?= $checked($key) ? 'checked' : '' ?> />
                            <label class="form-check-label" for="setting-<?= e($key) ?>">
                              <?= e((string) $toggle['label']) ?>
                            </label>
                          </div>
                          <p class="text-body-secondary small mb-0 ms-5 ps-2"><?= e((string) $toggle['hint']) ?></p>
<?php if (has_error($key)): ?>
                          <div class="text-danger small mt-1"><?= e(error_for($key)) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>
                    </div>
