<?php
declare(strict_types=1);

/**
 * Admin package form — the package binary card (Phase 23B-S1).
 *
 * TWO controls: the per-package switch and the points one activation
 * contributes. The switch is off on create; the points field suggests
 * ROUND(product_value) but saves nothing until the operator submits it.
 * No pair-size, pair-rate, cap or hold input exists here — those are
 * the shared global settings (plan D-06, Rule 1). The card is
 * progressive enhancement: the server renders every number and every
 * rule; app-package-binary.js only refreshes the readout live.
 *
 * @var array<string, mixed> $current package row or posted input
 * @var bool                 $isEdit  true when editing an existing package
 * @var int                  $id      package id (0 on create)
 */

$current = $current ?? [];
$isEdit  = $isEdit ?? false;
$id      = (int) ($id ?? 0);

$enabled     = (string) ($current['binary_enabled'] ?? '0') === '1';
$pointsPosted = (string) ($current['binary_points'] ?? '');
$productValue = (float) ($current['product_value'] ?? 0);

/* On create with no posted value, suggest ROUND(product_value). On edit
 * or after a post, the posted/stored value wins. */
if ($pointsPosted === '' && !$isEdit) {
    $pointsPosted = (string) max(0, (int) round($productValue));
}
$points  = (int) ($pointsPosted === '' ? 0 : $pointsPosted);

$globalOn   = setting('package_binary_enabled', '0') === '1';
$pairPoints = (int) setting('binary_pair_points', '1000');
$pairPct    = (float) setting('binary_pair_pct', '10.000');
$pairValue  = round($pairPoints * $pairPct / 100, 2);
$pairs      = $pairPoints > 0 ? (int) floor($points / $pairPoints) : 0;
?>
                    <div class="card mb-6" data-binary-card
                         data-binary-pair-points="<?= $pairPoints ?>"
                         data-binary-pair-value="<?= e(number_format($pairValue, 2)) ?>">
                      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0">Package binary</h5>
                        <span class="badge bg-label-<?= $enabled && $points > 0 ? 'success' : ($enabled ? 'warning' : 'secondary') ?>">
                          <?= $enabled ? 'On' : 'Off' ?>
                        </span>
                      </div>
                      <div class="card-body">
                        <p class="text-body-secondary small mb-4">
                          When a member's activation of this package is approved,
                          <strong><?= $points ?></strong> point<?= $points !== 1 ? 's' : '' ?> are contributed
                          once to the binary tree — team only, never to the buyer, never a self bonus.
                          Points are whole numbers; money uses the shared pair value.
                        </p>

                        <div class="row g-4 align-items-start mb-4">
                          <div class="col-md-7">
                            <div class="form-check form-switch mb-1">
                              <input type="hidden" name="binary_enabled" value="0" />
                              <input
                                class="form-check-input <?= e(invalid_class('binary_enabled')) ?>"
                                type="checkbox"
                                role="switch"
                                id="packageBinaryEnabled"
                                name="binary_enabled"
                                value="1"
                                <?= $enabled ? 'checked' : '' ?>
                                data-binary-toggle
                              />
                              <label class="form-check-label" for="packageBinaryEnabled">
                                Package binary enabled
                              </label>
                            </div>
                            <div class="form-text mb-0">
                              Off by default. The package stream's master switch on
                              <a href="<?= e(url('admin/settings#settings-commissions')) ?>">Commissions</a>
                              must also be on before anything is paid.
                            </div>
<?php if (has_error('binary_enabled')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('binary_enabled')) ?></div>
<?php endif; ?>
                          </div>

                          <div class="col-md-5">
                            <label class="form-label" for="packageBinaryPoints">Points per activation <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm" style="max-width: 11rem">
                              <span class="input-group-text"><?= e(CURRENCY_SYMBOL ?? '₱') ?></span>
                              <input
                                type="number"
                                class="form-control <?= e(invalid_class('binary_points')) ?>"
                                id="packageBinaryPoints"
                                name="binary_points"
                                min="0"
                                max="1000000"
                                step="1"
                                value="<?= e($pointsPosted) ?>"
                                data-binary-points-input
                              />
                              <span class="input-group-text">pts</span>
                            </div>
<?php if (has_error('binary_points')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('binary_points')) ?></div>
<?php endif; ?>
                            <div class="form-text mb-0">
                              Suggests <strong><?= e((string) max(0, (int) round($productValue))) ?></strong> pts
                              from the product value — the operator decides. Whole numbers only, 0–1,000,000.
                            </div>
                          </div>
                        </div>

                        <div class="mb-0">
                          <label class="form-label">What one approved activation places and pays</label>
                          <ul class="list-unstyled small text-body-secondary mb-0">
                            <li>
                              <strong>Points placed</strong>: <strong data-binary-points><?= e((string) $points) ?></strong> pts
                              per activation, snapshotted on the activation row.
                            </li>
                            <li>
                              <strong>Pair equivalent</strong>: one pair is
                              <strong><?= e((string) $pairPoints) ?></strong> matched points at
                              <strong><?= e(number_format($pairPct, 3)) ?>%</strong> →
                              <strong><?= e(money($pairValue)) ?></strong> per pair.
                            </li>
                            <li>
                              <strong>Pairs this activation could complete</strong>:
                              <strong data-binary-pairs><?= e((string) $pairs) ?></strong> — if the opposite side
                              already holds at least <?= e((string) $pairPoints) ?> pts per pair.
                            </li>
                            <li>
                              <strong>Money one pair completes</strong>:
                              <strong data-binary-money><?= e(money($pairs * $pairValue)) ?></strong> per activation when both sides match.
                            </li>
                          </ul>
                          <p class="text-body-secondary small mt-2 mb-0">
                            These rates are global — there is no per-package pair rate, cap or hold.
                            The card updates live when you toggle the switch or type points;
                            every rule is enforced on the server at save.
                          </p>
                        </div>
                      </div>
                    </div>
