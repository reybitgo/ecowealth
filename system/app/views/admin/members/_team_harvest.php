<?php
declare(strict_types=1);

/**
 * Admin member page — Team Harvest panel (Phase 20E, plan §9, Appendix C.7).
 *
 * Read-only. Shows the member's stored position, the instant they qualified,
 * the teammates they hold, the levels already paid or recorded before
 * go-live, the next level with its progress and its amount, and every award
 * row the ledger holds for them.
 *
 * The numbers come from `TeamHarvestService::statusFor()` — the same read
 * 20F's member card uses — so the two surfaces cannot promise different
 * amounts. A `baseline` row is complete before the plan started paying: it
 * keeps its amount and its own badge, and it has **no wallet link**, because
 * nothing was credited (D-07).
 *
 * A member who has not qualified gets the gate, not an empty matrix: the
 * panel says how many directs they hold and how many are needed, and it
 * renders no award table.
 *
 * @var array<string, mixed>       $member
 * @var array<string, mixed>|null  $team_harvest
 */

$member       = $member ?? [];
$team_harvest = $team_harvest ?? null;

$username = (string) ($member['username'] ?? '');
$ledgerUrl = url('admin/commissions?ledger=team_harvest&q=' . rawurlencode($username));

$statusTones = [
    TeamHarvestService::LEVEL_PAID         => 'success',
    TeamHarvestService::LEVEL_BASELINE     => 'secondary',
    TeamHarvestService::LEVEL_COMPLETE     => 'warning',
    TeamHarvestService::LEVEL_BEYOND_DEPTH => 'secondary',
    TeamHarvestService::LEVEL_NO_AMOUNT    => 'secondary',
    TeamHarvestService::LEVEL_IN_PROGRESS  => 'primary',
];
?>
                  <div class="card" data-live-region="member-team-harvest">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="card-title mb-1">Team Harvest</h5>
                        <p class="text-body-secondary small mb-0">
                          A private 3×10 matrix. A level pays its amount once, when its block of qualified
                          teammates is full — no purchases, no rank, no month.
                        </p>
                      </div>
                      <a href="<?= e($ledgerUrl) ?>" class="btn btn-sm btn-label-primary">Open the ledger</a>
                    </div>
<?php if ($team_harvest === null): ?>
                    <div class="card-body">
                      <p class="text-body-secondary mb-0">
                        The Team Harvest panel is unavailable right now. The rest of this page is unaffected.
                      </p>
                    </div>
<?php else: ?>
<?php
    $queued      = (bool) ($team_harvest['queued'] ?? false);
    $position    = $team_harvest['position'] ?? null;
    $qualifiedAt = $team_harvest['qualified_at'] ?? null;
    $teammates   = (int) ($team_harvest['teammates'] ?? 0);
    $baselineN   = (int) ($team_harvest['baseline_n'] ?? 0);
    $directs     = (int) ($team_harvest['directs'] ?? 0);
    $gate        = (int) ($team_harvest['gate'] ?? TeamHarvestLevel::qualifyDirects());
    $enabled     = (bool) ($team_harvest['enabled'] ?? false);
    $seeded      = (bool) ($team_harvest['seeded'] ?? false);
    $maxLevels   = (int) ($team_harvest['max_levels'] ?? TeamHarvestLevel::maxLevels());
    $levels      = is_array($team_harvest['levels'] ?? null) ? $team_harvest['levels'] : [];
    $next        = $team_harvest['next'] ?? null;
    $awards      = is_array($team_harvest['awards'] ?? null) ? $team_harvest['awards'] : [];
    $paidAmount  = (float) ($team_harvest['paid_amount'] ?? 0);
    $paidLevels  = (int) ($team_harvest['credited_levels'] ?? 0);
    $baseAmount  = (float) ($team_harvest['baseline_amount'] ?? 0);
    $baseLevels  = (int) ($team_harvest['baseline_levels'] ?? 0);
?>
                    <div class="card-body">
<?php if (!$enabled): ?>
                      <div class="alert alert-warning py-2 small" role="status">
                        The Team Harvest bonus is currently off. Positions and progress are still recorded;
                        no new award is written until the stream is switched on.
                      </div>
<?php endif; ?>
<?php if (!$seeded): ?>
                      <div class="alert alert-warning py-2 small" role="status">
                        The queue is not seeded, so this member holds no position yet. Nothing can pay until
                        <code>bin/harvest_queue_seed.php</code> has run.
                      </div>
<?php endif; ?>
<?php if (!$queued): ?>
                      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="badge bg-label-secondary">Not qualified</span>
                        <span class="small text-body-secondary">No queue position, no matrix.</span>
                      </div>
                      <p class="mb-2">
                        <strong>Not qualified: <?= $directs ?> of <?= $gate ?> direct members.</strong>
<?php if ($directs < $gate): ?>
                        They enter the queue when their <?= $gate ?>-th direct member registers; from that
                        instant their matrix fills with members who qualify after them.
<?php endif; ?>
                      </p>
                      <div class="row g-3">
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Direct members</div>
                            <div class="status-tile-value"><?= $directs ?> <span class="small fw-normal">of <?= $gate ?></span></div>
                          </div>
                        </div>
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Queue position</div>
                            <div class="status-tile-value">—</div>
                          </div>
                        </div>
                      </div>
