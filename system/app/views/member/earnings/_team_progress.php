<?php
declare(strict_types=1);

/**
 * Member Team Harvest statement — the position and progress card
 * (Phase 20F, plan §10 Rules 2 and 4, Appendix C.3/C.8).
 *
 * The card answers three questions in the member's own words:
 *
 *   · am I qualified, and why?  (Rule 2: the gate is always stated in the
 *     same sentence as the position — a member who does not remember why
 *     they are qualified should never have to ask)
 *   · how far into the next level am I?
 *   · what does that level pay, and how many teammates does it need?
 *
 * Everything is `TeamHarvestService::statusFor()`, so the amount is the
 * configured ladder's and the progress is the engine's own (Rule 9).
 *
 * There is no date anywhere on this card (Rule 4): the amount is fixed by
 * the plan, the timing is not knowable. The copy therefore reads "when
 * your teammates reach 12, level 2 completes and pays ₱2,000.00" and never
 * "you will be paid by …".
 *
 * A member who has not qualified reads the gate — the count they hold and
 * the sentence that explains the next step — and no matrix (Appendix C.8,
 * P20F-T6).
 *
 * @var array<string, mixed> $team_harvest TeamHarvestService::statusFor()
 */

$team_harvest = $team_harvest ?? [];

$queued    = (bool) ($team_harvest['queued'] ?? false);
$position  = $team_harvest['position'] ?? null;
$qualified = $team_harvest['qualified_at'] ?? null;
$gate      = (int) ($team_harvest['gate'] ?? TeamHarvestLevel::qualifyDirects());
$directs   = (int) ($team_harvest['directs'] ?? 0);
$teammates = (int) ($team_harvest['teammates'] ?? 0);
$enabled   = (bool) ($team_harvest['enabled'] ?? false);
$seeded    = (bool) ($team_harvest['seeded'] ?? false);
$next      = is_array($team_harvest['next'] ?? null) ? $team_harvest['next'] : null;
$opened    = (int) ($team_harvest['directs_at_qualification'] ?? 0);

/* The gate sentence names the direct member that opened the queue, in
 * words up to ten (the setting's range), and falls back to the digit. */
$ordinal = static function (int $number): string {
    $words = [
        1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth',
        6 => 'sixth', 7 => 'seventh', 8 => 'eighth', 9 => 'ninth', 10 => 'tenth',
    ];

    return $words[$number] ?? $number . 'th';
};

$gateWord  = $ordinal($opened > 0 ? $opened : $gate);
$gatePct   = $gate > 0 ? (int) max(0, min(100, intdiv($directs * 100, $gate))) : 100;
$nextLevel = $next === null ? 0 : (int) $next['level'];
$percent   = $next === null ? 0.0 : (float) $next['percent'];
?>
              <div class="card mb-6" data-live-region="earnings-team-progress">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Your Team Harvest bonus</h5>
                    <p class="text-body-secondary small mb-0">
                      A private 3×10 matrix. Level 1 fills with 3 qualified teammates, level 2 with 9 more,
                      level 3 with 27 more, and so on. A level pays its amount once, when its block is full.
                    </p>
                  </div>
<?php if ($queued): ?>
                  <span class="badge bg-label-primary text-nowrap">Harvest position #<?= (int) $position ?></span>
<?php else: ?>
                  <span class="badge bg-label-secondary text-nowrap">Not qualified yet</span>
<?php endif; ?>
                </div>
                <div class="card-body">
<?php if (!$seeded): ?>
                  <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
                    The Team Harvest bonus is being prepared. No position has been assigned yet, so there is
                    nothing to show here — your account is unaffected.
                  </div>
<?php endif; ?>
<?php if (!$enabled): ?>
                  <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
                    <strong>The Team Harvest bonus is not currently paying.</strong>
                    Your position and progress are recorded and will count when it is switched on.
                  </div>
<?php endif; ?>

<?php if (!$queued): ?>
                  <p class="mb-2">
                    <strong>You are not qualified yet: <?= $directs ?> of <?= $gate ?> direct members.</strong>
                    When your <?= e($gateWord) ?> direct member registers, you enter the queue and your matrix starts filling with members who qualify after you.
                  </p>
                  <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
                    <span class="small text-body-secondary">Direct members</span>
                    <strong class="small"><?= $directs ?> / <?= $gate ?></strong>
                  </div>
                  <div
                    class="progress commission-progress"
                    role="progressbar"
                    aria-valuenow="<?= $gatePct ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="Direct members toward the Team Harvest gate"
                  >
                    <div class="progress-bar bg-primary" style="width: <?= $gatePct ?>%"></div>
                  </div>
<?php else: ?>
                  <p class="mb-3">
                    <strong>You qualified for position #<?= (int) $position ?> when you registered your <?= e($gateWord) ?> direct member.</strong>
                    <span class="text-body-secondary small d-block">
                      Qualified <?= e(format_date((string) $qualified)) ?> · your matrix counts every member below you who qualified after you.
                    </span>
                  </p>

                  <div class="row g-4">
                    <div class="col-lg-7">
                      <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
                        <span class="small text-body-secondary">
<?php if ($next === null): ?>
                          Every level that pays at this depth is complete
<?php else: ?>
                          Level <?= $nextLevel ?>
<?php endif; ?>
                        </span>
<?php if ($next !== null): ?>
                        <strong class="small">
                          <?= number_format((int) $next['filled']) ?> of <?= number_format((int) $next['slots']) ?> slots
                          · <?= e(number_format($percent, 1, '.', '')) ?>%
                        </strong>
<?php endif; ?>
                      </div>
<?php if ($next !== null): ?>
                      <div
                        class="progress commission-progress"
                        role="progressbar"
                        aria-valuenow="<?= (int) round($percent) ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Slots filled in level <?= $nextLevel ?>"
                      >
                        <div class="progress-bar bg-primary" style="width: <?= $percent ?>%"></div>
                      </div>
                      <p class="small text-body-secondary mt-2 mb-0">
                        <?= (int) $next['remaining'] ?> more qualified teammate<?= (int) $next['remaining'] === 1 ? '' : 's' ?> needed. When your teammates reach <?= number_format((int) $next['threshold']) ?>, level <?= $nextLevel ?> completes and pays <?= e(money((float) $next['amount'])) ?>.
                      </p>
<?php else: ?>
                      <p class="small text-body-secondary mt-2 mb-0">
                        Your teammates keep counting, but no level beyond the configured depth pays.
                      </p>
<?php endif; ?>
                    </div>

                    <div class="col-lg-5">
                      <ul class="list-unstyled small mb-0">
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Teammates</span>
                          <strong><?= $teammates ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Levels paid</span>
                          <strong><?= (int) ($team_harvest['credited_levels'] ?? 0) ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1">
                          <span class="text-body-secondary">Earned</span>
                          <strong class="commission-num"><?= e(money((float) ($team_harvest['paid_amount'] ?? 0))) ?></strong>
                        </li>
                      </ul>
                    </div>
                  </div>

<?php if ($teammates === 0 && $next !== null): ?>
                  <div class="alert alert-info py-2 px-3 small mt-3 mb-0" role="status">
                    You are position #<?= (int) $position ?>. Your first level needs
                    <?= (int) $next['threshold'] ?> qualified teammates; you have 0.
                  </div>
<?php endif; ?>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  The Team Harvest bonus depends only on who qualified, and when. Nothing on this card is a
                  promise of a date: a level pays the moment its block is full.
                </div>
              </div>
