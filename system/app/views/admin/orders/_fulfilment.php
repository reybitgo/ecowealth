<?php
declare(strict_types=1);

/**
 * Admin order page — fulfilment.
 *
 * Pack → ready → ship (courier + tracking) → deliver, plus the invoice
 * footer. The card owns forms, so the client keeps unsaved input and shows
 * the stale banner instead of replacing it.
 *
 * @var array<string, mixed> $ctx
 */

$order         = $ctx['order'];
$orderId       = (int) $order['id'];
$status        = (string) $order['status'];
$shipment      = $ctx['shipment'];
$invoice       = $ctx['invoice'];
$hasInvoice    = (bool) $ctx['has_invoice'];
$invoiceUrl    = (string) $ctx['invoice_url'];
$canPack       = (bool) $ctx['can_pack'];
$canReady      = (bool) $ctx['can_ready'];
$canShip       = (bool) $ctx['can_ship'];
$canDeliver    = (bool) $ctx['can_deliver'];
$canConfirm    = (bool) $ctx['can_confirm'];
$autoDays      = (int) $ctx['auto_days'];
$courier       = (string) $ctx['courier'];
$tracking      = (string) $ctx['tracking'];
$courierChoice = (string) $ctx['courier_choice'];
$courierOther  = e(FulfilmentService::OTHER_COURIER);
?>
                  <div
                    class="card mb-6 order-fulfilment-card"
                    data-live-region="order-fulfilment"
                    data-live-hold="dirty"
                  >
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="mb-0">Fulfilment</h5>
                      <?= status_badge($status) ?>
                    </div>
                    <div class="card-body">
<?php if ($canPack): ?>
                      <p class="text-body-secondary small mb-3">
                        Payment is verified. Start packing, then mark the parcel ready for the courier.
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/transition')) ?>" class="row g-3"
                            data-confirm
                            data-confirm-title="Start packing this order?"
                            data-confirm-text="The order moves to Packing. Nothing else changes yet."
                            data-confirm-button="Start packing"
                            data-confirm-cancel="Not yet">
                        <?= csrf_field() ?>
                        <input type="hidden" name="to" value="packing" />
                        <div class="col-12">
                          <label class="form-label" for="pack-note">Note <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="note" id="pack-note" maxlength="200" class="form-control"
                                 value="<?= old('note') ?>" placeholder="Anything the packer should know" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-primary">
                            <i class="icon-base bx bx-box me-1"></i> Start packing
                          </button>
                        </div>
                      </form>
<?php elseif ($canReady): ?>
                      <p class="text-body-secondary small mb-3">
                        The order is being packed. Mark it ready when the parcel is sealed and waiting for pickup.
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/transition')) ?>" class="row g-3"
                            data-confirm
                            data-confirm-title="Mark this order ready to ship?"
                            data-confirm-text="The next step is recording the courier and the tracking number."
                            data-confirm-button="Ready to ship"
                            data-confirm-cancel="Not yet">
                        <?= csrf_field() ?>
                        <input type="hidden" name="to" value="ready_to_ship" />
                        <div class="col-12">
                          <label class="form-label" for="ready-note">Note <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="note" id="ready-note" maxlength="200" class="form-control"
                                 value="<?= old('note') ?>" placeholder="Parcel count, courier instructions…" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-primary">
                            <i class="icon-base bx bx-check-circle me-1"></i> Mark ready to ship
                          </button>
                        </div>
                      </form>
<?php elseif ($canShip): ?>
                      <p class="text-body-secondary small mb-3">
                        Record the courier and the tracking number. Saving this <strong>deducts the stock</strong>
                        from the catalogue and moves the order to Shipped.
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/ship')) ?>" class="row g-3"
                            autocomplete="off"
                            data-confirm
                            data-confirm-title="Ship this order?"
                            data-confirm-text="The stock will be deducted and the order marked Shipped. This cannot be undone here."
                            data-confirm-button="Ship order"
                            data-confirm-cancel="Not yet">
                        <?= csrf_field() ?>
                        <div class="col-sm-6">
                          <label class="form-label" for="ship-courier">Courier</label>
                          <select name="courier" id="ship-courier" autocomplete="off"
                                  class="form-select <?= invalid_class('courier') ?>" required>
                            <option value="">Choose a courier…</option>
<?php foreach (FulfilmentService::courierChoices() as $option): ?>
                            <option value="<?= e($option) ?>" <?= $courierChoice === e($option) ? 'selected' : '' ?>><?= e($option) ?></option>
<?php endforeach; ?>
                            <option value="<?= e($courierOther) ?>" <?= $courierChoice === $courierOther ? 'selected' : '' ?>>Others (type below)</option>
                          </select>
