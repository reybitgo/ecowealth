<?php
declare(strict_types=1);

/**
 * Member profile + address book (plan §5.3, Phase 6; reference
 * views/member/profile.php).
 *
 * The address book saves the delivery details checkout uses. Editing
 * an address reopens the same form; one address is the default and
 * checkout pre-fills from it.
 *
 * @var array<string, mixed> $user
 * @var list<array<string, mixed>> $addresses
 * @var array<string, mixed>|null $editing
 * @var array{gcash_number:string, maya_number:string, usdt_trc20_address:string, usdt_bep20_address:string} $payments
 * @var array<string, mixed> $posted
 */

$posted   = $posted ?? [];
$editing  = $editing ?? null;
$payments = $payments ?? [
    'gcash_number'       => '',
    'maya_number'        => '',
    'usdt_trc20_address' => '',
    'usdt_bep20_address' => '',
];

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

$paymentValue = static function (string $key) use ($posted, $payments): string {
    if (array_key_exists($key, $posted)) {
        return e((string) $posted[$key]);
    }

    return e((string) ($payments[$key] ?? ''));
};

$fullName = old('full_name') !== ''
    ? old('full_name')
    : e((string) ($user['full_name'] ?? ''));
?>
            <div class="container-xxl flex-grow-1 container-p-y profile-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Profile</h4>
                  <p class="text-body-secondary mb-0">
                    Your account details, payout accounts and the addresses checkout pre-fills.
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e(url('wallet')) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> E-wallet
                  </a>
                  <a href="<?= e(url('profile/password')) ?>" class="btn btn-label-secondary">
                    <i class="icon-base bx bx-lock-alt me-1"></i> Change password
                  </a>
                </div>
              </div>

              <div class="row g-6">
                <div class="col-lg-4">
                  <div class="card mb-6">
                    <div class="card-body text-center">
                      <div class="avatar avatar-xl mx-auto mb-3">
                        <span class="avatar-initial rounded-circle bg-label-primary fs-4"><?= e(initials((string) $user['username'])) ?></span>
                      </div>
                      <h5 class="mb-1"><?= e((string) $user['username']) ?></h5>
                      <p class="text-body-secondary small mb-3"><?= e((string) $user['email']) ?></p>
                      <div class="d-flex justify-content-center gap-2">
                        <span class="badge bg-label-info"><?= e(ucfirst((string) $user['role'])) ?></span>
                        <span class="badge bg-label-secondary">Member since <?= e(date('M Y', (int) strtotime((string) $user['created_at']))) ?></span>
                      </div>
                    </div>
                  </div>

                  <div class="card">
                    <div class="card-header"><h5 class="mb-0">Account details</h5></div>
                    <div class="card-body">
                      <form method="POST" action="<?= e(url('profile')) ?>">
                        <?= csrf_field() ?>
                        <div class="mb-4">
                          <label class="form-label" for="full_name">Full name</label>
                          <input type="text" id="full_name" name="full_name" maxlength="100" required
                                 class="form-control <?= e(invalid_class('full_name')) ?>" value="<?= $fullName ?>" />
