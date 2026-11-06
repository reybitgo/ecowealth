<?php
declare(strict_types=1);

/**
 * Admin — settings: payment accounts and payment windows (plan §7 Phase 7;
 * the full settings page expands in Phase 15).
 *
 * The values here are what buyers see at checkout and on the order page,
 * plus the timing rules the payment lifecycle reads. Every save is audited
 * (`settings.update`).
 *
 * @var array<string, mixed> $settings
 * @var list<array{title:string, icon:string, method:string, fields:list<array{key:string,label:string,placeholder:string}>}> $account_groups
 * @var array<string, array{label:string, rule:string, hint:string}> $window_fields
 * @var bool $shop_enabled
 * @var bool $manual_open
 */

$settings   = $settings ?? [];
$accountGroups = $account_groups ?? [];
$windowFields  = $window_fields ?? [];
$manualOpen    = (bool) ($manual_open ?? true);

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};
?>
            <div class="container-xxl flex-grow-1 container-p-y settings-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Settings</h4>
                  <p class="text-body-secondary mb-0">
                    Where buyers send manual payments, and how long they have to pay. Changes are recorded in the audit log.
                  </p>
                </div>
                <span class="badge bg-label-primary">Payment &amp; windows</span>
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

              <form method="POST" action="<?= e(url('admin/settings')) ?>" class="row g-6 settings-form">
                <?= csrf_field() ?>

                <div class="col-xl-8">
                  <div class="card mb-6">
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
                  </div>

                  <div class="card mb-6">
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
                  </div>

                  <div class="card">
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
                    </div>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-time-five me-1"></i>
                        The expiry job reads these windows; see <code>bin/shop_expire.php</code>.
                      </span>
                      <button type="submit" class="btn btn-primary">
                        <i class="icon-base bx bx-save me-1"></i> Save settings
                      </button>
                    </div>
                  </div>
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
                      <div class="settings-note mb-0">
                        <div class="d-flex gap-2">
                          <i class="icon-base bx bx-error text-warning mt-1"></i>
                          <div>
                            <div class="fw-medium">Expiry</div>
                            <p class="text-body-secondary small mb-0">
                              Unpaid orders are cancelled when their window closes; a proof already under review
                              is never expired.
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </form>
            </div>
