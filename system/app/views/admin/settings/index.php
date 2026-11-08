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
 * One live surface (`admin.settings`): each card body is a held region,
 * so a background refresh never replaces typed input; the headers,
 * footers and the explanatory side card stay static.
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
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$settings         = $settings ?? [];
$accountGroups    = $account_groups ?? [];
$windowFields     = $window_fields ?? [];
$sellerFields     = $seller_fields ?? [];
$fulfilmentFields = $fulfilment_fields ?? [];
$manualOpen       = (bool) ($manual_open ?? true);

$live = $live ?? [
    'surface'  => 'admin.settings',
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];

$action = url('admin/settings');
?>
            <div
              class="container-xxl flex-grow-1 container-p-y settings-page"
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
            >
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none" data-live-stale role="status" aria-live="polite">
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>These settings changed in another tab.</strong>
                  Your unsaved input was kept, so this page may be out of date.
                  <a href="<?= e(url('admin/settings')) ?>" class="alert-link" data-live-reload>
                    Reload the page
                  </a>
                  to see the latest.
                </div>
              </div>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Settings</h4>
                  <p class="text-body-secondary mb-0">
                    Where buyers send manual payments, how long they have to pay, and what appears on the
                    printed invoice. Each card saves on its own and every change is recorded in the audit log.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <span class="badge bg-label-primary">Payments, fulfilment &amp; invoices</span>
              </div>

<?= View::capture('admin/settings/_notice', ['manual_open' => $manualOpen]) ?>

              <div class="row g-6">
                <div class="col-xl-8">
                  <!-- Shop -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-shop" class="card mb-6 settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="shop" />
                    <div class="card-header"><h5 class="mb-0">Shop</h5></div>
<?= View::capture('admin/settings/_shop', ['settings' => $settings]) ?>
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
<?= View::capture('admin/settings/_accounts', [
    'settings'       => $settings,
    'account_groups' => $accountGroups,
]) ?>
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
<?= View::capture('admin/settings/_windows', [
    'settings'       => $settings,
    'window_fields'  => $windowFields,
    'expiry_enabled' => (bool) ($expiry_enabled ?? true),
]) ?>
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
<?= View::capture('admin/settings/_fulfilment', [
    'settings'             => $settings,
    'seller_fields'        => $sellerFields,
    'fulfilment_fields'    => $fulfilmentFields,
    'autocomplete_enabled' => (bool) ($autocomplete_enabled ?? true),
]) ?>
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
