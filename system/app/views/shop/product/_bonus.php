<?php
declare(strict_types=1);

/**
 * Product detail — the repeat-purchase ladder panel (Phase 17E, plan §9).
 *
 * What this panel is allowed to say is normative (plan §9, Rules 1–6):
 *
 *   · it says "repeat-purchase bonus", never "unilevel" alone;
 *   · it says "up to", never "you will earn";
 *   · it says the money is credited when the order is paid;
 *   · it never calls the money a rebate or cashback — the buyer is not
 *     a recipient, and the panel says so in one deliberate sentence;
 *   · it never labels level 1 "direct" or the rest "indirect": one
 *     ladder, the level number is the whole story (D-17);
 *   · all ten levels are always listed, and a level with no rate reads
 *     "—" instead of "0.000%".
 *
 * The numbers come from `UnilevelService::quoteLine($id, 1)` (17C), so
 * the panel, the checkout line and the engine share one definition of
 * every amount. The quote is a preview: the real chain and the
 * recipients' Active flags decide what is actually paid.
 *
 * @var array<string, mixed> $bonus quoteLine() plus total_percent/paying_levels
 */

$levels       = is_array($bonus['levels'] ?? null) ? $bonus['levels'] : [];
$totalPercent = (float) ($bonus['total_percent'] ?? 0);
$totalAmount  = (float) ($bonus['total'] ?? 0);
$payingLevels = (int) ($bonus['paying_levels'] ?? 0);
$base         = (float) ($bonus['base'] ?? 0);
$baseSource   = (string) ($bonus['base_source'] ?? 'price');
$baseLabel    = $baseSource === 'pv' ? 'P.V.' : 'price';
?>
                    <div class="product-bonus-panel" data-bonus-panel>
                      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                        <div>
                          <h6 class="mb-1">
                            <i class="icon-base bx bx-gift me-1"></i> Repeat-purchase bonus
                          </h6>
                          <p class="text-body-secondary small mb-0">
                            When this order is paid, up to
                            <strong><?= e(number_format($totalPercent, 3, '.', '')) ?>%</strong>
                            of its bonus base goes to your sponsor chain — one share per level, levels 1&ndash;10.
                          </p>
                        </div>
                        <span class="badge bg-label-info">Up to <?= (int) round($totalPercent) ?>%</span>
                      </div>

                      <div class="product-bonus-meta">
                        <span class="text-body-secondary small">
                          Bonus base
                          <strong><?= e(money($base)) ?></strong> per unit
                          (the product's <?= e($baseLabel) ?>).
                          Amounts below are for <strong>one unit</strong>;
                          your cart quantity multiplies them.
                        </span>
                        <span class="text-body-secondary small">
                          <?= $payingLevels ?> of 10 levels pay on this base.
                        </span>
                      </div>

                      <div class="product-bonus-grid">
<?php foreach ($levels as $level): ?>
<?php
    $number  = (int) ($level['level'] ?? 0);
    $rate    = (float) ($level['rate'] ?? 0);
    $amount  = (float) ($level['amount'] ?? 0);
    $label   = $number === 1 ? 'Your sponsor' : 'Upline ' . $number;
?>
                        <div class="product-bonus-item<?= $rate > 0 ? ' is-paying' : '' ?>">
                          <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="product-bonus-level">Level <?= $number ?></span>
                            <span class="product-bonus-rate"><?= $rate > 0 ? e(number_format($rate, 3, '.', '')) . '%' : '&mdash;' ?></span>
                          </div>
                          <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="small text-body-secondary text-truncate"><?= e($label) ?></span>
                            <span class="product-bonus-amount"><?= $rate > 0 ? e(money($amount)) : '&mdash;' ?></span>
                          </div>
                        </div>
<?php endforeach; ?>
                      </div>

                      <div class="product-bonus-total">
                        <div class="d-flex align-items-center justify-content-between gap-3">
                          <span class="fw-medium">Total up to <?= e(number_format($totalPercent, 3, '.', '')) ?>%</span>
                          <span class="fw-semibold"><?= e(money($totalAmount)) ?> per unit</span>
                        </div>
                        <p class="text-body-secondary small mb-0 mt-2">
                          <i class="icon-base bx bx-info-circle me-1"></i>
                          This is the most the ladder can pay on this base. The amount is credited to the
                          upline's e-wallet <strong>when the order is paid</strong>, and only to an upline who
                          is eligible and Active that month; a level that falls on someone who is not Active is
                          skipped and never moved to another level.
                        </p>
                        <p class="text-body-secondary small mb-0 mt-2">
                          <i class="icon-base bx bx-user-check me-1"></i>
                          You do not earn a rebate on your own purchase &mdash; the bonus goes to your sponsor
                          chain, not to you.
                        </p>
                      </div>
                    </div>
