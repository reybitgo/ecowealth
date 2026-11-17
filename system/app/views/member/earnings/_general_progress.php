<?php
declare(strict_types=1);

/**
 * Member General Harvest statement — the next-block progress card
 * (Phase 21F, plan §10 Rules 2–5, Appendix C.3/C.8).
 *
 * When the member has not qualified, this is the whole region: the gate
 * and the count they hold. When they have qualified, it shows the next
 * incomplete level, how many slots are filled, what it pays and the exact
 * `N` the queue needs. No date is ever promised (Rule 5).
 *
 * @var array<string, mixed> $general_harvest GeneralHarvestService::statusFor()
 */

$general_harvest = $general_harvest ?? [];

$queued   = (bool) ($general_harvest['queued'] ?? false);
$position = $general_harvest['position'] ?? null;
$seq      = $general_harvest['seq'] ?? null;
$queueN   = (int) ($general_harvest['queue_n'] ?? 0);
$armed    = (bool) ($general_harvest['armed'] ?? false);
$enabled  = (bool) ($general_harvest['enabled'] ?? false);
$next     = is_array($general_harvest['next'] ?? null) ? $general_harvest['next'] : null;
$reachable= (bool) ($general_harvest['reachable'] ?? false);
$open     = (string) ($general_harvest['open'] ?? '');
$earned   = (float) ($general_harvest['earned'] ?? 0.0);
?>
<?php if (!$queued): ?>
              <div class="card mb-4" data-live-region="earnings-general-progress">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                  <h5 class="card-title mb-0">Get your node</h5>
                </div>
                <div class="card-body">
                  <p class="mb-2">
                    <strong>You are not qualified yet: 0 of 3 direct members.</strong>
                    When your third direct member registers, you enter the queue and your blocks start
                    filling with the members who qualify after you.
                  </p>
                  <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
                    <span class="small text-body-secondary">Direct members</span>
                    <strong class="small">0 / 3</strong>
                  </div>
                  <div class="progress commission-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Direct members toward qualification">
                    <div class="progress-bar bg-primary" style="width: 0%"></div>
                  </div>
                  <p class="small text-body-secondary mt-2 mb-0">
                    Your position on the board is the order you qualified in — nothing else matters.
                  </p>
                </div>
              </div>
<?php else: ?>
              <div class="card mb-4" data-live-region="earnings-general-progress">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                  <h5 class="card-title mb-0">Your next block</h5>
                  <span class="badge bg-label-primary text-nowrap">Position #<?= (int) $position ?></span>
                </div>
                <div class="card-body">
<?php if (!$armed): ?>
                  <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
                    The General Harvest bonus is not currently paying. Your position and blocks are
                    recorded and will count when it is switched on.
                  </div>
<?php endif; ?>
<?php if ($next === null || !$reachable): ?>
                  <p class="mb-3">
                    <strong>No level can complete at the current queue size.</strong>
                    The queue is at <?= number_format($queueN) ?> qualified members; level 1 needs the
                    queue to reach 4.
                  </p>
<?php else: ?>
<?php
    $nextLevel = (int) ($next['level'] ?? 0);
    $nextFrom  = (int) ($next['from'] ?? 0);
    $nextTo    = (int) ($next['to'] ?? 0);
    $nextFilled= (int) ($next['filled'] ?? 0);
    $nextSlots = (int) ($next['slots'] ?? 0);
    $nextRem   = (int) ($next['remaining'] ?? 0);
    $nextPct   = (float) ($next['percent'] ?? 0.0);
    $nextAmt   = (float) ($next['amount'] ?? 0.0);
    $nextNeeds = (int) ($next['needs_n'] ?? 0);
?>
                  <p class="mb-3">
                    <strong>Level <?= $nextLevel ?></strong> — block
                    #<?= number_format($nextFrom) ?>–#<?= number_format($nextTo) ?>:
                    <span class="fw-semibold"><?= number_format($nextFilled) ?> of <?= number_format($nextSlots) ?></span>
                    filled · <span class="fw-semibold"><?= e(number_format($nextPct, 1, '.', '')) ?>%</span>.
                  </p>
                  <div class="progress commission-progress" role="progressbar" aria-valuenow="<?= (int) round($nextPct) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Slots filled in level <?= $nextLevel ?>">
                    <div class="progress-bar bg-primary" style="width: <?= $nextPct ?>%"></div>
                  </div>
                  <p class="small text-body-secondary mt-2 mb-0">
                    <strong><?= $nextRem ?> more qualified member<?= $nextRem === 1 ? '' : 's' ?></strong>
                    needed. When the queue reaches
                    <strong><?= number_format($nextNeeds) ?></strong>, level
                    <?= $nextLevel ?> completes and pays <strong><?= e(money($nextAmt)) ?></strong>.
                  </p>
<?php endif; ?>
                  <p class="small text-body-secondary mt-3 mb-0">
                    <strong>A slot in your block is filled by a qualified member of the whole network
                    — not necessarily anyone in your team. Your block fills in the order members
                    qualified.</strong>
                  </p>
                </div>
                <div class="card-footer small text-body-secondary">
                  The amount is fixed by the plan; the timing is not knowable. Nothing on this card is
                  a promise of a date.
                </div>
              </div>
<?php endif; ?>