<?php if (has_error('full_name')): ?>
                          <div class="invalid-feedback"><?= e(error_for('full_name')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="mb-4">
                          <label class="form-label" for="profile-username">Username</label>
                          <input type="text" id="profile-username" class="form-control" value="<?= e((string) $user['username']) ?>" disabled />
                        </div>
                        <div class="mb-4">
                          <label class="form-label" for="profile-email">Email</label>
                          <input type="email" id="profile-email" class="form-control" value="<?= e((string) $user['email']) ?>" disabled />
                        </div>
                        <button type="submit" class="btn btn-primary">Save profile</button>
                      </form>
                    </div>
                  </div>
                </div>

                <div class="col-lg-8">
                  <div class="card mb-6" id="payout-details">
                    <div class="card-header">
                      <h5 class="mb-1">Payout accounts</h5>
                      <p class="text-body-secondary small mb-0">
                        Where the company sends your withdrawals. The GCash, Maya and bank details shown on an
                        order are the company's receiving accounts — these are your own payout accounts.
                      </p>
                    </div>
                    <div class="card-body">
<?php if (has_error('payments')): ?>
                      <div class="alert alert-danger py-2" role="alert"><?= e(error_for('payments')) ?></div>
<?php endif; ?>
                      <form method="POST" action="<?= e(url('profile/payment')) ?>">
                        <?= csrf_field() ?>
                        <div class="row g-4">
                          <div class="col-md-6">
                            <label class="form-label" for="gcash_number">GCash number</label>
                            <input
                              type="tel"
                              inputmode="tel"
                              id="gcash_number"
                              name="gcash_number"
                              maxlength="20"
                              class="form-control <?= e(invalid_class('gcash_number')) ?>"
                              value="<?= $paymentValue('gcash_number') ?>"
                              placeholder="0917 123 4567"
                              autocomplete="tel"
                            />
<?php if (has_error('gcash_number')): ?>
                            <div class="invalid-feedback"><?= e(error_for('gcash_number')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="maya_number">Maya number</label>
                            <input
                              type="tel"
                              inputmode="tel"
                              id="maya_number"
                              name="maya_number"
                              maxlength="20"
                              class="form-control <?= e(invalid_class('maya_number')) ?>"
                              value="<?= $paymentValue('maya_number') ?>"
                              placeholder="0917 123 4567"
                              autocomplete="tel"
                            />
<?php if (has_error('maya_number')): ?>
                            <div class="invalid-feedback"><?= e(error_for('maya_number')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="usdt_trc20_address">USDT (TRC20) address</label>
                            <input
                              type="text"
                              id="usdt_trc20_address"
                              name="usdt_trc20_address"
                              maxlength="100"
                              class="form-control font-monospace <?= e(invalid_class('usdt_trc20_address')) ?>"
                              value="<?= $paymentValue('usdt_trc20_address') ?>"
                              placeholder="T…"
                              spellcheck="false"
                            />
<?php if (has_error('usdt_trc20_address')): ?>
                            <div class="invalid-feedback"><?= e(error_for('usdt_trc20_address')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="usdt_bep20_address">USDT (BEP20) address</label>
                            <input
                              type="text"
                              id="usdt_bep20_address"
                              name="usdt_bep20_address"
                              maxlength="100"
                              class="form-control font-monospace <?= e(invalid_class('usdt_bep20_address')) ?>"
                              value="<?= $paymentValue('usdt_bep20_address') ?>"
                              placeholder="0x…"
                              spellcheck="false"
                            />
<?php if (has_error('usdt_bep20_address')): ?>
                            <div class="invalid-feedback"><?= e(error_for('usdt_bep20_address')) ?></div>
<?php endif; ?>
                          </div>
                        </div>
                        <p class="text-body-secondary small mt-3 mb-4">
                          These details are only used for payouts. Leave a field empty to remove it.
                        </p>
                        <button type="submit" class="btn btn-primary">Save payout details</button>
                      </form>
                    </div>
                  </div>

                  <div class="card mb-6">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="mb-0">Address book</h5>
                        <p class="text-body-secondary small mb-0">The default address pre-fills checkout.</p>
                      </div>
                      <span class="badge bg-label-primary"><?= count($addresses) ?> saved</span>
                    </div>
<?php if ($addresses === []): ?>
                    <div class="card-body">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-map-pin',
        'title' => 'No saved addresses yet',
        'text'  => 'Add the address you want checkout to pre-fill.',
    ]);
?>
                    </div>
<?php else: ?>
                    <div class="card-body">
                      <div class="row g-4">
<?php foreach ($addresses as $address): ?>
                        <div class="col-12 col-md-6">
                          <div class="address-card<?= (int) $address['is_default'] === 1 ? ' is-default' : '' ?>" data-address-id="<?= (int) $address['id'] ?>">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                              <div>
                                <div class="fw-semibold"><?= e((string) $address['recipient_name']) ?></div>
<?php if (trim((string) ($address['label'] ?? '')) !== ''): ?>
                                <div class="small text-body-secondary"><?= e((string) $address['label']) ?></div>
<?php endif; ?>
                              </div>
<?php if ((int) $address['is_default'] === 1): ?>
                              <span class="badge bg-label-primary">Default</span>
<?php endif; ?>
                            </div>
                            <div class="small text-body-secondary mt-2">
                              <?= e(implode(', ', array_filter([
                                  (string) $address['address_line'],
                                  (string) ($address['barangay'] ?? ''),
                                  (string) ($address['city'] ?? ''),
                                  (string) ($address['province'] ?? ''),
                                  (string) ($address['postal'] ?? ''),
                              ]))) ?>
                            </div>
<?php if (trim((string) ($address['phone'] ?? '')) !== ''): ?>
                            <div class="small text-body-secondary mt-1"><?= e((string) $address['phone']) ?></div>
<?php endif; ?>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                              <a href="<?= e(url('profile?edit=' . (int) $address['id'])) ?>" class="btn btn-sm btn-label-primary">Edit</a>
<?php if ((int) $address['is_default'] !== 1): ?>
                              <form method="POST" action="<?= e(url('profile/address/' . (int) $address['id'] . '/default')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-label-secondary">Make default</button>
                              </form>
<?php endif; ?>
                              <form method="POST" action="<?= e(url('profile/address/' . (int) $address['id'] . '/delete')) ?>" onsubmit="return confirm('Delete this address?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-label-danger">Delete</button>
                              </form>
                            </div>
                          </div>
                        </div>
<?php endforeach; ?>
                      </div>
                    </div>
<?php endif; ?>
                  </div>

                  <div class="card" id="address-form">
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
                </div>
              </div>
            </div>
