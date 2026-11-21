<?php
declare(strict_types=1);

/**
 * Admin member page — General Harvest panel (Phase 21E, plan §9 Rules 2,
 * 5, 9–12).
 *
 * Read-only over one node's board state. Shows the member's position and
 * node, when they qualified, the settled state of their board, the ten
 * levels with block ranges and fill, the next level, and every award row
 * the ledger holds for them.
 *
 * The numbers come from `GeneralHarvestService::statusFor()` — the same
 * read 21F's member card uses — so the admin panel and the member's own
 * page can never promise different amounts. A `baseline` row keeps its
 * amount and its own badge, and it has **no wallet link**, because nothing
 * was credited (D-06).
 *
 * A member who has not qualified gets the gate, not an empty grid: the
 * panel says how many directs they hold and how many are needed, and it
 * renders no award table.
 *
 * @var array<string, mixed>       $member
 * @var array<string, mixed>|null  $general_harvest
 */

$member         = $member ?? [];
$general_harvest = $general_harvest ?? null;

$username     = (string) ($member['username'] ?? '');
$ledgerUrl    = url('admin/commissions?ledger=general_harvest&q=' . rawurlencode($username));
$walletUrl    = url('admin/wallets/' . (int) ($member['id'] ?? 0));

$statusTones = [
    GeneralHarvestAward::STATUS_CREDITED      => 'success',
    GeneralHarvestAward::STATUS_BASELINE      => 'secondary',
    'complete-beyond-depth'                   => 'secondary',
    'unpriced'                                => 'secondary',
    'complete'                                => 'warning',
    'progress'                                => 'primary',
    'waiting'                                 => 'secondary',
];
?>
                  <div class="card" data-live-region="member-general-harvest">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="card-title mb-1">General Harvest</h5>
                        <p class="text-body-secondary small mb-0">
                          One board for the whole network. This member holds node
                          <strong><?= (string) ($general_harvest['seq'] ?? '—') === '—' ? '—' : ((int) ($general_harvest['seq'] ?? 0) + 1) ?></strong>,
                          and each level's block fills with qualified members who join after them —
                          not necessarily anyone in their team.
                        </p>
                      </div>
                      <a href="<?= e($ledgerUrl) ?>" class="btn btn-sm btn-label-primary">Open the ledger</a>
                    </div>
<?php if ($general_harvest === null): ?>
                    <div class="card-body">
                      <p class="text-body-secondary mb-0">
                        The General Harvest panel is unavailable right now. The rest of this page is
                        unaffected.
                      </p>
                    </div>
<?php else: ?>
<?php
    $queued      = (bool) ($general_harvest['queued'] ?? false);
    $position    = $general_harvest['position'] ?? null;
    $seq         = $general_harvest['seq'] ?? null;
    $queueN      = (int) ($general_harvest['queue_n'] ?? 0);
    $armed       = (bool) ($general_harvest['armed'] ?? false);
    $enabled     = (bool) ($general_harvest['enabled'] ?? false);
    $baselineN   = $general_harvest['baseline_n'] ?? null;
    $settledN    = (int) ($general_harvest['settled_n'] ?? 0);
    $backlog     = (int) ($general_harvest['backlog'] ?? 0);
    $maxLevels   = (int) ($general_harvest['max_levels'] ?? GeneralHarvestLevel::LEVEL_MAX);
    $levels      = is_array($general_harvest['levels'] ?? null) ? $general_harvest['levels'] : [];
    $next        = $general_harvest['next'] ?? null;
    $awards      = is_array($general_harvest['awards'] ?? null) ? $general_harvest['awards'] : [];
    $paidAmount  = (float) ($general_harvest['credited_amount'] ?? 0);
    $paidLevels  = (int) ($general_harvest['paid_levels'] ?? 0);
    $baseAmount  = (float) ($general_harvest['baseline_earned'] ?? 0);
    $baseLevels  = (int) ($general_harvest['baseline_levels'] ?? 0);
    $total       = (string) ($general_harvest['total'] ?? '0.00');
    $earned      = (string) ($general_harvest['earned'] ?? '0.00');
    $open        = (string) ($general_harvest['open'] ?? '0.00');
?>
                    <div class="card-body">
