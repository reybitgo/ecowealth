<?php
declare(strict_types=1);

/**
 * Member General Harvest statement — the ten-block grid (Phase 21F,
 * plan §10 Rules 2, 4, 6, 9, Appendix C.3/C.7).
 *
 * Shows all ten levels, filled and empty alike, each with its block range
 * (#from–#to), slot count, filled count, amount and status. A level the
 * member has not reached reads `0 of 27` with its amount, because the
 * amount is the offer (Rule 6). The copy states the network rule once,
 * verbatim, and the position gap in numbers (Rules 2 and 3).
 *
 * @var array<string, mixed> $general_harvest GeneralHarvestService::statusFor()
 */

$general_harvest = $general_harvest ?? [];

$levels   = is_array($general_harvest['levels'] ?? null) ? $general_harvest['levels'] : [];
$queueN   = (int) ($general_harvest['queue_n'] ?? 0);
$enabled  = (bool) ($general_harvest['enabled'] ?? false);
$armed    = (bool) ($general_harvest['armed'] ?? false);
?>
              <div class="card mb-4" data-live-region="earnings-general-blocks">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                  <h5 class="card-title mb-0">Your ten blocks</h5>
                  <span class="badge bg-label-info text-nowrap">Queue at <?= number_format($queueN) ?></span>
                </div>
                <div class="table-responsive">
                  <table class="table table-hover align-middle commission-table mb-0">
                    <thead>
                      <tr>
                        <th scope="col" style="width: 18%">Level</th>
                        <th scope="col" style="width: 24%">Block</th>
                        <th scope="col" class="text-end" style="width: 16%">Filled</th>
                        <th scope="col" class="text-end" style="width: 18%">Amount</th>
                        <th scope="col" style="width: 24%">Status</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($levels as $levelRow): ?>
<?php
    $level     = (int) ($levelRow['level'] ?? 0);
    $from      = (int) ($levelRow['from'] ?? 0);
    $to        = (int) ($levelRow['to'] ?? 0);
    $filled    = (int) ($levelRow['filled'] ?? 0);
    $slots     = (int) ($levelRow['slots'] ?? 0);
    $complete  = (bool) ($levelRow['complete'] ?? false);
    $amount    = (float) ($levelRow['amount'] ?? 0.0);
    $status    = (string) ($levelRow['status'] ?? '');
    $threshold = (int) ($levelRow['threshold_n'] ?? 0);
    $progress  = (float) ($levelRow['progress'] ?? 0.0);
    $paid      = $status === GeneralHarvestAward::STATUS_CREDITED;
    $baseline  = $status === GeneralHarvestAward::STATUS_BASELINE;

    /* Member-facing status labels (Appendix C.7): stored statuses keep
     * their badge labels; computed block states read in member vocabulary. */
    $statusLabel = match ($status) {
        GeneralHarvestAward::STATUS_CREDITED    => 'Paid',
        GeneralHarvestAward::STATUS_BASELINE    => 'Before go-live',
        'complete-beyond-depth'                 => 'Not paying at this depth',
        'unpriced'                              => 'No amount configured',
        'progress'                              => 'In progress',
        'waiting'                               => 'Waiting for the queue',
        default                                 => ucfirst(str_replace('_', ' ', $status)),
    };
    $statusTone = match ($status) {
        GeneralHarvestAward::STATUS_CREDITED    => 'success',
        GeneralHarvestAward::STATUS_BASELINE    => 'secondary',
        'complete-beyond-depth'                 => 'info',
        'unpriced'                              => 'warning',
        'progress'                              => 'primary',
        'waiting'                               => 'secondary',
        default                                 => 'secondary',
    };
?>
                      <tr data-live-key="level-<?= $level ?>">
                        <td class="text-nowrap fw-medium">Level <?= $level ?></td>
                        <td class="text-nowrap">#<?= number_format($from) ?>–#<?= number_format($to) ?></td>
                        <td class="text-end text-nowrap">
                          <span class="fw-semibold"><?= number_format($filled) ?></span>
                          <span class="text-body-secondary small"> of <?= number_format($slots) ?></span>
                          <span class="small text-body-secondary d-block">
                            <?= $slots > 0 ? e(number_format($progress, 1, '.', '')) . '%' : '' ?>
                          </span>
                        </td>
                        <td class="text-end text-nowrap fw-semibold">
                          <?= e(money($amount)) ?>
                        </td>
                        <td>
                          <span class="badge bg-label-<?= $statusTone ?> text-nowrap"><?= e($statusLabel) ?></span>
<?php if (!$complete && $threshold > 0): ?>
                          <span class="small text-body-secondary d-block text-nowrap">
                            needs the queue to reach <?= number_format($threshold) ?>
                          </span>
<?php endif; ?>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-footer small text-body-secondary">
                  A level pays once, ever, and in full: when the queue reaches the last position of
                  its block. A block that is partly filled pays nothing.
                </div>
              </div>
