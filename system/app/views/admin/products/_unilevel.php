<?php
declare(strict_types=1);

/**
 * Admin product form — the repeat-purchase ladder card (Phase 17B).
 *
 * ONE ladder, TEN EQUAL ROWS (D-17). Level 1 is the buyer's direct
 * sponsor and is edited and rendered exactly like level 10: no
 * read-only row, no separate "direct" field and no direct/indirect
 * control of any kind. The plan's exit gate asserts that no such
 * control exists in the rendered HTML.
 *
 * The card writes `unilevel_enabled`, `unilevel_base` and
 * `unilevel_levels[1..10]`; the P.V. the base may use lives in the
 * *Pricing & stock* card, not here. Saving is atomic: a refused
 * ladder leaves the product untouched (plan §6 Rule 7).
 *
 * Progressive enhancement: the table already renders its totals and
 * per-unit amounts server-side, and every rule is enforced on the
 * server. app-product-unilevel.js only updates the preview live and
 * fills the reference ladder; with JS off the form still works.
 *
 * @var array<string, mixed>      $current  product row or posted input
 * @var array<int, string>        $unilevel levels 1 … 10 => posted/stored value
 */

$current  = $current ?? [];
$unilevel = $unilevel ?? [];

$enabled = (string) ($current['unilevel_enabled'] ?? '0') === '1';
$baseKey = (string) ($current['unilevel_base'] ?? 'auto');
$baseKey = in_array($baseKey, ProductUnilevel::BASES, true) ? $baseKey : 'auto';

/* The server-side preview: the same numbers the script recomputes.
 * A typed-but-invalid value contributes 0 to the total, exactly like a
 * blank one — validation still refuses it on save. */
$rates = [];
for ($level = ProductUnilevel::LEVEL_MIN; $level <= ProductUnilevel::LEVEL_MAX; $level++) {
    $value = (string) ($unilevel[$level] ?? '');
    $rates[$level] = is_numeric($value) ? (float) $value : 0.0;
}

$base      = ProductUnilevel::baseFor($current, 1);
$totalRate = round(array_sum($rates), 3);

$rateLabel = static function (float $value): string {
    $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

    return $text === '' ? '0' : $text;
};
?>
                    <div class="card mb-6" data-unilevel-card>
                      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0">Unilevel repeat-purchase bonus</h5>
                        <span class="badge bg-label-primary">Levels 1–10</span>
                      </div>
                      <div class="card-body">
                        <p class="text-body-secondary small mb-4">
                          A percentage of the line's bonus base, paid to the buyer's sponsor chain on every
                          <strong>paid</strong> order — level 1 is the direct sponsor, level 10 the tenth upline.
                          A level left at <strong>0</strong> pays nothing but still counts: the next upline keeps its
                          own level number (no compression).
                        </p>

<?php if (has_error('unilevel_levels.sum')): ?>
                        <div class="alert alert-danger py-2" role="alert"><?= e(error_for('unilevel_levels.sum')) ?></div>
<?php endif; ?>

                        <div class="row g-4 align-items-start mb-4">
                          <div class="col-md-7">
                            <div class="form-check form-switch mb-1">
                              <input type="hidden" name="unilevel_enabled" value="0" />
                              <input
                                class="form-check-input <?= e(invalid_class('unilevel_enabled')) ?>"
                                type="checkbox"
                                role="switch"
                                id="productUnilevelEnabled"
                                name="unilevel_enabled"
                                value="1"
                                <?= $enabled ? 'checked' : '' ?>
                              />
                              <label class="form-check-label" for="productUnilevelEnabled">
                                Pay this product's ladder
                              </label>
                            </div>
                            <div class="form-text mb-0">
                              Off by default. The global switch on <a href="<?= e(url('admin/settings#settings-commissions')) ?>">Commissions</a>
                              must be on as well — both have to be on for anything to be paid.
                            </div>
<?php if (has_error('unilevel_enabled')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('unilevel_enabled')) ?></div>
<?php endif; ?>
                          </div>

                          <div class="col-md-5">
                            <label class="form-label" for="productUnilevelBase">Bonus base</label>
                            <select
                              class="form-select <?= e(invalid_class('unilevel_base')) ?>"
                              id="productUnilevelBase"
                              name="unilevel_base"
                            >
                              <option value="auto" <?= $baseKey === 'auto' ? 'selected' : '' ?>>Auto — P.V. when set, else the price</option>
                              <option value="pv" <?= $baseKey === 'pv' ? 'selected' : '' ?>>Point value (P.V.)</option>
                              <option value="price" <?= $baseKey === 'price' ? 'selected' : '' ?>>Retail price</option>
                            </select>