<?php if (!$armed): ?>
                      <div class="alert alert-warning py-2 small" role="status">
                        The General Harvest board is not armed yet, so nothing can pay.
                        Run <code>bin/general_harvest_arm.php</code> to record the go-live baseline.
                      </div>
<?php elseif (!$enabled): ?>
                      <div class="alert alert-warning py-2 small" role="status">
                        The General Harvest bonus is currently off. Positions and progress are still
                        recorded; no new award is written until the stream is switched on.
                      </div>
<?php endif; ?>
<?php if (!$queued): ?>
                      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="badge bg-label-secondary">Not qualified</span>
                        <span class="small text-body-secondary">No queue position, no node.</span>
                      </div>
                      <p class="mb-2">
                        <strong>Not qualified.</strong> This member holds no Harvest queue position,
                        so they have no node and no block can fill for them.
                      </p>
                      <div class="row g-3">
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Queue position</div>
                            <div class="status-tile-value">—</div>
                          </div>
                        </div>
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Levels that pay</div>
                            <div class="status-tile-value"><?= $maxLevels ?></div>
                          </div>
                        </div>
                      </div>
<?php else: ?>
                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                          <span class="badge bg-label-primary">Position #<?= (int) $position ?> · Node <?= (int) $seq + 1 ?></span>
                          <span class="small text-body-secondary">
                            Node holder · settled to #<?= $settledN ?> of the queue (N = <?= $queueN ?>)
                          </span>
                        </div>
                        <div class="small text-body-secondary">
                          Levels that pay: <?= $maxLevels ?>
<?php if ($backlog > 0): ?>
                          · <?= $backlog ?> position<?= $backlog === 1 ? '' : 's' ?> waiting to settle
<?php endif; ?>
                        </div>
                      </div>

                      <div class="row g-3 mb-3">
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Levels paid</div>
                            <div class="status-tile-value"><?= $paidLevels ?></div>
                          </div>
                        </div>
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Paid</div>
                            <div class="status-tile-value"><?= e(money($paidAmount)) ?></div>
                          </div>
                        </div>
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Before go-live</div>
                            <div class="status-tile-value">
                              <?= $baseLevels ?> <span class="small fw-normal">· <?= e(money($baseAmount)) ?></span>
                            </div>
                          </div>
                        </div>
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Open amount <span class="text-body-secondary">· not yet paid</span></div>
                            <div class="status-tile-value"><?= e(money((float) $open)) ?></div>
                          </div>
                        </div>
                      </div>

<?php if ($baselineN !== null && $baselineN > 0): ?>
                      <p class="small text-body-secondary mb-3">
                        At go-live (N₀ = <?= (int) $baselineN ?>) this member's block was already full for
                        <strong><?= $baseLevels ?> level<?= $baseLevels === 1 ? '' : 's' ?></strong>:
                        those are recorded as <strong>Before go-live</strong> and are never paid.
<?php endif; ?>

                      <div class="mb-3">
                        <div class="d-flex flex-wrap align-items-center justify-content-between small mb-1">
                          <span class="text-body-secondary">Next level</span>
                          <span class="fw-semibold">
<?php if ($next === null): ?>
                            Every payable level is complete
