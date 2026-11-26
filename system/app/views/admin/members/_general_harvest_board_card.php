<?php
declare(strict_types=1);

/**
 * Admin member directory — General Harvest board card (Phase 21E, plan §9
 * Rules 7–9).
 *
 * Read-only over the board's state and the closed-form liability. The card
 * explains **every** empty state and names the single next action:
 *
 *   not armed          run `bin/general_harvest_arm.php`
 *   armed, nobody paid the first block yet → the first completes at N = 4
 *   armed, unsettled   run `bin/general_harvest_catchup.php`
 *   armed, settled     the frontier and the open liability
 *
 * Rule 8: the open liability is a number, never a warning —
 * `boardTotal(N) − boardTotal(settled_n)`, labelled *not yet paid*.
 *
 * @var array<string, mixed>|null $board
 */

$board = $board ?? null;

$state    = is_array($board['state'] ?? null) ? $board['state'] : [];
$frontier = is_array($board['frontier'] ?? null) ? $board['frontier'] : [];
$backlog  = is_array($board['backlog'] ?? null) ? $board['backlog'] : [];
$open     = is_array($board['open'] ?? null) ? $board['open'] : [];

$armed     = (bool) ($state['armed'] ?? false);
$enabled   = (bool) ($state['enabled'] ?? false);
$baselineN = $state['baseline_n'] ?? null;
$settledN  = (int) ($state['settled_n'] ?? 0);
$queueN    = (int) ($state['n'] ?? 0);
$gapless   = (bool) ($state['gapless'] ?? true);

$positions = (int) ($backlog['positions'] ?? 0);
$openAmount= (float) ($open['amount'] ?? 0.0);
$pairs     = is_array($open['pairs'] ?? null) ? $open['pairs'] : [];

$ledgerUrl = url('admin/commissions?ledger=general_harvest');
$catchUp   = 'bin/general_harvest_catchup.php';
$armJob    = 'bin/general_harvest_arm.php';
?>
              <div class="card mb-6" data-live-region="member-general-harvest">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">General Harvest board</h5>
                    <p class="text-body-secondary small mb-0">
                      One board for the whole network. Every qualified member holds a node — position
                      minus one — and a node's level-<?= GeneralHarvestLevel::LEVEL_MAX ?> block is
                      the last 59,049 positions behind it. A block pays its configured amount once,
                      when its last position is filled.
                    </p>
                  </div>
                  <a href="<?= e($ledgerUrl) ?>" class="btn btn-sm btn-label-primary">Open the ledger</a>
                </div>
<?php if (!$armed): ?>
                <div class="card-body">
                  <div class="alert alert-warning mb-0" role="status">
                    <strong>Not armed.</strong>
                    No baseline has been recorded, so nothing can pay.
                    Run <code><?= e($armJob) ?></code> once, then switch the bonus on in Settings.
                  </div>
                </div>
<?php elseif (!$enabled): ?>
                <div class="card-body">
                  <div class="alert alert-warning mb-0" role="status">
                    <strong>Armed, switched off.</strong>
                    The baseline is recorded (N₀ =
                    <?= $baselineN === null ? '—' : (int) $baselineN ?>) and positions are still being
                    recorded, but no new award is written until the stream is switched on.
                  </div>
                </div>
<?php elseif ($positions > 0): ?>
                <div class="card-body">
                  <div class="alert alert-warning mb-0" role="status">
                    <strong>Positions waiting to be settled.</strong>
                    The queue is at N = <?= $queueN ?> but the board is settled only to
                    <?= $settledN ?> — <?= $positions ?> position<?= $positions === 1 ? '' : 's' ?>
                    are worth <strong><?= e(money($openAmount)) ?></strong> not yet paid.
                    Run <code><?= e($catchUp) ?></code> to settle them.
                  </div>
                </div>
<?php else: ?>
                <div class="card-body pb-0">
                  <div class="row g-3">
                    <div class="col-6 col-md-3">
                      <div class="status-tile">
                        <div class="status-tile-label">Queue</div>
                        <div class="status-tile-value">#<?= $queueN ?></div>
                      </div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="status-tile">
                        <div class="status-tile-label">Settled to</div>
                        <div class="status-tile-value">#<?= $settledN ?></div>
                      </div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="status-tile">
                        <div class="status-tile-label">Open liability <span class="text-body-secondary">· not yet paid</span></div>
                        <div class="status-tile-value"><?= e(money($openAmount)) ?></div>
                      </div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="status-tile">
                        <div class="status-tile-label">Levels that pay</div>
                        <div class="status-tile-value"><?= (int) ($state['max_levels'] ?? GeneralHarvestLevel::LEVEL_MAX) ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="table-responsive mt-3">
                    <table class="table table-sm table-hover align-middle mb-0">
                      <thead>
                        <tr>
                          <th scope="col">Level</th>
                          <th scope="col" class="text-end">Blocks paid</th>
                          <th scope="col" class="text-end">Blocks full</th>
                          <th scope="col" class="text-end">Waiting</th>
                          <th scope="col" class="text-end">Next completes at</th>
                          <th scope="col" class="text-end">Amount</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($frontier as $row): ?>
<?php
    $level    = (int) ($row['level'] ?? 0);
    $rowsPaid = (int) ($row['rows'] ?? 0);
    $complete = (int) ($row['complete'] ?? 0);
    $missing  = (int) ($row['missing'] ?? 0);
    $nextN    = (int) ($row['next_n'] ?? 0);
    $amount   = (float) ($row['amount'] ?? 0.0);
?>
                        <tr>
                          <td class="text-nowrap fw-medium">Level <?= $level ?></td>
                          <td class="text-end"><?= $rowsPaid ?></td>
                          <td class="text-end"><?= $complete ?></td>
                          <td class="text-end"><?= $missing ?></td>
                          <td class="text-end">
                            <?= $missing > 0 ? '#' . $nextN : '—' ?>
                          </td>
                          <td class="text-end text-nowrap"><?= e(money($amount)) ?></td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

                  <div class="card-footer small text-body-secondary">
                    The frontier is read from the ledger, never assumed: rows at each level are exactly
                    the prefix 0 … count−1. Open liability is derived, never stored — it moves with
                    every qualification.
                  </div>
                </div>
<?php endif; ?>
                <div class="card-footer small text-body-secondary">
                  Read-only. A block pays once, ever; a block complete before go-live is recorded and
                  never credited. The open liability is boardTotal(N) − boardTotal(settled_n).
                </div>
              </div>
