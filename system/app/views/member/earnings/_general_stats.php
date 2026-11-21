<?php
declare(strict_types=1);

/**
 * Member General Harvest statement — the stats card (Phase 21F, plan §10,
 * Appendix C.3).
 *
 * Shows the member's paid total, baseline total, and the number of levels
 * paid. Baseline rows are excluded from the *Earned* figure (Rule 8).
 *
 * @var array<string, mixed> $general_harvest GeneralHarvestService::statusFor()
 */

$general_harvest = $general_harvest ?? [];

$paidLevels   = (int) ($general_harvest['paid_levels'] ?? 0);
$baselineLevels = (int) ($general_harvest['baseline_levels'] ?? 0);
$creditedAmount = (float) ($general_harvest['credited_amount'] ?? 0.0);
$baselineAmount = (float) ($general_harvest['baseline_earned'] ?? 0.0);
?>
              <div class="card mb-4" data-live-region="earnings-general-stats">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                  <h5 class="card-title mb-0">Your blocks</h5>
                </div>
                <div class="card-body">
                  <ul class="list-unstyled mb-0 small">
                    <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                      <span class="text-body-secondary">Levels paid</span>
                      <strong><?= $paidLevels ?></strong>
                    </li>
                    <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                      <span class="text-body-secondary">Recorded before go-live</span>
                      <strong><?= $baselineLevels ?></strong>
                    </li>
                    <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                      <span class="text-body-secondary">Earned</span>
                      <strong class="commission-num"><?= e(money($creditedAmount)) ?></strong>
                    </li>
<?php if ($baselineAmount > 0): ?>
                    <li class="d-flex align-items-center justify-content-between py-1">
                      <span class="text-body-secondary">Before go-live</span>
                      <strong class="text-body-secondary"><?= e(money($baselineAmount)) ?></strong>
                    </li>
<?php endif; ?>
                  </ul>
                </div>
              </div>