<?php else: ?>
                            Level <?= (int) $next['level'] ?> —
                            block #<?= (int) $next['from'] ?>–#<?= (int) $next['to'] ?>,
                            <?= (int) $next['filled'] ?> of <?= (int) $next['slots'] ?> slots,
                            <?= (int) $next['remaining'] ?> remaining,
                            <?= e(money((float) $next['amount'])) ?> when the block is full
                            (needs the queue to reach #<?= (int) $next['needs_n'] ?>)
<?php endif; ?>
                          </span>
                        </div>
<?php if ($next !== null): ?>
                        <div class="progress" role="progressbar" aria-valuenow="<?= (int) round((float) $next['percent']) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 8px">
                          <div class="progress-bar" style="width: <?= (float) $next['percent'] ?>%"></div>
                        </div>
<?php endif; ?>
                      </div>

                      <div class="table-responsive border-top">
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr>
                              <th scope="col">Level</th>
                              <th scope="col" class="text-end">Block</th>
                              <th scope="col" class="text-end">Filled</th>
                              <th scope="col" class="text-end">Amount</th>
                              <th scope="col">State</th>
                              <th scope="col" class="text-end">Paid at</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($levels as $level): ?>
<?php
    $state   = (string) ($level['status'] ?? '');
    $tone    = $statusTones[$state] ?? 'secondary';
    $filled  = (int) ($level['filled'] ?? 0);
    $slots   = (int) ($level['slots'] ?? 0);
    $amount  = (float) ($level['amount'] ?? 0.0);
    $paidAt  = (string) ($level['paid_at'] ?? '');
?>
                          <tr>
                            <td class="text-nowrap fw-medium">Level <?= (int) $level['level'] ?></td>
                            <td class="text-end text-nowrap">
                              #<?= (int) $level['from'] ?>–#<?= (int) $level['to'] ?>
                              <div class="small text-body-secondary"><?= $slots ?> slots</div>
                            </td>
                            <td class="text-end text-nowrap">
                              <?= $filled ?> / <?= $slots ?>
                              <div class="small text-body-secondary"><?= (float) $level['percent'] ?>%</div>
                            </td>
                            <td class="text-end text-nowrap"><?= e(money($amount)) ?></td>
                            <td class="text-nowrap">
                              <span class="badge bg-label-<?= e($tone) ?>">
                                <?= e(ucfirst(str_replace('-', ' ', $state))) ?>
                              </span>
                            </td>
                            <td class="text-end text-nowrap small text-body-secondary">
                              <?= $paidAt === '' ? '—' : e(format_datetime($paidAt)) ?>
                            </td>
                          </tr>
<?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>

                      <div class="table-responsive border-top">
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr>
                              <th scope="col">Awarded at</th>
                              <th scope="col">Level</th>
                              <th scope="col" class="text-end">Queue (N)</th>
                              <th scope="col" class="text-end">Threshold</th>
                              <th scope="col" class="text-end">Amount</th>
                              <th scope="col">Status</th>
                              <th scope="col" class="text-end">Wallet</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($awards as $award): ?>
<?php
    $awardStatus = (string) ($award['status'] ?? '');
    $creditedAt  = (string) ($award['credited_at'] ?? '');
    $ledgerId    = $award['ledger_id'] ?? null;
?>
                          <tr>
                            <td class="text-nowrap small text-body-secondary">
                              <?= $creditedAt === '' ? '—' : e(format_datetime($creditedAt)) ?>
                            </td>
                            <td class="text-nowrap fw-medium">Level <?= (int) $award['level'] ?></td>
                            <td class="text-end"><?= (int) ($award['n_at_award'] ?? 0) ?></td>
                            <td class="text-end text-body-secondary"><?= (int) ($award['threshold_n'] ?? 0) ?></td>
                            <td class="text-end text-nowrap"><?= e(money((float) ($award['amount'] ?? 0))) ?></td>
                            <td><?= status_badge($awardStatus, GeneralHarvestAward::STATUS_BADGES) ?></td>
                            <td class="text-end">
<?php if ($ledgerId !== null): ?>
                              <a
                                href="<?= e(url('admin/wallets/' . (int) ($award['user_id'] ?? 0))) ?>"
                                class="btn btn-sm btn-icon btn-label-secondary"
                                title="Open the wallet movement (ledger #<?= (int) $ledgerId ?>)"
                                aria-label="Open the wallet movement"
                              ><i class="icon-base bx bx-wallet"></i></a>
<?php else: ?>
                              <span class="text-body-secondary small" title="No wallet movement: recorded before go-live">—</span>
<?php endif; ?>
                            </td>
                          </tr>
<?php endforeach; ?>
<?php if ($awards === []): ?>
                          <tr>
                            <td colspan="7" class="text-body-secondary small">
                              No level has completed yet. The first one completes when the queue reaches
                              its block's last position.
                            </td>
                          </tr>
<?php endif; ?>
                          </tbody>
                        </table>
                      </div>
<?php endif; ?>
                    </div>
                    <div class="card-footer small text-body-secondary">
                      Read-only. A block pays once, ever; a block complete before go-live is recorded
                      and never credited. Every award keeps the amount it was paid at; the ladder is
                      frozen once the board is armed.
                    </div>
                  </div>
<?php endif; ?>
