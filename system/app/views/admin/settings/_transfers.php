<?php
declare(strict_types=1);

/**
 * Admin settings — transfer fee and limits (Phase 12 keys, exposed in
 * Phase 15).
 *
 * @var array<string, mixed> $settings
 * @var list<array{key:string, label:string, rule:string, suffix:string, hint:string}> $transfer_fields
 */

$settings       = $settings ?? [];
$transferFields = $transfer_fields ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};
?>
                    <div class="card-body" data-live-region="settings-transfers" data-live-hold="dirty">
                      <div class="row g-6">
<?php foreach ($transferFields as $field): ?>
<?php $key = (string) $field['key']; ?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <div class="input-group">
                            <span class="input-group-text"><?= e((string) $field['suffix']) ?></span>
                            <input type="number" min="0" step="0.01" name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
                                   class="form-control <?= invalid_class($key) ?>" value="<?= $value($key) ?>" />
                          </div>
<?php if (has_error($key)): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for($key)) ?></div>
<?php else: ?>
                          <div class="form-text"><?= e((string) $field['hint']) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>

                      <p class="text-body-secondary small mb-0 mt-4">
                        A limit set to <strong>0</strong> means unlimited. Limits are tracked per member and reset by
                        <code>bin/reset_transfer_limits.php</code>; a transfer refused by a limit moves no money.
                      </p>
                    </div>
