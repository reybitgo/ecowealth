<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — the 1–10 breakdown (Phase 17E).
 *
 * One ladder, ten levels, no direct/indirect split (D-17; plan §9
 * Rule 5). The level number is the whole label: level 1 is paid to the
 * buyer's sponsor, level 2 to their sponsor's sponsor, and so on. A
 * level with no credited award stays visible at ₱0.00, so the member
 * sees the whole ladder they are part of.
 *
 * @var array<int, array{amount:float, count:int}> $levels
 * @var array{count:int, credited:float} $totals
 */

$levels = $levels ?? [];
$totals = $totals ?? ['count' => 0, 'credited' => 0.0];

$maxAmount = 0.0;

foreach ($levels as $level) {
    $maxAmount = max($maxAmount, (float) $level['amount']);
}

$earned = 0.0;
$awards = 0;

for ($level = UnilevelAward::LEVEL_MIN; $level <= UnilevelAward::LEVEL_MAX; $level++) {
    $earned += (float) ($levels[$level]['amount'] ?? 0);
    $awards += (int) ($levels[$level]['count'] ?? 0);
}
?>
              <div class="card mb-4" data-live-region="earnings-repeat-levels">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-1">Repeat-purchase by level</h5>
                    <p class="text-body-secondary mb-0 small">
                      One ladder, levels 1&ndash;10. Level 1 is paid to the buyer's sponsor, level 2 to
                      their sponsor's sponsor, and so on up the chain.
                    </p>
                  </div>
                  <span class="badge bg-label-info">
                    <?= $awards ?> level award<?= $awards === 1 ? '' : 's' ?> · <?= e(money($earned)) ?>
                  </span>
                </div>
                <div class="card-body">
                  <div class="commission-level-grid">
<?php for ($level = UnilevelAward::LEVEL_MIN; $level <= UnilevelAward::LEVEL_MAX; $level++): ?>
<?php
    $amount  = round((float) ($levels[$level]['amount'] ?? 0), 2);
    $count   = (int) ($levels[$level]['count'] ?? 0);
    $percent = $maxAmount > 0 ? (int) round(($amount / $maxAmount) * 100) : 0;
?>
                    <div class="commission-level-item<?= $count > 0 ? ' is-paid' : '' ?>" data-level="<?= $level ?>">
                      <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                          <div class="commission-level-label">Level <?= $level ?></div>
                          <div class="small text-body-secondary">
                            <?= $count === 0 ? 'No awards yet' : $count . ' award' . ($count === 1 ? '' : 's') ?>
                          </div>
                        </div>
                        <span class="fw-semibold text-nowrap"><?= e(money($amount)) ?></span>
                      </div>
                      <div
                        class="progress commission-level-bar"
                        role="progressbar"
                        aria-label="Level <?= $level ?> share"
                        aria-valuenow="<?= $percent ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                      >
                        <div class="progress-bar bg-info" style="width: <?= $percent ?>%"></div>
                      </div>
                    </div>
<?php endfor; ?>
                  </div>
                  <p class="small text-body-secondary mt-3 mb-0">
                    A level with no award just means no paid purchase reached you at that depth — the buyer's
                    chain may be shorter than ten levels, or that product's rate at the level is 0.
                  </p>
                </div>
              </div>
