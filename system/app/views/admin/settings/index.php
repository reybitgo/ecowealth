<?php
declare(strict_types=1);

/**
 * Admin — settings: shop, payment accounts, payment windows, fulfilment and
 * the invoice seller block (plan §7 Phases 7–8; the full settings page
 * expands in Phase 15).
 *
 * Each card is its own form and saves on its own. The hidden `section`
 * field tells AdminSettingsController::save() which card's keys to
 * validate, write and audit, so pressing one card's **Save settings**
 * never touches another card's values.
 *
 * @var array<string, mixed> $settings
 * @var list<array{title:string, icon:string, method:string, fields:list<array{key:string,label:string,placeholder:string}>}> $account_groups
 * @var array<string, array{label:string, rule:string, hint:string}> $window_fields
 * @var list<array{key:string,label:string,placeholder:string,type:string}> $seller_fields
 * @var array<string, array{label:string, rule:string, hint:string}> $fulfilment_fields
 * @var bool $shop_enabled
 * @var bool $expiry_enabled
 * @var bool $autocomplete_enabled
 * @var bool $manual_open
 */

$settings   = $settings ?? [];
$accountGroups = $account_groups ?? [];
$windowFields  = $window_fields ?? [];
$sellerFields  = $seller_fields ?? [];
$fulfilmentFields = $fulfilment_fields ?? [];
$manualOpen    = (bool) ($manual_open ?? true);
$expiryChecked = old('shop_expiry_enabled', ($expiry_enabled ?? true) ? '1' : '0') === '1';
$autocompleteChecked = old('shop_autocomplete_enabled', ($autocomplete_enabled ?? true) ? '1' : '0') === '1';

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};

$action = url('admin/settings');
?>
            <div class="container-xxl flex-grow-1 container-p-y settings-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Settings</h4>
                  <p class="text-body-secondary mb-0">
                    Where buyers send manual payments, how long they have to pay, and what appears on the
                    printed invoice. Each card saves on its own and every change is recorded in the audit log.
                  </p>
                </div>
                <span class="badge bg-label-primary">Payments, fulfilment &amp; invoices</span>
              </div>

<?php if (!$manualOpen): ?>
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-6" role="alert">
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>The receiving accounts still look like placeholders.</strong>
                  Replace them with the real GCash / Maya / bank accounts before you accept manual payments;
                  buyers copy them from the checkout and order pages.
                </div>
              </div>
<?php endif; ?>

              <div class="row g-6">
                <div class="col-xl-8">
                  <!-- Shop -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-shop" class="card mb-6 settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="shop" />
                    <div class="card-header"><h5 class="mb-0">Shop</h5></div>
                    <div class="card-body">
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
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-store me-1"></i>
                        Applies to the storefront and the checkout immediately.
                      </span>
                      <button type="submit" class="btn btn-primary" aria-label="Save shop settings">
                        <i class="icon-base bx bx-save me-1"></i> Save settings
                      </button>
                    </div>
                  </form>

                  <!-- Receiving accounts -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-accounts" class="card mb-6 settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="accounts" />
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Receiving accounts</h5>
                      <span class="small text-body-secondary">Shown at checkout and on the order page</span>
                    </div>
                    <div class="card-body">
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
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-receipt me-1"></i>
                        Buyers copy these details on the checkout and order pages.
                      </span>
                      <button type="submit" class="btn btn-primary" aria-label="Save receiving accounts">
                        <i class="icon-base bx bx-save me-1"></i> Save settings
                      </button>
                    </div>
                  </form>

                  <!-- Payment windows -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-windows" class="card mb-6 settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="windows" />
                    <div class="card-header"><h5 class="mb-0">Payment windows</h5></div>
                    <div class="card-body">
                      <div class="row g-6">
