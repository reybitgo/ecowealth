<?php
declare(strict_types=1);

/**
 * Profile — the add/edit address form.
 *
 * A held region: a background refresh never replaces a partly typed
 * address. The card title and the hidden address id follow `?edit=`.
 *
 * @var list<array<string, mixed>> $addresses
 * @var array<string, mixed>|null  $editing
 * @var array<string, mixed>       $posted
 */

$addresses = $addresses ?? [];
$editing   = $editing ?? null;
$posted    = $posted ?? [];

$addressId = array_key_exists('address_id', $posted)
    ? (int) $posted['address_id']
    : ($editing === null ? 0 : (int) $editing['id']);

$field = static function (string $key, string $default = '') use ($posted, $editing): string {
    if (array_key_exists($key, $posted)) {
        return e((string) $posted[$key]);
    }

    if ($editing !== null) {
        return e((string) ($editing[$key] ?? $default));
    }

    return e($default);
};

$isDefaultChecked = array_key_exists('is_default', $posted)
    ? (int) $posted['is_default'] === 1
    : ($editing !== null
        ? (int) $editing['is_default'] === 1
        : $addresses === []);
?>
                  <div class="card" data-live-region="profile-address-form" data-live-hold="dirty">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0"><?= $editing === null ? 'Add an address' : 'Edit address' ?></h5>
<?php if ($editing !== null): ?>
                      <a href="<?= e(url('profile')) ?>" class="btn btn-sm btn-label-secondary">Cancel edit</a>
<?php endif; ?>
                    </div>
                    <div class="card-body">
<?php if (has_error('address')): ?>
                      <div class="alert alert-danger py-2" role="alert"><?= e(error_for('address')) ?></div>
<?php endif; ?>
                      <form method="POST" action="<?= e(url('profile/address')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="address_id" value="<?= $addressId ?>" />
                        <div class="row g-4">
                          <div class="col-md-6">
                            <label class="form-label" for="address-label">Label <span class="text-body-secondary">(optional)</span></label>
                            <input type="text" id="address-label" name="label" maxlength="40" class="form-control <?= e(invalid_class('label')) ?>" value="<?= $field('label') ?>" placeholder="Home, office…" />
<?php if (has_error('label')): ?>
                            <div class="invalid-feedback"><?= e(error_for('label')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="address-recipient">Recipient name <span class="text-danger">*</span></label>
                            <input type="text" id="address-recipient" name="recipient_name" maxlength="120" required class="form-control <?= e(invalid_class('recipient_name')) ?>" value="<?= $field('recipient_name') ?>" />
<?php if (has_error('recipient_name')): ?>
                            <div class="invalid-feedback"><?= e(error_for('recipient_name')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="address-phone">Phone</label>
                            <input type="text" id="address-phone" name="phone" maxlength="40" class="form-control <?= e(invalid_class('phone')) ?>" value="<?= $field('phone') ?>" />
<?php if (has_error('phone')): ?>
                            <div class="invalid-feedback"><?= e(error_for('phone')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="address-postal">Postal code</label>
                            <input type="text" id="address-postal" name="postal" maxlength="20" class="form-control <?= e(invalid_class('postal')) ?>" value="<?= $field('postal') ?>" />
<?php if (has_error('postal')): ?>
                            <div class="invalid-feedback"><?= e(error_for('postal')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-12">
                            <label class="form-label" for="address-line">Street address <span class="text-danger">*</span></label>
                            <input type="text" id="address-line" name="address_line" maxlength="255" required class="form-control <?= e(invalid_class('address_line')) ?>" value="<?= $field('address_line') ?>" placeholder="House / unit, street, subdivision" />
<?php if (has_error('address_line')): ?>
                            <div class="invalid-feedback"><?= e(error_for('address_line')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="address-barangay">Barangay</label>
                            <input type="text" id="address-barangay" name="barangay" maxlength="120" class="form-control <?= e(invalid_class('barangay')) ?>" value="<?= $field('barangay') ?>" />
<?php if (has_error('barangay')): ?>
                            <div class="invalid-feedback"><?= e(error_for('barangay')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="address-city">City / municipality</label>
                            <input type="text" id="address-city" name="city" maxlength="80" class="form-control <?= e(invalid_class('city')) ?>" value="<?= $field('city') ?>" />
<?php if (has_error('city')): ?>
                            <div class="invalid-feedback"><?= e(error_for('city')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="address-province">Province</label>
                            <input type="text" id="address-province" name="province" maxlength="80" class="form-control <?= e(invalid_class('province')) ?>" value="<?= $field('province') ?>" />
<?php if (has_error('province')): ?>
                            <div class="invalid-feedback"><?= e(error_for('province')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="address-billing-name">Billing name <span class="text-body-secondary">(optional)</span></label>
                            <input type="text" id="address-billing-name" name="billing_name" maxlength="120" class="form-control <?= e(invalid_class('billing_name')) ?>" value="<?= $field('billing_name') ?>" />
<?php if (has_error('billing_name')): ?>
                            <div class="invalid-feedback"><?= e(error_for('billing_name')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="address-billing-phone">Billing phone <span class="text-body-secondary">(optional)</span></label>
                            <input type="text" id="address-billing-phone" name="billing_phone" maxlength="40" class="form-control <?= e(invalid_class('billing_phone')) ?>" value="<?= $field('billing_phone') ?>" />
<?php if (has_error('billing_phone')): ?>
                            <div class="invalid-feedback"><?= e(error_for('billing_phone')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-12">
                            <label class="form-label" for="address-notes">Delivery notes</label>
                            <textarea id="address-notes" name="notes" rows="2" maxlength="500" class="form-control <?= e(invalid_class('notes')) ?>"><?= $field('notes') ?></textarea>
<?php if (has_error('notes')): ?>
                            <div class="invalid-feedback"><?= e(error_for('notes')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-12">
                            <div class="form-check">
                              <input class="form-check-input" type="checkbox" name="is_default" id="address-default" value="1" <?= $isDefaultChecked ? 'checked' : '' ?> />
                              <label class="form-check-label" for="address-default">Use this as my default address</label>
                            </div>
                          </div>
                        </div>
                        <div class="d-flex gap-2 mt-5">
                          <button type="submit" class="btn btn-primary"><?= $editing === null ? 'Save address' : 'Update address' ?></button>
<?php if ($editing !== null): ?>
                          <a href="<?= e(url('profile')) ?>" class="btn btn-label-secondary">Cancel</a>
<?php endif; ?>
                        </div>
                      </form>
                    </div>
                  </div>
