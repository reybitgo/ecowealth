<?php
declare(strict_types=1);

/**
 * Member General Harvest statement — the block card header (Phase 21F,
 * plan §10, Appendix C.3).
 *
 * The card states the two hard facts in copy: a slot in the member's block
 * is filled by the whole network, and a block pays only when the queue
 * reaches its last position. No date is ever promised (Rule 5).
 *
 * Everything is `GeneralHarvestService::statusFor()` — position, `N`,
 * armed/enabled state, the ten levels, paid and baseline totals — so the
 * view formats and never computes (Rule 10).
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
$levels   = is_array($general_harvest['levels'] ?? null) ? $general_harvest['levels'] : [];
$earned   = (float) ($general_harvest['earned'] ?? 0.0);
$credited = (float) ($general_harvest['credited_amount'] ?? 0.0);
$baseline = (float) ($general_harvest['baseline_earned'] ?? 0.0);
$armedAt  = (string) ($general_harvest['armed_at'] ?? '');
$total    = (string) ($general_harvest['total'] ?? '');
$next     = is_array($general_harvest['next'] ?? null) ? $general_harvest['next'] : null;
$reachable= (bool) ($general_harvest['reachable'] ?? false);
$open     = (string) ($general_harvest['open'] ?? '');
?>
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3" data-live-region="earnings-general-header">
                  <div>
                    <h5 class="mb-1">General Harvest bonus</h5>
                    <p class="text-body-secondary mb-0 small">
                      Your position on the shared Harvest board. Each level pays its configured amount once,
                      when the queue reaches its block's last position — never before, never in part.
                    </p>
                  </div>
<?php if ($queued): ?>
                  <span class="badge bg-label-primary text-nowrap">Position #<?= (int) $position ?></span>
<?php else: ?>
                  <span class="badge bg-label-secondary text-nowrap">Not qualified yet</span>
<?php endif; ?>
                </div>

<?php if (!$queued): ?>
                <div class="card-body">
                  <p class="mb-2">
                    <strong>You are not qualified yet.</strong>
                    Three members register with you as their sponsor — that is all. You take the next
                    position on the board and your blocks start filling with the members who qualify
                    after you.
                  </p>
                </div>
<?php elseif (!$armed): ?>
                <div class="card-body">
                  <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
                    The General Harvest bonus is not currently paying. Your position and blocks are
                    recorded and will count when it is switched on.
                  </div>
                  <p class="mb-0 small text-body-secondary">
                    Your position is <strong>#<?= (int) $position ?></strong>; the queue is at
                    <strong><?= number_format($queueN) ?></strong> qualified members.
                  </p>
                </div>
<?php else: ?>
                <div class="card-body">
                  <p class="mb-3">
                    <strong>Position #<?= (int) $position ?> · queue at <?= number_format($queueN) ?></strong>
                    <span class="text-body-secondary small d-block">
                      A slot in your block is filled by a qualified member of the whole network — not
                      necessarily anyone in your team. Your block fills in the order members qualified.
                    </span>
                  </p>
                </div>
<?php endif; ?>

<?php if ($armed && $baseline > 0): ?>
                <div class="card-body">
                  <p class="small text-body-secondary mb-0">
                    The board started paying on <strong><?= e(format_date($armedAt)) ?></strong>.
                    Levels marked <strong>Before go-live</strong> were already complete on that date:
                    they are recorded, not paid, and they do not count toward your Earned total.
                  </p>
                </div>
<?php endif; ?>

                <div class="card-footer small text-body-secondary">
                  This bonus does not depend on any purchase, on your rank, or on the monthly Active
                  status.
                </div>
