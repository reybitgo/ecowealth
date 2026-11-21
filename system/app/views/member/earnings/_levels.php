<?php
declare(strict_types=1);

/**
 * Member earnings statement — the unilevel level breakdown
 * (plan §7 Phase 11).
 *
 * Ten rows, always present: level 1 is the direct referral and levels
 * 2–10 are the indirect ladder. A level with no credited award stays
 * visible at ₱0.00 so the member can see the whole ladder they own.
 *
 * A live region: a credited or skipped award bumps the per-member
 * earnings surface, so the per-level totals refresh in place.
 *
 * @var array<int, array{amount:float, count:int}> $levels
 * @var array{indirect:float} $totals
 */

$maxAmount = 0.0;

foreach ($levels as $level) {
    $maxAmount = max($maxAmount, (float) $level['amount']);
}

$indirectLevels = 0;
$indirectAmount = 0.0;

for ($level = Commission::LEVEL_MIN; $level <= Commission::LEVEL_MAX; $level++) {
    $indirectLevels += (int) ($levels[$level]['count'] ?? 0);
    $indirectAmount += (float) ($levels[$level]['amount'] ?? 0);
}
?>
              <div class="card mb-4" data-live-region="earnings-levels">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-1">Unilevel breakdown</h5>
                    <p class="text-body-secondary mb-0 small">
                      Level 1 is your direct referral; levels 2–10 are the indirect ladder.
                      One award can be paid at every level, up to ten.
                    </p>
                  </div>
                  <span class="badge bg-label-info">
                    <?= $indirectLevels ?> indirect award<?= $indirectLevels === 1 ? '' : 's' ?>
                    · <?= e(money($indirectAmount)) ?>
                  </span>
                </div>
                <div class="card-body">
                  <div class="commission-level-grid">
<?php for ($level = Commission::LEVEL_DIRECT; $level <= Commission::LEVEL_MAX; $level++): ?>
<?php
    $amount  = round((float) ($levels[$level]['amount'] ?? 0), 2);
    $count   = (int) ($levels[$level]['count'] ?? 0);
    $percent = $maxAmount > 0 ? (int) round(($amount / $maxAmount) * 100) : 0;
    $paid    = $level === Commission::LEVEL_DIRECT ? 'direct' : 'indirect';
?>
                    <div class="commission-level-item<?= $count > 0 ? ' is-paid' : '' ?>" data-level="<?= $level ?>">
                      <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                          <div class="commission-level-label"><?= e(commission_level_label($level)) ?></div>
                          <div class="small text-body-secondary">
                            <?= $count === 0 ? 'No awards yet' : $count . ' award' . ($count === 1 ? '' : 's') ?>
                            · <?= $paid === 'direct' ? 'direct referral' : 'indirect referral' ?>
                          </div>
                        </div>
                        <span class="fw-semibold text-nowrap"><?= e(money($amount)) ?></span>
                      </div>
                      <div
                        class="progress commission-level-bar"
                        role="progressbar"
                        aria-label="<?= e(commission_level_label($level)) ?> share"
                        aria-valuenow="<?= $percent ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                      >
                        <div
                          class="progress-bar <?= $level === Commission::LEVEL_DIRECT ? 'bg-primary' : 'bg-info' ?>"
                          style="width: <?= $percent ?>%"
                        ></div>
                      </div>
                    </div>
<?php endfor; ?>
                  </div>
                </div>
              </div>
