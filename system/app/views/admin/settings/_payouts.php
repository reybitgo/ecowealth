<?php
declare(strict_types=1);

/**
 * Admin settings — the payout methods card.
 *
 * The withdrawal floor, each channel's switch and service fee, and the
 * ₱/USDT rate with its network fees. `PayoutService` reads exactly these
 * keys, so this card is what the member's `/payouts` page and the admin
 * queue print: changing the rate here opens (or closes) the two USDT
 * channels on both surfaces after their next refresh.
 *
 * @var array<string, mixed> $settings
 * @var list<array{key:string,label:string,hint:string,step:string,suffix:string}> $payout_fields
 * @var list<array{key:string,title:string,icon:string,usdt:bool,toggle:string,fee:string,gas:string}> $payout_channels
 */

$settings       = $settings ?? [];
$payoutFields   = $payout_fields ?? [];
$payoutChannels = $payout_channels ?? [];

$value = static function (string $key) use ($settings): string {
    return old($key, (string) ($settings[$key] ?? ''));
};

$usdtRate = (float) $value('usdt_rate');
?>
                    <div class="card-body" data-live-region="settings-payouts" data-live-hold="dirty">
                      <div class="row g-6">
<?php foreach ($payoutFields as $field): ?>
<?php $key = (string) $field['key']; ?>
                        <div class="col-md-6">
                          <label class="form-label" for="setting-<?= e($key) ?>"><?= e((string) $field['label']) ?></label>
                          <div class="input-group">
                            <input
                              type="number"
                              min="0"
                              step="<?= e((string) $field['step']) ?>"
                              name="<?= e($key) ?>"
                              id="setting-<?= e($key) ?>"
                              class="form-control <?= e(invalid_class($key)) ?>"
                              value="<?= $value($key) ?>"
                              inputmode="decimal"
                            />
                            <span class="input-group-text"><?= e((string) $field['suffix']) ?></span>
                          </div>
<?php if (has_error($key)): ?>
                          <div class="invalid-feedback d-block"><?= e(error_for($key)) ?></div>
<?php else: ?>
                          <div class="form-text"><?= e((string) $field['hint']) ?></div>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                      </div>

<?php if ($usdtRate <= 0): ?>
                      <div class="alert alert-warning d-flex align-items-start gap-2 py-2 mt-4 mb-0" role="status">
                        <i class="icon-base bx bx-error-circle mt-1"></i>
                        <div class="small">
                          <strong>USDT payouts are closed.</strong>
                          The two USDT channels stay unavailable on <code>/payouts</code> until the rate above is set;
                          a request is refused rather than converted at a guessed rate.
                        </div>
                      </div>
<?php endif; ?>

                      <hr class="my-5" />

                      <h6 class="mb-3">
                        <i class="icon-base bx bx-money-withdraw me-1 text-body-secondary"></i> Channels
                      </h6>
                      <div class="row g-6">
<?php foreach ($payoutChannels as $channel): ?>
<?php
    $toggleKey = (string) $channel['toggle'];
    $feeKey    = (string) $channel['fee'];
    $gasKey    = (string) $channel['gas'];
    $checked   = $value($toggleKey) === '1';
?>
                        <div class="col-md-6">
                          <div class="settings-account-block">
                            <h6 class="mb-3">
                              <i class="icon-base <?= e(icon_class((string) $channel['icon'])) ?> me-1 text-body-secondary"></i>
                              <?= e((string) $channel['title']) ?>
                            </h6>

                            <div class="form-check form-switch mb-3">
                              <input
                                class="form-check-input <?= e(invalid_class($toggleKey)) ?>"
                                type="checkbox"
                                role="switch"
                                name="<?= e($toggleKey) ?>"
                                value="1"
                                id="setting-<?= e($toggleKey) ?>"
                                <?= $checked ? 'checked' : '' ?>
                              />
                              <label class="form-check-label" for="setting-<?= e($toggleKey) ?>">
                                <?= e((string) $channel['title']) ?> payouts are open
                              </label>
                            </div>
<?php if (has_error($toggleKey)): ?>
                            <div class="text-danger small mb-3"><?= e(error_for($toggleKey)) ?></div>
<?php endif; ?>

                            <div class="<?= $gasKey === '' ? 'mb-0' : 'mb-3' ?>">
                              <label class="form-label" for="setting-<?= e($feeKey) ?>">Service fee</label>
                              <div class="input-group">
                                <input
                                  type="number"
                                  min="0"
                                  max="100"
                                  step="0.01"
                                  name="<?= e($feeKey) ?>"
                                  id="setting-<?= e($feeKey) ?>"
                                  class="form-control <?= e(invalid_class($feeKey)) ?>"
                                  value="<?= $value($feeKey) ?>"
                                  inputmode="decimal"
                                />
                                <span class="input-group-text">%</span>
                              </div>
<?php if (has_error($feeKey)): ?>
                              <div class="invalid-feedback d-block"><?= e(error_for($feeKey)) ?></div>
<?php endif; ?>
                            </div>

<?php if ($gasKey !== ''): ?>
                            <div class="mb-0">
                              <label class="form-label" for="setting-<?= e($gasKey) ?>">Network fee</label>
                              <div class="input-group">
                                <input
                                  type="number"
                                  min="0"
                                  step="0.0001"
                                  name="<?= e($gasKey) ?>"
                                  id="setting-<?= e($gasKey) ?>"
                                  class="form-control <?= e(invalid_class($gasKey)) ?>"
                                  value="<?= $value($gasKey) ?>"
                                  inputmode="decimal"
                                />
                                <span class="input-group-text">USDT</span>
                              </div>
<?php if (has_error($gasKey)): ?>
                              <div class="invalid-feedback d-block"><?= e(error_for($gasKey)) ?></div>
<?php endif; ?>
                            </div>
<?php endif; ?>
                          </div>
                        </div>
<?php endforeach; ?>
                      </div>

                      <p class="small text-body-secondary mb-0 mt-4">
                        These rules apply to withdrawals only — the receiving accounts card above is what buyers pay
                        into. A request stores the fee, rate and network fee it was quoted, so a later change never
                        rewrites a queued request.
                      </p>
                    </div>
