<?php
declare(strict_types=1);

/**
 * Admin product form — the product binary card (Phase 22B-S2).
 *
 * ONE switch and ONE read-only readout: `P.V. → points per unit`, plus
 * the self/pair sentence at the global preset. No pair-size, pair-rate
 * or self-rate input lives here — those are global settings (plan D-09,
 * Phase 22B Rule 4). The card is progressive enhancement: the server
 * renders every number, app-product-binary.js only refreshes the readout
 * live when the switch is toggled.
 *
 * @var array<string, mixed> $current product row or posted input
 */

$current = $current ?? [];
$enabled = (string) ($current['binary_enabled'] ?? '0') === '1';
$pv      = (float) ($current['pv'] ?? 0);
$points  = (int) round($pv);

$globalOn      = setting('binary_enabled', '0') === '1';
$selfPct       = (float) setting('binary_self_pct', '5.000');
$pairPoints    = (int) setting('binary_pair_points', '1000');
$pairPct       = (float) setting('binary_pair_pct', '10.000');
$selfPerUnit   = round($points * $selfPct / 100, 2);
$pairValue     = round($pairPoints * $pairPct / 100, 2);
?>
                    <div class="card mb-6" data-binary-card>
                      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0">Product binary points</h5>
                        <span class="badge bg-label-<?= $enabled && $points > 0 ? 'success' : ($enabled ? 'warning' : 'secondary') ?>">
                          <?= $enabled ? 'On' : 'Off' ?>
                        </span>
                      </div>
                      <div class="card-body">
                        <p class="text-body-secondary small mb-4">
                          Every paid order of this product places <strong><?= $points ?></strong> point<?= $points !== 1 ? 's' : '' ?>
                          per unit on the buyer's side of the binary tree (P.V. × quantity, rounded). Points and
                          money stay in separate ledgers — a points bug never moves a peso.
                        </p>

                        <div class="row g-4 align-items-start mb-4">
                          <div class="col-md-7">
                            <div class="form-check form-switch mb-1">
                              <input type="hidden" name="binary_enabled" value="0" />
                              <input
                                class="form-check-input <?= e(invalid_class('binary_enabled')) ?>"
                                type="checkbox"
                                role="switch"
                                id="productBinaryEnabled"
                                name="binary_enabled"
                                value="1"
                                <?= $enabled ? 'checked' : '' ?>
                                data-binary-toggle
                              />
                              <label class="form-check-label" for="productBinaryEnabled">
                                Pay product binary points
                              </label>
                            </div>
                            <div class="form-text mb-0">
                              Off by default. The global stream switch on
                              <a href="<?= e(url('admin/settings#settings-commissions')) ?>">Commissions</a>
                              must be on as well — both have to be on for anything to be paid.
                            </div>
<?php if (has_error('binary_enabled')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('binary_enabled')) ?></div>
<?php endif; ?>
                          </div>

                          <div class="col-md-5">
                            <label class="form-label" for="productBinaryPoints">Points per unit</label>
                            <div class="input-group input-group-sm" style="max-width: 11rem">
                              <span class="form-control" id="productBinaryPoints"><?= e((string) $points) ?></span>
                              <span class="input-group-text">pts</span>
                            </div>
<?php if ($enabled && $pv > 0): ?>
                            <div class="form-text mb-0">
                              <strong><?= e(number_format($pv, 2)) ?></strong> P.V. × 1 unit =
                              <strong data-binary-points><?= e((string) $points) ?></strong> pts per unit.
                            </div>
<?php elseif ($enabled): ?>
                            <div class="form-text mb-0 text-danger">
                              0 points — set a P.V. above 0 in <em>Pricing &amp; stock</em> or switch this off.
                            </div>
<?php else: ?>
                            <div class="form-text mb-0">
                              Switch on to place points on paid orders.
                            </div>
<?php endif; ?>
                          </div>
                        </div>

                        <div class="mb-0">
                          <label class="form-label">What the global preset pays</label>
                          <ul class="list-unstyled small text-body-secondary mb-0">
                            <li>
                              <strong>Self bonus</strong>: <?= e(number_format($selfPct, 3)) ?>% of the order's
                              points → <?= e(money($selfPerUnit)) ?> per unit at this P.V.
                            </li>
                            <li>
                              <strong>Pair</strong>: <?= e(number_format($pairPct, 3)) ?>% of
                              <strong><?= e(number_format($pairPoints)) ?></strong> matched points on each side →
                              <?= e(money($pairValue)) ?> per pair.
                            </li>
                          </ul>
                          <p class="text-body-secondary small mt-2 mb-0">
                            These rates are global — there is no per-product pair rate or self rate. The card
                            updates live when you toggle the switch; every rule is enforced on the server at save.
                          </p>
                        </div>
                      </div>
                    </div>
