<?php
declare(strict_types=1);

/**
 * Checkout — address snapshot, payment method, terms, placement
 * (plan §5.3, §7 Phase 6; reference views/member/checkout.php).
 *
 * Prices and stock come from the cart state the controller re-read from
 * the catalogue; nothing on this form is trusted for money. The hidden
 * idempotency key makes a double submit resolve to one order.
 *
 * The surface follows the shared `config` revision (`data-live-config`),
 * so the repeat-purchase master switch saved on `/admin/settings` adds or
 * removes the summary's preview line without a reload.
 *
 * @var array{
 *   items: list<array<string, mixed>>,
 *   totals: array{items:int, quantity:int, subtotal:float}
 * } $state
 * @var list<array<string, mixed>> $addresses
 * @var array<string, mixed>|null  $selected
 * @var array<string, string>      $prefill
 * @var array{balance:float, withdrawable:float} $wallet
 * @var array<string, array{label:string, icon:string, hint:string, available:bool}> $payment_methods
 * @var string $terms_version
 * @var string $idempotency_key
 * @var int $selected_id
 * @var array{surface:string, enabled:bool, revision:int, etag:string, interval:int} $live
 */

$posted = $posted ?? old_payload();

$value = static function (string $key, string $default = '') use ($posted, $prefill): string {
    if (array_key_exists($key, $posted)) {
        return e((string) $posted[$key]);
    }

    return e((string) ($prefill[$key] ?? $default));
};

/* Terms are accepted by default; a failed submit keeps the unticked state
 * so the member sees exactly what they need to fix. */
$termsChecked = array_key_exists('terms', $posted) ? (string) $posted['terms'] === '1' : true;
$saveAddressDefault = array_key_exists('save_address', $posted)
    ? (bool) $posted['save_address']
    : $addresses === [];
$key = array_key_exists('idempotency_key', $posted) && (string) $posted['idempotency_key'] !== ''
    ? (string) $posted['idempotency_key']
    : $idempotency_key;
$selectedId = (int) ($selected_id ?? (isset($selected) && $selected !== null ? (int) $selected['id'] : 0));

$live = $live ?? [
    'surface'  => Live::checkoutSurface((int) (auth_user()['id'] ?? 0)),
    'enabled'  => false,
    'revision' => 0,
    'etag'     => '',
    'interval' => 30000,
];
?>
            <div
              class="container-xxl flex-grow-1 container-p-y checkout-page"
              data-checkout-page
              data-live-surface="<?= e($live['surface']) ?>"
              data-live-enabled="<?= $live['enabled'] ? '1' : '0' ?>"
              data-live-revision="<?= (int) $live['revision'] ?>"
              data-live-etag="<?= e((string) $live['etag']) ?>"
              data-live-interval="<?= (int) $live['interval'] ?>"
              data-live-config="<?= e(Live::CONFIG) ?>"
            >
              <div class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-none" data-live-stale role="status" aria-live="polite">
                <i class="icon-base bx bx-error-circle fs-5"></i>
                <div>
                  <strong>Your checkout changed in another tab.</strong>
                  Your unsaved payment choice was kept, so this page may be out of date.
                  <a href="<?= e(url('checkout')) ?>" class="alert-link" data-live-reload>
                    Reload the page
                  </a>
                  to see the latest.
                </div>
              </div>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Checkout</h4>
                  <p class="text-body-secondary mb-0">
                    Review your items, choose how to pay, and tell us where to deliver.
                    <span class="small text-body-secondary" data-live-status aria-live="polite"></span>
                  </p>
                </div>
                <a href="<?= e(url('cart')) ?>" class="btn btn-label-secondary">
                  <i class="icon-base bx bx-arrow-back me-1"></i> Back to cart
                </a>
              </div>

              <div class="alert alert-info d-flex align-items-start gap-2 mb-4 d-none" data-checkout-draft role="status" aria-live="polite">
                <i class="icon-base bx bx-history fs-5"></i>
                <div>
                  <strong>Your checkout details were restored.</strong>
                  The delivery details and payment method you typed earlier were filled back in; nothing was submitted.
                  <button type="button" class="btn btn-sm btn-link alert-link p-0 align-baseline" data-checkout-draft-clear>Start over</button>
                </div>
              </div>

