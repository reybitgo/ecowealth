<?php
declare(strict_types=1);

/**
 * Product detail — the repeat-purchase ladder panel (Phase 17E, plan §9).
 *
 * Member copy is **Team chain directed**: it answers "what do I earn?".
 * When someone in this member's Team chain buys the product, the level
 * that buyer sits at pays the listed level income — level 1 when a
 * member the reader personally sponsored buys, level 2 when their
 * recruits buy, and so on. The reader's own purchase never pays them;
 * it counts toward the monthly minimum that keeps them Active.
 *
 * Copy rules for this panel (plan §9, member-facing):
 *
 *   · say "Team chain", never "sponsor chain";
 *   · say "repeat-purchase bonus" and version the level incomes;
 *   · say "up to", never "you will earn" a fixed total;
 *   · state the monthly minimum and that the money is credited when the
 *     order is paid;
 *   · all ten levels are always listed, and a level with no rate reads
 *     "—" instead of "0.000%";
 *   · never tell the member they earn on their own purchase.
 *
 * The numbers come from `UnilevelService::quoteLine($id, 1)` (17C), so
 * the panel, the checkout line and the engine share one definition of
 * every amount. The quote is a preview: the real chain and the
 * recipients' Active flags decide what is actually paid.
 *
 * @var array<string, mixed> $bonus quoteLine() plus total_percent/paying_levels,
 *                                  requires_active and active_threshold
 */

$levels       = is_array($bonus['levels'] ?? null) ? $bonus['levels'] : [];
$totalPercent = (float) ($bonus['total_percent'] ?? 0);
$totalAmount  = (float) ($bonus['total'] ?? 0);
$payingLevels = (int) ($bonus['paying_levels'] ?? 0);
$base         = (float) ($bonus['base'] ?? 0);
$baseSource   = (string) ($bonus['base_source'] ?? 'price');
$baseLabel    = $baseSource === 'pv' ? 'P.V.' : 'price';

$requiresActive = (bool) ($bonus['requires_active'] ?? true);
$threshold      = (float) ($bonus['active_threshold'] ?? 0.0);

if (!$requiresActive) {
    $activeNote   = 'The monthly Active rule is currently off, so every eligible member is paid without a purchase check.';
    $inactiveSkip = false;
} elseif ($threshold > 0) {
    $activeNote   = 'Meet the ' . money($threshold) . ' monthly minimum to stay Active: your counted purchases last month decide this month, and only an Active member is paid.';
    $inactiveSkip = true;
} else {
    $activeNote   = 'The monthly minimum is ' . money($threshold) . ', so every member is Active.';
    $inactiveSkip = false;
}
?>
                    <div class="product-bonus-panel" data-bonus-panel>
                      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                        <div>
                          <h6 class="mb-1">
                            <i class="icon-base bx bx-gift me-1"></i> Repeat-purchase bonus
                          </h6>
                          <p class="text-body-secondary small mb-0">
                            Your <strong>Team chain</strong> is your repeat-purchase income.
                            When someone in your Team chain buys this product, the level that buyer
                            sits at pays you that level's share: <strong>Level 1</strong> when a member
                            you personally sponsored buys, <strong>Level 2</strong> when their recruits
                            buy, and so on up to <strong>Level 10</strong>. The full ladder pays up to
                            <strong><?= e(number_format($totalPercent, 3, '.', '')) ?>%</strong>
                            of the bonus base, one share per level.
                          </p>
                        </div>
                        <span class="badge bg-label-info">Up to <?= (int) round($totalPercent) ?>%</span>
                      </div>

                      <div class="product-bonus-meta">
                        <span class="text-body-secondary small">
                          Bonus base
                          <strong><?= e(money($base)) ?></strong> per unit
                          (the product's <?= e($baseLabel) ?>).
                          Each level below is what you earn when a Team chain member at that depth buys
                          <strong>one unit</strong>; their cart quantity multiplies it.
                        </span>
                        <span class="text-body-secondary small">
                          <?= $payingLevels ?> of 10 levels pay on this base.
                        </span>
                      </div>

                      <div class="product-bonus-grid">
<?php foreach ($levels as $level): ?>
<?php
    $number = (int) ($level['level'] ?? 0);
    $rate   = (float) ($level['rate'] ?? 0);
    $amount = (float) ($level['amount'] ?? 0);
    $label  = $number === 1 ? 'Your direct recruit' : 'Your Team chain';
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
                          <i class="icon-base bx bx-wallet me-1"></i>
                          Across all ten levels. Your own share is the rate on the level where your
                          Team chain member sits, and it is credited to your e-wallet
                          <strong>when that order is paid</strong>, to an eligible member who is Active
                          for the month.
                        </p>
                        <p class="text-body-secondary small mb-0 mt-2">
                          <i class="icon-base bx bx-calendar-check me-1"></i>
                          <?= e($activeNote) ?><?php if ($inactiveSkip): ?> If a level falls on you while
                          you are not Active, it is skipped and never moved to another level.<?php endif; ?>
                        </p>
                        <p class="text-body-secondary small mb-0 mt-2">
                          <i class="icon-base bx bx-user-check me-1"></i>
                          Your own purchase does not pay you. It counts toward your monthly minimum;
                          the purchases your Team chain makes are what pay your level income.
                        </p>
                      </div>
                    </div>
