<?php
declare(strict_types=1);

/**
 * Member repeat-purchase statement — the two-clock Active panel
 * (Phase 17E, plan §9; the rule itself is §12.7).
 *
 * The reference model's two clocks, explained as one picture:
 *
 *      the flag used this month    ← last month's personal purchases
 *      the flag used next month    ← this month's personal purchases
 *
 * Copy rules that apply here: the money is never described as
 * something the member earns on their own purchase (they are not a
 * recipient), and a skipped level is never hidden — the panel says
 * what the rule does and why.
 *
 * @var array{
 *   period:string, previous:string, next:string, active:bool,
 *   previous_purchases:float, previous_seen:bool, previous_source:string,
 *   current_purchases:float, threshold:float, next_active:bool,
 *   progress:int, remaining:float, requires_active:bool
 * } $status
 */

$status = array_merge([
    'period'             => UnilevelCycle::periodOf(),
    'previous'           => UnilevelCycle::previous(UnilevelCycle::periodOf()),
    'next'               => UnilevelCycle::periodOf(),
    'active'             => true,
    'previous_purchases' => 0.0,
    'previous_seen'      => false,
    'previous_source'    => 'live',
    'current_purchases'  => 0.0,
    'threshold'          => 500.0,
    'next_active'        => false,
    'progress'           => 0,
    'remaining'          => 500.0,
    'requires_active'    => true,
], $status ?? []);

$monthLabel = static function (string $period): string {
    $time = strtotime($period . '-01');

    return $time === false ? $period : date('F Y', $time);
};

$currentLabel  = $monthLabel((string) $status['period']);
$previousLabel = $monthLabel((string) $status['previous']);
$nextLabel     = $monthLabel((string) $status['next']);

$active       = (bool) $status['active'];
$requires     = (bool) $status['requires_active'];
$threshold    = (float) $status['threshold'];
$previousPaid = (float) $status['previous_purchases'];
$previousSeen = (bool) ($status['previous_seen'] ?? true);
$currentPaid  = (float) $status['current_purchases'];
$progress     = (int) $status['progress'];
$nextActive   = (bool) $status['next_active'];
$source       = (string) $status['previous_source'];

$previousCopy = match (true) {
    $threshold <= 0 => sprintf(
        'The monthly threshold is %s, so every account is Active.',
        money($threshold)
    ),
    $source === 'missing' => sprintf(
        'Your account has no snapshot for %s, so you count as Active for %s.',
        $previousLabel,
        $currentLabel
    ),
    !$previousSeen => sprintf(
        'You have no counted purchases in %s, so no flag was set — a missing flag counts as Active for %s.',
        $previousLabel,
        $currentLabel
    ),
    $active => sprintf(
        'You bought %s in %s and reached the %s threshold, so you are Active for %s.',
        money($previousPaid),
        $previousLabel,
        money($threshold),
        $currentLabel
    ),
    default => sprintf(
        'You bought %s in %s, below the %s threshold, so you are not Active for %s. '
            . 'A repeat-purchase level that falls on you in %s is skipped and never moved to another upline.',
        money($previousPaid),
        $previousLabel,
        money($threshold),
        $currentLabel,
        $currentLabel
    ),
};

$nextCopy = match (true) {
    $threshold <= 0 => sprintf('Every purchase counts, so you are Active for %s.', $nextLabel),
    $nextActive => sprintf(
        'You have already bought %s in %s — that meets the threshold, so you will be Active for %s.',
        money($currentPaid),
        $currentLabel,
        $nextLabel
    ),
    default => sprintf(
        'You have bought %s in %s. Reach %s this month and you will be Active for %s.',
        money($currentPaid),
        $currentLabel,
        money($threshold),
        $nextLabel
    ),
};
?>
              <div class="card mb-4" data-live-region="earnings-repeat-status">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <h5 class="mb-1">Your Active status</h5>
                    <p class="text-body-secondary mb-0 small">
                      The month that just ended decides this month; this month decides the next one.
                    </p>
                  </div>
                  <span class="badge <?= $active ? 'bg-label-success' : 'bg-label-secondary' ?>">
                    <?= $active ? 'Active' : 'Not Active' ?> for <?= e($currentLabel) ?>
                  </span>
                </div>
                <div class="card-body">
<?php if (!$requires): ?>
                  <div class="alert alert-info py-2 small mb-4">
                    <i class="icon-base bx bx-info-circle me-1"></i>
                    The monthly Active rule is currently turned off, so every eligible upline is paid
                    regardless of their purchases. The figures below are still recorded, but they do not
                    gate a payment while the rule is off.
                  </div>
<?php endif; ?>

                  <div class="row g-4">
                    <div class="col-md-6">
                      <div class="small text-body-secondary"><?= e($previousLabel) ?> purchases</div>
                      <div class="h4 mb-1"><?= e(money($previousPaid)) ?></div>
                      <div class="small text-body-secondary mb-2">
                        Threshold <?= e(money($threshold)) ?>
<?php if ($source === 'live'): ?>
                        · not closed yet, this total is live
<?php elseif ($source === 'snapshot'): ?>
                        · closed month
<?php else: ?>
                        · no snapshot for your account
<?php endif; ?>
                      </div>
                      <p class="small mb-0"><?= e($previousCopy) ?></p>
                    </div>

                    <div class="col-md-6">
                      <div class="small text-body-secondary"><?= e($currentLabel) ?> purchases so far</div>
                      <div class="h4 mb-1"><?= e(money($currentPaid)) ?></div>
                      <div class="small text-body-secondary mb-2">Counts toward <?= e($nextLabel) ?></div>
                      <div
                        class="progress"
                        role="progressbar"
                        aria-label="<?= e($currentLabel) ?> progress toward the threshold"
                        aria-valuenow="<?= $progress ?>"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        style="height: 0.4rem"
                      >
                        <div class="progress-bar<?= $nextActive ? ' bg-success' : ' bg-primary' ?>" style="width: <?= $progress ?>%"></div>
                      </div>
                      <p class="small mt-2 mb-0"><?= e($nextCopy) ?></p>
                    </div>
                  </div>

                  <p class="small text-body-secondary border-top pt-3 mt-4 mb-0">
                    <i class="icon-base bx bx-info-circle me-1"></i>
                    These are your own purchases, and they never pay you a bonus — a repeat-purchase award
                    goes to the uplines above the buyer. Your purchases only decide whether you are Active,
                    so a bonus can reach you when your team buys.
                  </p>
                </div>
              </div>