<?php foreach ($windowFields as $key => $field): ?>
                        <?php $key = (string) $key; ?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <input type="number" min="1" step="1" name="<?= e($key) ?>" id="setting-<?= e($key) ?>"
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

                      <hr class="my-5" />

                      <div class="form-check form-switch">
                        <input class="form-check-input <?= invalid_class('shop_expiry_enabled') ?>" type="checkbox"
                               role="switch" name="shop_expiry_enabled" value="1" id="shop-expiry-enabled"
                               <?= $expiryChecked ? 'checked' : '' ?> />
                        <label class="form-check-label" for="shop-expiry-enabled">
                          Expire unpaid orders automatically
                        </label>
                      </div>
                      <p class="text-body-secondary small mb-0 mt-2">
                        When this is off, every shop-expiry run is skipped — no order is cancelled by the
                        timer. Payment proofs and the admin review keep working, so nothing is lost; the
                        windows above still apply when you switch it back on.
                      </p>
<?php if (has_error('shop_expiry_enabled')): ?>
                      <div class="text-danger small mt-1"><?= e(error_for('shop_expiry_enabled')) ?></div>
<?php endif; ?>
                    </div>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-time-five me-1"></i>
                        The expiry job reads these windows; see <code>bin/shop_expire.php</code>.
                      </span>
                      <button type="submit" class="btn btn-primary" aria-label="Save payment windows">
                        <i class="icon-base bx bx-save me-1"></i> Save settings
                      </button>
                    </div>
                  </form>

                  <!-- Fulfilment & invoices -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-fulfilment" class="card settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="fulfilment" />
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Fulfilment &amp; invoices</h5>
                      <span class="small text-body-secondary">Printed on every issued invoice</span>
                    </div>
                    <div class="card-body">
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
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-receipt me-1"></i>
                        Frozen at payment · completion runs from <code>bin/order_autocomplete.php</code>.
                      </span>
                      <button type="submit" class="btn btn-primary" aria-label="Save fulfilment and invoice settings">
                        <i class="icon-base bx bx-save me-1"></i> Save settings
                      </button>
                    </div>
                  </form>
                </div>

                <div class="col-xl-4">
                  <div class="card">
                    <div class="card-header"><h5 class="mb-0">How these are used</h5></div>
                    <div class="card-body">
                      <div class="settings-note">
                        <div class="d-flex gap-2">
                          <i class="icon-base bx bx-receipt text-primary mt-1"></i>
                          <div>
                            <div class="fw-medium">Checkout</div>
                            <p class="text-body-secondary small mb-0">
                              The GCash / Maya / bank details appear when the member chooses a manual method.
                              E-wallet checkout does not use them.
                            </p>
                          </div>
                        </div>
                      </div>
                      <div class="settings-note">
                        <div class="d-flex gap-2">
                          <i class="icon-base bx bx-upload text-info mt-1"></i>
                          <div>
                            <div class="fw-medium">Payment proof</div>
                            <p class="text-body-secondary small mb-0">
                              Members upload a JPG, PNG, WebP or PDF up to 5&nbsp;MB. Only the review team can open it.
                            </p>
                          </div>
                        </div>
                      </div>
                      <div class="settings-note">
                        <div class="d-flex gap-2">
                          <i class="icon-base bx bx-check-shield text-success mt-1"></i>
                          <div>
                            <div class="fw-medium">Review</div>
                            <p class="text-body-secondary small mb-0">
                              A verification needs the exact amount and a reference no other order uses. A
                              rejection releases the stock and opens the correction window.
                            </p>
                          </div>
                        </div>
                      </div>
                      <div class="settings-note">
                        <div class="d-flex gap-2">
                          <i class="icon-base bx bx-error text-warning mt-1"></i>
                          <div>
                            <div class="fw-medium">Expiry &amp; completion</div>
                            <p class="text-body-secondary small mb-0">
                              Unpaid orders are cancelled when their window closes; delivered orders complete
                              themselves when the auto-complete window passes.
                            </p>
                          </div>
                        </div>
                      </div>
                      <div class="settings-note mb-0">
                        <div class="d-flex gap-2">
                          <i class="icon-base bx bx-receipt text-primary mt-1"></i>
                          <div>
                            <div class="fw-medium">Invoice</div>
                            <p class="text-body-secondary small mb-0">
                              The seller block below is frozen onto each invoice when an order is paid. Editing
                              it later never rewrites a document that was already issued.
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