<?php if (has_error('courier')): ?>
                          <div class="invalid-feedback"><?= e(error_for('courier')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                          <label class="form-label" for="ship-tracking">Tracking number</label>
                          <input type="text" name="tracking_number" id="ship-tracking" maxlength="80" autocomplete="off"
                                 class="form-control <?= invalid_class('tracking_number') ?>"
                                 value="<?= old('tracking_number') ?>" placeholder="e.g. JT1234567890" required />
<?php if (has_error('tracking_number')): ?>
                          <div class="invalid-feedback"><?= e(error_for('tracking_number')) ?></div>
<?php endif; ?>
                        </div>
                        <div class="col-sm-6" id="ship-other-wrap" data-courier-other data-courier-other-value="<?= e($courierOther) ?>">
                          <label class="form-label" for="ship-courier-other">Other courier</label>
                          <input type="text" name="courier_other" id="ship-courier-other" maxlength="40" autocomplete="off"
                                 class="form-control <?= invalid_class('courier_other') ?>"
                                 value="<?= old('courier_other') ?>" placeholder="e.g. Flash Express" />
<?php if (has_error('courier_other')): ?>
                          <div class="invalid-feedback"><?= e(error_for('courier_other')) ?></div>
<?php else: ?>
                          <div class="form-text">Only needed when “Others” is selected above.</div>
<?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                          <label class="form-label" for="ship-notes">Shipping note <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="notes" id="ship-notes" maxlength="255" class="form-control" autocomplete="off"
                                 value="<?= old('notes') ?>" placeholder="Box count, fragile, pickup time…" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-primary">
                            <i class="icon-base bx bxs-truck me-1"></i> Ship order
                          </button>
                        </div>
                      </form>
<?php elseif ($canDeliver): ?>
                      <p class="text-body-secondary small mb-3">
                        The parcel is with <?= e($courier !== '' ? $courier : 'the courier') ?>
<?= $tracking !== '' ? ' (tracking ' . e($tracking) . ')' : '' ?>.
                        Mark it delivered when it arrives; the member confirms afterwards.
                      </p>
                      <form method="POST" action="<?= e(url('admin/orders/' . $orderId . '/deliver')) ?>" class="row g-3"
                            data-confirm
                            data-confirm-title="Mark this order delivered?"
                            data-confirm-text="The order moves to Delivered and the member can confirm receipt."
                            data-confirm-button="Mark delivered"
                            data-confirm-cancel="Not yet">
                        <?= csrf_field() ?>
                        <div class="col-12">
                          <label class="form-label" for="deliver-note">Proof of delivery <span class="text-body-secondary">(optional)</span></label>
                          <input type="text" name="note" id="deliver-note" maxlength="200" class="form-control"
                                 value="<?= old('note') ?>" placeholder="Received by, date/time, remarks" />
                        </div>
                        <div class="col-12">
                          <button type="submit" class="btn btn-success">
                            <i class="icon-base bx bx-home-smile me-1"></i> Mark delivered
                          </button>
                        </div>
                      </form>
<?php elseif ($canConfirm): ?>
                      <p class="text-body-secondary small mb-0">
                        Delivered<?= $order['delivered_at'] !== null ? ' on ' . e(format_datetime((string) $order['delivered_at'])) : '' ?>.
                        Waiting for the member to confirm receipt; the auto-complete job closes it
                        <?= $autoDays >= 1 ? (int) $autoDays . ' day' . ($autoDays === 1 ? '' : 's') . ' after delivery' : 'when scheduled' ?>.
                      </p>
<?php elseif ($status === 'completed'): ?>
                      <p class="text-body-secondary small mb-0">
                        Completed<?= $order['completed_at'] !== null ? ' on ' . e(format_datetime((string) $order['completed_at'])) : '' ?>.
                        The invoice is the frozen document for this sale.
                      </p>
<?php elseif ($status === 'cancelled'): ?>
                      <p class="text-body-secondary small mb-0">
                        Cancelled — no fulfilment action is available. Any reserved stock has been released.
                      </p>
<?php else: ?>
                      <p class="text-body-secondary small mb-0">
                        Fulfilment starts once the payment is verified. Verify the payment above first.
                      </p>
<?php endif; ?>
                    </div>
<?php if ($hasInvoice): ?>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span class="small text-body-secondary">
                        <i class="icon-base bx bx-receipt me-1"></i>
                        <?= $invoice !== null && $invoice['voided_at'] !== null ? 'Invoice voided (payment reversed)' : 'Invoice issued' ?>
                      </span>
                      <a href="<?= e($invoiceUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-label-primary">
                        View / print invoice
                      </a>
                    </div>
<?php endif; ?>
                  </div>