<?php else: ?>
                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                          <span class="badge bg-label-primary">Position #<?= (int) $position ?></span>
                          <span class="small text-body-secondary">
                            Qualified <?= $qualifiedAt === null ? '—' : e(format_datetime((string) $qualifiedAt)) ?>
<?php if ((int) ($team_harvest['directs_at_qualification'] ?? 0) > 0): ?>
                            · on their <?= (int) $team_harvest['directs_at_qualification'] ?>th direct member
<?php endif; ?>
                          </span>
                        </div>
                        <div class="small text-body-secondary">
                          <?= $directs ?> direct<?= $directs === 1 ? '' : 's' ?> · levels that pay: <?= $maxLevels ?>
                        </div>
                      </div>

                      <div class="row g-3 mb-3">
                        <div class="col-6 col-xl-3">
                          <div class="status-tile">
                            <div class="status-tile-label">Teammates</div>
                            <div class="status-tile-value"><?= $teammates ?></div>
                          </div>
                        </div>
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
                      </div>

                      <p class="small text-body-secondary mb-3">
<?php if ($baselineN > 0): ?>
                        At go-live this member already held <strong><?= $baselineN ?> teammate<?= $baselineN === 1 ? '' : 's' ?></strong>:
                        the levels inside that count are recorded as <strong>Before go-live</strong> and are never paid.
<?php else: ?>
                        This member qualified after go-live, so no level of theirs was complete before the plan
                        started paying.
<?php endif; ?>
                      </p>

                      <div class="mb-3">
                        <div class="d-flex flex-wrap align-items-center justify-content-between small mb-1">
                          <span class="text-body-secondary">Next level</span>
                          <span class="fw-semibold">
<?php if ($next === null): ?>
                            Every payable level is complete
<?php else: ?>
                            Level <?= (int) $next['level'] ?> —
                            <?= (int) $next['filled'] ?> of <?= (int) $next['slots'] ?> slots,
                            <?= (int) $next['remaining'] ?> teammate<?= (int) $next['remaining'] === 1 ? '' : 's' ?> remaining,
                            <?= e(money((float) $next['amount'])) ?> when the block is full
                            (<?= (int) $next['threshold'] ?> teammates)
<?php endif; ?>
                          </span>
                        </div>
<?php if ($next !== null): ?>
                        <div class="progress" role="progressbar" aria-valuenow="<?= (int) round((float) $next['percent']) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 8px">
                          <div class="progress-bar" style="width: <?= (float) $next['percent'] ?>%"></div>
                        </div>
<?php endif; ?>
                      </div>
<?php endif; ?>
                    </div>

<?php if ($queued): ?>
                    <div class="table-responsive border-top">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col">Level</th>
                            <th scope="col" class="text-end">Teammates</th>
                            <th scope="col" class="text-end">Block</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col">State</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($levels as $level): ?>
<?php
    $state = (string) ($level['status'] ?? TeamHarvestService::LEVEL_IN_PROGRESS);
    $tone  = $statusTones[$state] ?? 'secondary';
?>
                          <tr>
                            <td class="text-nowrap fw-medium">Level <?= (int) $level['level'] ?></td>
                            <td class="text-end text-nowrap">
                              <?= (int) $level['filled'] ?> / <?= (int) $level['slots'] ?>
                              <div class="small text-body-secondary"><?= number_format((int) $level['threshold']) ?> needed</div>
                            </td>
                            <td class="text-end text-nowrap"><?= (int) round((float) $level['progress']) ?>%</td>
                            <td class="text-end text-nowrap"><?= e(money((float) $level['amount'])) ?></td>
                            <td class="text-nowrap">
                              <span class="badge bg-label-<?= e($tone) ?>">
                                <?= e(TeamHarvestService::LEVEL_LABELS[$state] ?? ucfirst($state)) ?>
                              </span>
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
                            <th scope="col">Awards</th>
                            <th scope="col">Level</th>
                            <th scope="col" class="text-end">Teammates at award</th>
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
?>
                          <tr>
                            <td class="text-nowrap small text-body-secondary">
                              <?= $creditedAt === '' ? '—' : e(format_datetime($creditedAt)) ?>
                            </td>
                            <td class="text-nowrap fw-medium">Level <?= (int) $award['level'] ?></td>
                            <td class="text-end"><?= (int) $award['n_at_award'] ?></td>
                            <td class="text-end text-body-secondary"><?= (int) $award['threshold_n'] ?></td>
                            <td class="text-end text-nowrap"><?= e(money((float) $award['amount'])) ?></td>
                            <td><?= status_badge($awardStatus, TeamHarvestAward::STATUS_BADGES) ?></td>
                            <td class="text-end">
<?php if (($award['ledger_id'] ?? null) !== null): ?>
                              <a
                                href="<?= e(url('admin/wallets/' . (int) $award['user_id'])) ?>"
                                class="btn btn-sm btn-icon btn-label-secondary"
                                title="Open the wallet movement (ledger #<?= (int) $award['ledger_id'] ?>)"
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
                              No level has completed yet. The first one needs
                              <?= TeamHarvestLevel::threshold(1) ?> qualified teammates.
                            </td>
                          </tr>
<?php endif; ?>
                        </tbody>
                      </table>
                    </div>
<?php endif; ?>
<?php endif; ?>
                    <div class="card-footer small text-body-secondary">
                      Read-only. A level pays once, ever; a level complete before go-live is recorded and never
                      credited. Changing the ladder affects levels that have not paid yet — every award keeps the
                      amount it was paid at.
                    </div>
                  </div>
