<?php
declare(strict_types=1);

/**
 * Admin settings — the receiving accounts card.
 *
 * @var array<string, mixed> $settings
 * @var list<array{title:string, icon:string, method:string, fields:list<array{key:string,label:string,placeholder:string}>}> $account_groups
 */

$settings      = $settings ?? [];
$accountGroups = $account_groups ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};
?>
                    <div class="card-body" data-live-region="settings-accounts" data-live-hold="dirty">
                      <div class="row g-6">
<?php foreach ($accountGroups as $group): ?>
                        <div class="col-md-6">
                          <div class="settings-account-block">
                            <h6 class="mb-3">
                              <i class="icon-base <?= e(icon_class((string) $group['icon'])) ?> me-1 text-body-secondary"></i>
                              <?= e((string) $group['title']) ?>
                            </h6>
<?php foreach ($group['fields'] as $field): ?>
<?php $key = (string) $field['key']; ?>
                            <div class="mb-3">
                              <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                              <input type="text" name="<?= e($key) ?>" id="setting-<?= e($key) ?>" maxlength="120"
                                     class="form-control <?= invalid_class($key) ?>"
                                     value="<?= $value($key) ?>"
                                     placeholder="<?= e((string) $field['placeholder']) ?>" />
<?php if (has_error($key)): ?>
                              <div class="invalid-feedback"><?= e(error_for($key)) ?></div>
<?php endif; ?>
                            </div>
<?php endforeach; ?>
                          </div>
                        </div>
<?php endforeach; ?>
                      </div>
                    </div>
