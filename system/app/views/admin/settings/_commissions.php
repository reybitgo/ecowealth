<?php
declare(strict_types=1);

/**
 * Admin settings — the compensation switches (Phase 9–11 keys, exposed
 * in Phase 15) plus the repeat-purchase sub-block (Phase 17B).
 *
 * Both blocks are one card and one form: the hidden `section` field
 * still says `commissions`, so the repeat-purchase keys save, validate
 * and audit exactly like the referral keys beside them (plan §13.5).
 *
 * @var array<string, mixed> $settings
 * @var list<array{key:string, label:string, rule:string, suffix:string, hint:string}> $commission_fields
 * @var array<string, array{label:string, hint:string}> $commission_toggles
 * @var list<array<string, mixed>> $repeat_fields
 * @var array<string, array{label:string, hint:string}> $repeat_toggles
 */

$settings          = $settings ?? [];
$commissionFields  = $commission_fields ?? [];
$commissionToggles = $commission_toggles ?? [];
$repeatFields      = $repeat_fields ?? [];
$repeatToggles     = $repeat_toggles ?? [];

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

                      <hr class="my-5" />

                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h6 class="mb-0">Repeat-purchase bonus</h6>
                        <span class="badge bg-label-primary">Levels 1–10, per product</span>
                      </div>
                      <p class="text-body-secondary small mb-4">
                        Paid on every <strong>paid shop order</strong> to the buyer's sponsor chain, at the ladder
                        each product carries. Independent of the referral bonuses above: it has its own master
                        switch, its own Active rule and its own reversal rule. The per-product ladder is edited on
                        the product form; this block holds the five values that apply to every product.
                      </p>

                      <div class="row g-6">
<?php foreach ($repeatFields as $field): ?>
<?php
    $key  = (string) $field['key'];
    $min  = $field['min'] ?? null;
    $max  = $field['max'] ?? null;
    $step = $field['step'] ?? null;
?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <div class="input-group">
                            <input type="number"
                                   <?= $min !== null ? 'min="' . e((string) $min) . '" ' : '' ?><?= $max !== null ? 'max="' . e((string) $max) . '" ' : '' ?><?= $step !== null ? 'step="' . e((string) $step) . '" ' : '' ?>
                                   name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
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

                      <div class="row g-4 mt-1">
<?php foreach ($repeatToggles as $key => $toggle): ?>
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
