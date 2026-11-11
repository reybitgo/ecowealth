<?php
declare(strict_types=1);

/**
 * Admin — settings: identity & maintenance, shop, payment accounts,
 * payout methods, payment windows, fulfilment and invoices, commissions
 * and transfers (plan §7 Phases 7–8; the full settings page in Phase 15).
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
 * @var list<array{key:string,label:string,rule:string,max:int,hint:string}> $general_fields
 * @var list<array{title:string, icon:string, method:string, fields:list<array{key:string,label:string,placeholder:string}>}> $account_groups
 * @var list<array{key:string,label:string,hint:string,step:string,suffix:string}> $payout_fields
 * @var list<array{key:string,title:string,icon:string,usdt:bool,toggle:string,fee:string,gas:string}> $payout_channels
 * @var array<string, array{label:string, rule:string, hint:string}> $window_fields
 * @var list<array{key:string,label:string,placeholder:string,type:string}> $seller_fields
 * @var array<string, array{label:string, rule:string, hint:string}> $fulfilment_fields
 * @var list<array{key:string,label:string,rule:string,suffix:string,hint:string}> $commission_fields
 * @var array<string, array{label:string,hint:string}> $commission_toggles
 * @var list<array{key:string,label:string,rule:string,suffix:string,hint:string}> $transfer_fields
 * @var bool $shop_enabled
 * @var bool $expiry_enabled
 * @var bool $autocomplete_enabled
 * @var bool $maintenance_mode
 * @var bool $manual_open
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$settings         = $settings ?? [];
$generalFields    = $general_fields ?? [];
$accountGroups    = $account_groups ?? [];
$payoutFields     = $payout_fields ?? [];
$payoutChannels   = $payout_channels ?? [];
$windowFields     = $window_fields ?? [];
$sellerFields     = $seller_fields ?? [];
$fulfilmentFields = $fulfilment_fields ?? [];
$commissionFields = $commission_fields ?? [];
$commissionToggles = $commission_toggles ?? [];
$transferFields   = $transfer_fields ?? [];
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
                    Identity, where buyers send manual payments, how withdrawals are paid out, how long a buyer has to
                    pay, what appears on the printed invoice, how commissions are earned and what transfers may move.
                    Each card saves on its own and every change is recorded in the audit log.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <span class="badge bg-label-primary">General, payments, payouts, fulfilment, commissions &amp; transfers</span>
              </div>

<?= View::capture('admin/settings/_notice', ['manual_open' => $manualOpen]) ?>

              <div class="row g-6">
                <div class="col-xl-8">
                  <!-- General -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-general" class="card mb-6 settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="general" />
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="mb-1">General</h5>
                        <p class="text-body-secondary small mb-0">Identity and availability</p>
                      </div>
                      <span class="badge bg-label-secondary">Phase 15</span>
                    </div>
<?= View::capture('admin/settings/_general', [
    'settings'         => $settings,
    'general_fields'   => $generalFields,
    'maintenance_mode' => (bool) ($maintenance_mode ?? false),
]) ?>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-buildings me-1"></i>
                        The name appears in the browser title and the layout.
                      </span>
                      <button type="submit" class="btn btn-primary" aria-label="Save general settings">
                        <i class="icon-base bx bx-save me-1"></i> Save settings
                      </button>
                    </div>
                  </form>

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

                  <!-- Payout methods -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-payouts" class="card mb-6 settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="payouts" />
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Payout methods</h5>
                      <span class="small text-body-secondary">Shown on the member&rsquo;s payout page</span>
                    </div>
<?= View::capture('admin/settings/_payouts', [
    'settings'        => $settings,
    'payout_fields'   => $payoutFields,
    'payout_channels' => $payoutChannels,
]) ?>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-money-withdraw me-1"></i>
                        The payout page and the queue pick these up on their next refresh.
                      </span>
                      <button type="submit" class="btn btn-primary" aria-label="Save payout methods">
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

                  <!-- Commissions -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-commissions" class="card mb-6 settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="commissions" />
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="mb-1">Commissions</h5>
                        <p class="text-body-secondary small mb-0">Direct, unilevel and registration switches</p>
                      </div>
                      <span class="badge bg-label-secondary">Phase 15</span>
                    </div>
<?= View::capture('admin/settings/_commissions', [
    'settings'           => $settings,
    'commission_fields'  => $commissionFields,
    'commission_toggles' => $commissionToggles,
]) ?>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-line-chart me-1"></i>
                        A disabled leg pays nothing on the next activation; the ledger keeps every past award.
                      </span>
                      <button type="submit" class="btn btn-primary" aria-label="Save commission settings">
                        <i class="icon-base bx bx-save me-1"></i> Save settings
                      </button>
                    </div>
                  </form>

                  <!-- Transfers -->
                  <form method="POST" action="<?= e($action) ?>" id="settings-transfers" class="card settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="section" value="transfers" />
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="mb-1">Transfers</h5>
                        <p class="text-body-secondary small mb-0">Member-to-member fee and limits</p>
                      </div>
                      <span class="badge bg-label-secondary">Phase 15</span>
                    </div>
<?= View::capture('admin/settings/_transfers', [
    'settings'        => $settings,
    'transfer_fields' => $transferFields,
]) ?>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-transfer me-1"></i>
                        The transfer page and the admin monitor pick these up on their next refresh.
                      </span>
                      <button type="submit" class="btn btn-primary" aria-label="Save transfer settings">
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
                          <i class="icon-base bx bx-money-withdraw text-primary mt-1"></i>
                          <div>
                            <div class="fw-medium">Withdrawals</div>
                            <p class="text-body-secondary small mb-0">
                              Payout methods are the withdrawal side: the minimum, each channel&rsquo;s fee and
                              switch, and the ₱/USDT rate. Members see them on <code>/payouts</code>; a rate left
                              at 0 keeps both USDT channels closed.
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
