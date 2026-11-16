<?php
declare(strict_types=1);

/**
 * Member Team Harvest statement — the awards card heading (Phase 20F,
 * plan §10, Appendix C.3).
 *
 * The copy states the rule the table obeys: one row per level, one
 * payment per level, and a row marked *Before go-live* is a record, not
 * money (Rule 8, D-07).
 *
 * @var array<string, mixed> $team_harvest TeamHarvestService::statusFor()
 * @var Paginator            $pager
 */

$team_harvest = $team_harvest ?? [];
$pager        = $pager ?? Paginator::make(0);
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="earnings-team-header">
                  <div>
                    <h5 class="mb-1">Your levels</h5>
                    <p class="text-body-secondary mb-0 small">
                      One row per level. A level pays once, ever — the amount on the row is the amount it paid.
                      A level marked <strong>Before go-live</strong> was already complete when the plan started
                      paying: it is recorded here and was never credited.
                    </p>
                  </div>
                  <span class="badge bg-label-primary"><?= (int) $pager->total ?> level<?= $pager->total === 1 ? '' : 's' ?></span>
                </div>
