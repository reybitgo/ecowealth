<?php
declare(strict_types=1);

/**
 * Admin settings — identity and maintenance (Phase 1 keys, exposed in
 * Phase 15).
 *
 * @var array<string, mixed> $settings
 * @var list<array{key:string, label:string, rule:string, max:int, hint:string}> $general_fields
 * @var bool $maintenance_mode
 */

$settings      = $settings ?? [];
$generalFields = $general_fields ?? [];
$maintenance   = old('maintenance_mode', ($maintenance_mode ?? false) ? '1' : '0') === '1';

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};
?>
                    <div class="card-body" data-live-region="settings-general" data-live-hold="dirty">
                      <div class="row g-6">
<?php foreach ($generalFields as $field): ?>
<?php $key = (string) $field['key']; ?>
                        <div class="col-md-<?= $key === 'site_tagline' ? '7' : '5' ?>">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <input type="text" name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
                                 class="form-control <?= invalid_class($key) ?>"
                                 value="<?= $value($key) ?>" maxlength="<?= (int) ($field['max'] ?? 80) ?>" />
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
                        <input class="form-check-input <?= invalid_class('maintenance_mode') ?>" type="checkbox"
                               role="switch" name="maintenance_mode" value="1" id="maintenance-mode"
                               <?= $maintenance ? 'checked' : '' ?> />
                        <label class="form-check-label" for="maintenance-mode">Maintenance mode</label>
                      </div>
                      <p class="text-body-secondary small mb-0 mt-2">
                        Visitors and members see a branded <strong>503</strong> page while this is on; admins and
                        superadmins keep working. Cron jobs and the CLI reconciliation are never blocked by it.
                      </p>
<?php if (has_error('maintenance_mode')): ?>
                      <div class="text-danger small mt-1"><?= e(error_for('maintenance_mode')) ?></div>
<?php endif; ?>
                    </div>