<?php if (has_error('unilevel_base')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('unilevel_base')) ?></div>
<?php else: ?>
                            <div class="form-text mb-0">
                              This unit's base is <strong data-unilevel-base><?= e(money($base['unit'])) ?></strong>
                              (<?= $base['basis'] === 'pv' ? 'P.V.' : 'price' ?>).
                            </div>
<?php endif; ?>
                          </div>
                        </div>

                        <div class="table-responsive">
                          <table class="table align-middle mb-0">
                            <thead>
                              <tr>
                                <th scope="col" style="width: 22%">Level</th>
                                <th scope="col" style="width: 30%">Rate</th>
                                <th scope="col">Pays per unit</th>
                                <th scope="col" class="text-end">Upline</th>
                              </tr>
                            </thead>
                            <tbody>
<?php for ($level = ProductUnilevel::LEVEL_MIN; $level <= ProductUnilevel::LEVEL_MAX; $level++): ?>
<?php
    $value  = (string) ($unilevel[$level] ?? '');
    $amount = round($base['unit'] * $rates[$level] / 100, 2);
?>
                              <tr>
                                <td class="fw-medium">Level <?= $level ?></td>
                                <td>
                                  <div class="input-group input-group-sm" style="max-width: 11rem">
                                    <input
                                      type="number"
                                      class="form-control <?= e(invalid_class('unilevel_levels.' . $level)) ?>"
                                      id="unilevelLevel<?= $level ?>"
                                      name="unilevel_levels[<?= $level ?>]"
                                      min="0"
                                      max="100"
                                      step="0.001"
                                      value="<?= e($value) ?>"
                                      aria-label="Level <?= $level ?> rate"
                                      data-unilevel-rate="<?= $level ?>"
                                    />
                                    <span class="input-group-text">%</span>
                                  </div>
<?php if (has_error('unilevel_levels.' . $level)): ?>
                                  <div class="invalid-feedback d-block"><?= e(error_for('unilevel_levels.' . $level)) ?></div>
<?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                  <span data-unilevel-amount="<?= $level ?>"><?= e(money($amount)) ?></span>
                                </td>
                                <td class="text-end small text-body-secondary">
                                  <?= $level === 1 ? 'Direct sponsor' : 'Upline ' . $level ?>
                                </td>
                              </tr>
<?php endfor; ?>
                            </tbody>
                            <tfoot>
                              <tr>
                                <th scope="row">Total</th>
                                <th scope="row">
                                  <span class="badge bg-label-<?= $totalRate > ProductUnilevel::MAX_SUM ? 'danger' : 'success' ?>" data-unilevel-total>
                                    <?= e(number_format($totalRate, 3, '.', '')) ?>%
                                  </span>
                                </th>
                                <th scope="row" class="text-nowrap">
                                  <span data-unilevel-total-amount><?= e(money(round($base['unit'] * $totalRate / 100, 2))) ?></span>
                                </th>
                                <th scope="row" class="text-end">
                                  <span class="small text-body-secondary">max <?= e($rateLabel(ProductUnilevel::MAX_SUM)) ?>%</span>
                                </th>
                              </tr>
                            </tfoot>
                          </table>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                          <button type="button" class="btn btn-sm btn-label-primary" data-unilevel-preset>
                            <i class="icon-base bx bx-magic-wand me-1"></i> Load reference ladder
                          </button>
                          <button type="button" class="btn btn-sm btn-label-secondary" data-unilevel-clear>
                            <i class="icon-base bx bx-eraser me-1"></i> Clear
                          </button>
                          <span class="small text-body-secondary align-self-center ms-1">
                            Reference: 5 / 5 / 5 / 3 / 3 / 2 / 2 / 2 / 1 / 1 — 29% in total.
                          </span>
                        </div>

                        <p class="text-body-secondary small mb-0 mt-4">
                          Rates are read when an order is <strong>paid</strong>, so an edit here applies to the next
                          payment — the price or P.V. on an already-placed order is snapshotted at placement.
                        </p>
                      </div>
                    </div>