<?php if (error_for('checkout') !== ''): ?>
              <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                <i class="icon-base bx bx-error-circle fs-4"></i>
                <span><?= e(error_for('checkout')) ?></span>
              </div>
<?php endif; ?>

              <form method="POST" action="<?= e(url('checkout')) ?>" id="checkoutForm" novalidate data-checkout-posted="<?= $posted === [] ? '0' : '1' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="idempotency_key" value="<?= e($key) ?>" />

                <div class="row g-6">
                  <div class="col-lg-8">
<?= View::capture('shop/checkout/_addresses', [
    'addresses'   => $addresses,
    'selected_id' => $selectedId,
]) ?>

                    <div class="card mb-6 checkout-card">
                      <div class="card-header">
                        <h5 class="mb-0">Delivery details</h5>
                      </div>
                      <div class="card-body">
                        <div class="row g-4">
                          <div class="col-md-6">
                            <label class="form-label" for="shipping_name">Recipient name <span class="text-danger">*</span></label>
                            <input type="text" id="shipping_name" name="shipping_name" maxlength="120" required
                                   class="form-control <?= e(invalid_class('shipping_name')) ?>" value="<?= $value('shipping_name') ?>" />
<?php if (has_error('shipping_name')): ?>
                            <div class="invalid-feedback"><?= e(error_for('shipping_name')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="shipping_phone">Recipient phone</label>
                            <input type="text" id="shipping_phone" name="shipping_phone" maxlength="40"
                                   class="form-control <?= e(invalid_class('shipping_phone')) ?>" value="<?= $value('shipping_phone') ?>" />
<?php if (has_error('shipping_phone')): ?>
                            <div class="invalid-feedback"><?= e(error_for('shipping_phone')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-12">
                            <label class="form-label" for="shipping_address">Street address <span class="text-danger">*</span></label>
                            <input type="text" id="shipping_address" name="shipping_address" maxlength="255" required
                                   placeholder="House / unit, street, subdivision"
                                   class="form-control <?= e(invalid_class('shipping_address')) ?>" value="<?= $value('shipping_address') ?>" />
<?php if (has_error('shipping_address')): ?>
                            <div class="invalid-feedback"><?= e(error_for('shipping_address')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="shipping_barangay">Barangay</label>
                            <input type="text" id="shipping_barangay" name="shipping_barangay" maxlength="120"
                                   class="form-control <?= e(invalid_class('shipping_barangay')) ?>" value="<?= $value('shipping_barangay') ?>" />
<?php if (has_error('shipping_barangay')): ?>
                            <div class="invalid-feedback"><?= e(error_for('shipping_barangay')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="shipping_city">City / municipality</label>
                            <input type="text" id="shipping_city" name="shipping_city" maxlength="80"
                                   class="form-control <?= e(invalid_class('shipping_city')) ?>" value="<?= $value('shipping_city') ?>" />
<?php if (has_error('shipping_city')): ?>
                            <div class="invalid-feedback"><?= e(error_for('shipping_city')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="shipping_province">Province</label>
                            <input type="text" id="shipping_province" name="shipping_province" maxlength="80"
                                   class="form-control <?= e(invalid_class('shipping_province')) ?>" value="<?= $value('shipping_province') ?>" />
<?php if (has_error('shipping_province')): ?>
                            <div class="invalid-feedback"><?= e(error_for('shipping_province')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6 col-xl-3">
                            <label class="form-label" for="shipping_postal">Postal code</label>
                            <input type="text" id="shipping_postal" name="shipping_postal" maxlength="20"
                                   class="form-control <?= e(invalid_class('shipping_postal')) ?>" value="<?= $value('shipping_postal') ?>" />
<?php if (has_error('shipping_postal')): ?>
                            <div class="invalid-feedback"><?= e(error_for('shipping_postal')) ?></div>
<?php endif; ?>
                          </div>

                          <div class="col-12"><hr class="my-2" /></div>

                          <div class="col-md-6">
                            <label class="form-label" for="billing_name">Billing name <span class="text-danger">*</span></label>
                            <input type="text" id="billing_name" name="billing_name" maxlength="120" required
                                   class="form-control <?= e(invalid_class('billing_name')) ?>" value="<?= $value('billing_name') ?>" />
<?php if (has_error('billing_name')): ?>
                            <div class="invalid-feedback"><?= e(error_for('billing_name')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="billing_phone">Billing phone</label>
                            <input type="text" id="billing_phone" name="billing_phone" maxlength="40"
                                   class="form-control <?= e(invalid_class('billing_phone')) ?>" value="<?= $value('billing_phone') ?>" />
<?php if (has_error('billing_phone')): ?>
                            <div class="invalid-feedback"><?= e(error_for('billing_phone')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-12">
                            <label class="form-label" for="notes_member">Delivery notes</label>
                            <textarea id="notes_member" name="notes_member" rows="2" maxlength="500"
                                      placeholder="Landmarks, preferred delivery time…"
                                      class="form-control <?= e(invalid_class('notes_member')) ?>"><?= $value('notes_member') ?></textarea>
<?php if (has_error('notes_member')): ?>
                            <div class="invalid-feedback"><?= e(error_for('notes_member')) ?></div>
<?php endif; ?>
                          </div>
                          <div class="col-12">
                            <div class="form-check">
                              <input class="form-check-input" type="checkbox" name="save_address" id="save_address" value="1" <?= $saveAddressDefault ? 'checked' : '' ?> />
                              <label class="form-check-label" for="save_address">
                                Save this address to my address book for next time.
                              </label>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="card mb-6 checkout-card">
                      <div class="card-header">
                        <h5 class="mb-0">Payment method</h5>
                      </div>
<?= View::capture('shop/checkout/_payment', [
    'payment_methods' => $payment_methods,
    'posted'          => $posted,
]) ?>
                    </div>

                    <div class="card checkout-card">
                      <div class="card-body">
                        <div class="form-check<?= has_error('terms') ? ' is-invalid' : '' ?>">
                          <input class="form-check-input <?= e(invalid_class('terms')) ?>" type="checkbox" name="terms" id="terms" value="1" <?= $termsChecked ? 'checked' : '' ?> required />
                          <label class="form-check-label" for="terms">
                            I agree to the <?= e(APP_NAME) ?> terms (<?= e($terms_version) ?>) and confirm the delivery details above are correct.
                          </label>
<?php if (has_error('terms')): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for('terms')) ?></div>
<?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-lg-4">
                    <div class="card checkout-summary">
                      <div class="card-header">
                        <h5 class="mb-0">Order summary</h5>
                      </div>
<?= View::capture('shop/checkout/_summary', [
    'state'         => $state,
    'wallet'        => $wallet,
    'bonus_preview' => $bonus_preview ?? null,
]) ?>
                      <div class="card-body pt-0">
                        <button
                          type="submit"
                          class="btn btn-primary w-100 mt-4"
                          id="placeOrder"
                          data-checkout-submit
                          <?= $termsChecked ? '' : 'disabled' ?>
                        >
                          <i class="icon-base bx bx-check-shield me-1"></i> Place order
                        </button>
                        <p class="checkout-terms-hint small text-center mt-2 mb-0<?= $termsChecked ? ' d-none' : '' ?>" data-terms-hint>
                          Tick the terms above to place your order.
                        </p>
                        <p class="text-body-secondary small text-center mt-3 mb-0">
                          Manual payments (GCash / Maya / bank) keep the order pending until the company verifies it.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </form>
            </div>
