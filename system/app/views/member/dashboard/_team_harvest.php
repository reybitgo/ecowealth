<?php
declare(strict_types=1);

/**
 * Member dashboard — the Team Harvest tile (Phase 20F, plan §10,
 * Appendix C.4).
 *
 * A live region: an award bumps the member's dashboard surface, so the
 * paid total and the next level's progress refresh in place. The tile is a
 * read-out — it has no form (P20F-T10) — and the only peso figure it
 * states as money is what already reached the wallet.
 *
 * The copy never promises a date or an amount the engine would not pay:
 * the progress is `TeamHarvestService::statusFor()`'s, the amount is the
 * configured ladder's, and the sentence reads "when your teammates reach
 * 12" (Rule 4).
 *
 * Visibility: hidden only when the stream is off **and** the member is not
 * queued **and** has no awards — the same shape the Royalty tile uses. A
 * member who is queued keeps their position on the dashboard whether the
 * stream pays or not (Rule 8's "recorded, not paid" reality);
 * a member who is not queued while the stream is on reads the gate, which
 * is the question the tile exists to answer (Rule 2).
 *
 * The two exclusions and the purchases footnote live on the matrix card
 * and are deliberately **not** repeated here (Rule 3, Rule 6).
 *
 * @var array<string, mixed>|null $team_harvest
 */

$team_harvest = $team_harvest ?? null;

if (!is_array($team_harvest)) {
    return;
}

$enabled   = (bool) ($team_harvest['enabled'] ?? false);
$queued    = (bool) ($team_harvest['queued'] ?? false);
$position  = $team_harvest['position'] ?? null;
$directs   = (int) ($team_harvest['directs'] ?? 0);
$gate      = (int) ($team_harvest['gate'] ?? TeamHarvestLevel::qualifyDirects());
$teammates = (int) ($team_harvest['teammates'] ?? 0);
$paid      = (float) ($team_harvest['paid_amount'] ?? 0);
$paidLevels = (int) ($team_harvest['credited_levels'] ?? 0);
$baseLevels = (int) ($team_harvest['baseline_levels'] ?? 0);
$next      = is_array($team_harvest['next'] ?? null) ? $team_harvest['next'] : null;
$statement = url('earnings?ledger=team_harvest');

/* Off with no position and no history: nothing to say yet. */
if (!$enabled && !$queued && $paidLevels === 0 && $baseLevels === 0) {
    return;
}

$nextLevel = $next === null ? 0 : (int) $next['level'];
$percent   = $next === null ? 0.0 : max(0.0, min(100.0, (float) $next['percent']));
$gatePct   = $gate > 0 ? (int) max(0, min(100, intdiv($directs * 100, $gate))) : 100;

$ordinal = static function (int $number): string {
    $words = [
        1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth',
        6 => 'sixth', 7 => 'seventh', 8 => 'eighth', 9 => 'ninth', 10 => 'tenth',
    ];

    return $words[$number] ?? $number . 'th';
};

$gateWord = $ordinal($gate);
?>
              <div class="row g-6 mb-6" data-live-region="dashboard-team-harvest">
                <div class="col-12">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div class="d-flex align-items-center gap-2">
                        <h5 class="card-title mb-0">Team Harvest</h5>
<?php if ($queued): ?>
                        <span class="badge bg-label-primary">Harvest position #<?= (int) $position ?></span>
<?php else: ?>
                        <span class="badge bg-label-secondary">Not qualified yet</span>
<?php endif; ?>
                      </div>
                      <a href="<?= e($statement) ?>" class="btn btn-sm btn-label-primary">
                        <?= $queued ? 'View your matrix' : 'How the bonus works' ?>
                      </a>
                    </div>
                    <div class="card-body">
<?php if (!$enabled): ?>
                      <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
                        <strong>The Team Harvest bonus is not currently paying.</strong>
                        Your position and progress are recorded and will count when it is switched on.
                      </div>
<?php endif; ?>

<?php if (!$queued): ?>
                      <p class="small text-body-secondary mb-3">
                        <strong>You are not qualified yet: <?= $directs ?> of <?= $gate ?> direct members.</strong>
                        When your <?= e($gateWord) ?> direct member registers, you enter the queue and your matrix
                        starts filling with members who qualify after you.
                      </p>
                      <div
                        class="progress commission-progress mb-3"
                        role="progressbar"
                        aria-valuenow="<?= $gatePct ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Direct members toward the Team Harvest gate"
                      >
                        <div class="progress-bar bg-primary" style="width: <?= $gatePct ?>%"></div>
                      </div>
<?php else: ?>
                      <p class="small text-body-secondary mb-3">
                        <?= $teammates ?> qualified teammate<?= $teammates === 1 ? '' : 's' ?> count toward your
                        matrix.
<?php if ($next !== null): ?>
                        Level <?= $nextLevel ?> is <?= number_format((int) $next['filled']) ?> of <?= number_format((int) $next['slots']) ?>
                        slots full and pays <?= e(money((float) $next['amount'])) ?> when the block completes at
                        <?= number_format((int) $next['threshold']) ?> teammates.
<?php else: ?>
                        Every payable level is complete.
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
<?php endif; ?>

                      <div class="row g-3 mt-1">
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Earned</div>
                          <div class="h5 mb-0 commission-num"><?= e(money($paid)) ?></div>
                        </div>
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Levels paid</div>
                          <div class="h5 mb-0"><?= $paidLevels ?></div>
                        </div>
                        <div class="col-6 col-lg-3">
                          <div class="small text-body-secondary">Teammates</div>
                          <div class="h5 mb-0"><?= $teammates ?></div>
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
                      A private 3×10 matrix, paid level by level the moment a block is full. The Team Harvest
                      bonus depends only on who qualified, and when.
                    </div>
                  </div>
                </div>
              </div>
