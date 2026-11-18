<?php
declare(strict_types=1);

/**
 * Member dashboard — the General Harvest tile (Phase 21F, plan §10,
 * Appendix C.4).
 *
 * A live region: an award bumps the member's dashboard surface, so the
 * paid total, the position and the next block's progress refresh in place.
 * The tile is a read-out — no form, no posting button; its only link goes
 * to the statement (P21F-T10) — and every number is
 * `GeneralHarvestService::statusFor()`'s, so the tile can never promise a
 * block or an amount the engine would not pay (Rule 10).
 *
 * The two hard facts (the whole-network board and the purchases/rank
 * exclusion) live on the block card and are deliberately **not** repeated
 * here (Rule 2, Rule 7, P21F-T13/T14). The tile states the position, the
 * queue size and the next block's exact threshold — never a date (Rule 5).
 *
 * Visibility: the tile renders only for a member who holds a position. The
 * qualification gate is Phase 20's, and the Team Harvest tile already
 * states it; this stream does not own a second gate.
 *
 * @var array<string, mixed>|null $general_harvest GeneralHarvestService::statusFor()
 */

$general_harvest = $general_harvest ?? null;

if (!is_array($general_harvest)) {
    return;
}

$queued = (bool) ($general_harvest['queued'] ?? false);

if (!$queued) {
    return;
}

$position   = (int) ($general_harvest['position'] ?? 0);
$queueN     = (int) ($general_harvest['queue_n'] ?? 0);
$armed      = (bool) ($general_harvest['armed'] ?? false);
$enabled    = (bool) ($general_harvest['enabled'] ?? false);
$paying     = $armed && $enabled;
$earned     = (float) ($general_harvest['credited_amount'] ?? 0);
$paidLevels = (int) ($general_harvest['paid_levels'] ?? 0);
$next       = is_array($general_harvest['next'] ?? null) ? $general_harvest['next'] : null;
$statement  = url('earnings?ledger=general_harvest');

$nextLevel  = $next === null ? 0 : (int) ($next['level'] ?? 0);
$nextFilled = $next === null ? 0 : (int) ($next['filled'] ?? 0);
$nextSlots  = $next === null ? 0 : (int) ($next['slots'] ?? 0);
$nextAmount = $next === null ? 0.0 : (float) ($next['amount'] ?? 0);
$nextNeeds  = $next === null ? 0 : (int) ($next['needs_n'] ?? 0);
$percent    = $next === null ? 0.0 : max(0.0, min(100.0, (float) ($next['percent'] ?? 0.0)));
?>
              <div class="row g-4 mb-4" data-live-region="dashboard-general-harvest">
                <div class="col-12">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div class="d-flex align-items-center gap-2">
                        <h5 class="card-title mb-0">General Harvest bonus</h5>
                        <span class="badge bg-label-primary">Position #<?= $position ?></span>
<?php if (!$paying): ?>
                        <span class="badge bg-label-secondary">Not paying yet</span>
<?php endif; ?>
                      </div>
                      <a href="<?= e($statement) ?>" class="btn btn-sm btn-label-primary">View your board</a>
                    </div>
                    <div class="card-body">
<?php if (!$paying): ?>
                      <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
                        <strong>The General Harvest bonus is not currently paying.</strong>
                        Your position and progress are recorded and will count when it is switched on.
                      </div>
<?php endif; ?>

                      <p class="small text-body-secondary mb-3">
                        <?= number_format($queueN) ?> qualified member<?= $queueN === 1 ? '' : 's' ?> on the shared board.
<?php if ($next !== null): ?>
                        Level <?= $nextLevel ?> is <?= number_format($nextFilled) ?> of <?= number_format($nextSlots) ?> filled and pays
                        <?= e(money($nextAmount)) ?> when the queue reaches <?= number_format($nextNeeds) ?>.
<?php else: ?>
                        Every level of your block has been decided.
<?php endif; ?>
                      </p>

<?php if ($next !== null): ?>
                      <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
                        <span class="small text-body-secondary">Level <?= $nextLevel ?> progress</span>
                        <strong class="small"><?= e(number_format($percent, 1, '.', '')) ?>%</strong>
                      </div>
                      <div
                        class="progress commission-progress mb-3"
                        role="progressbar"
                        aria-valuenow="<?= (int) round($percent) ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Slots filled in level <?= $nextLevel ?>"
                      >
                        <div class="progress-bar bg-primary" style="width: <?= $percent ?>%"></div>
                      </div>
<?php endif; ?>

                      <div class="row g-3 mt-1">
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Earned</div>
                          <div class="h5 mb-0 commission-num"><?= e(money($earned)) ?></div>
                        </div>
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Levels paid</div>
                          <div class="h5 mb-0"><?= $paidLevels ?></div>
                        </div>
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Position</div>
                          <div class="h5 mb-0">#<?= $position ?></div>
                        </div>
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Next level</div>
                          <div class="h5 mb-0">
<?php if ($next === null): ?>
                            <span class="text-body-secondary">—</span>
<?php else: ?>
                            Level <?= $nextLevel ?>
<?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="card-footer small text-body-secondary">
                      A level pays once, ever, and in full when its block completes. The amount is fixed by
                      the plan; the timing is not knowable.
                    </div>
                  </div>
                </div>
              </div>