<?php
declare(strict_types=1);

/**
 * Reports — Team Harvest bonus (Phase 20E, plan §9, Appendix C.2/C.8).
 *
 * The four cards of the phase in one region:
 *
 *   summary   what the window paid, who it reached and what was recorded
 *             before go-live — never mixed (Rule 4)
 *   queue     who is qualified, in what order, and what the engine would
 *             still pay (the open liability, Rule 8)
 *   levels    the ladder's ten levels with their windowed decisions
 *   months    the decisions by calendar month
 *
 * The numbers come from `Report::teamHarvest*()`, the same read model the
 * ledger tab's counters use, so the reports desk and the ledger cannot
 * disagree (P20E-T13).
 *
 * @var array{credited:float, count:int, recipients:int, baseline:float,
 *            baseline_rows:int, awards:int, average:float, all_time:float,
 *            this_month:float} $team_harvest
 * @var list<array{level:int, amount:float, threshold:int, slots:int, credited:int,
 *                 credited_total:float, baseline:int, baseline_total:float}> $team_harvest_levels
 * @var list<array{month:string, awards:int, credited:int, credited_total:float,
 *                 baseline:int, baseline_total:float}> $team_harvest_months
 * @var array{queued:int, positions:int, next:int, deepest:int, seeded:bool,
 *            baseline:?string, awards:int, credited_awards:int, baseline_awards:int,
 *            credited_total:float, baseline_total:float,
 *            rows:list<array<string, mixed>>} $team_harvest_queue
 * @var array{levels:int, members:int, amount:float, by_level:array<int, array<string, mixed>>} $team_harvest_open
 * @var int $team_harvest_max
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$summary = array_merge([
    'credited' => 0.0, 'count' => 0, 'recipients' => 0, 'baseline' => 0.0,
    'baseline_rows' => 0, 'awards' => 0, 'average' => 0.0, 'all_time' => 0.0,
    'this_month' => 0.0,
], $team_harvest ?? []);

$levels   = is_array($team_harvest_levels ?? null) ? $team_harvest_levels : [];
$months   = is_array($team_harvest_months ?? null) ? $team_harvest_months : [];
$queue    = array_merge([
    'queued' => 0, 'positions' => 0, 'next' => 1, 'deepest' => 0, 'seeded' => false,
    'baseline' => null, 'awards' => 0, 'credited_awards' => 0, 'baseline_awards' => 0,
    'credited_total' => 0.0, 'baseline_total' => 0.0, 'rows' => [],
], $team_harvest_queue ?? []);
$open     = array_merge(['levels' => 0, 'members' => 0, 'amount' => 0.0], $team_harvest_open ?? []);
$maxLevels = (int) ($team_harvest_max ?? TeamHarvestLevel::maxLevels());
$range    = $range ?? ['label' => ''];

$monthLabel = static function (string $month): string {
    $ts = strtotime($month . '-01 12:00:00');

    return $ts === false ? $month : date('M Y', $ts);
};

$membersUrl = url('admin/members?harvest=yes');
?>
              <div class="card mb-6 report-team-harvest-card" data-live-region="report-team-harvest">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Team Harvest bonus</h5>
                    <p class="text-body-secondary small mb-0">
                      Level money decided in <?= e($range['label']) ?> — no purchases, no rank, no month.
                    </p>
                  </div>
                  <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="<?= e(url('admin/commissions?ledger=team_harvest')) ?>" class="btn btn-sm btn-label-primary">
                      Ledger
                    </a>
                    <a href="<?= e($membersUrl) ?>" class="btn btn-sm btn-label-secondary">Queue</a>
                  </div>
                </div>
                <div class="card-body">
                  <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
                    <h3 class="mb-0"><?= e(money($summary['credited'])) ?></h3>
                    <span class="text-body-secondary small">
                      credited in this window across <?= (int) $summary['count'] ?>
                      award<?= (int) $summary['count'] === 1 ? '' : 's' ?>
                      and <?= (int) $summary['recipients'] ?> recipient<?= (int) $summary['recipients'] === 1 ? '' : 's' ?>
                    </span>
                  </div>

                  <div class="row g-3">
                    <div class="col-6 col-md-4 col-xl-2">
                      <div class="text-body-secondary small">Awards</div>
                      <div class="fw-semibold"><?= (int) $summary['count'] ?></div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                      <div class="text-body-secondary small">Recipients</div>
                      <div class="fw-semibold"><?= (int) $summary['recipients'] ?></div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                      <div class="text-body-secondary small">Before go-live</div>
                      <div class="fw-semibold">
                        <?= e(money($summary['baseline'])) ?>
                        <div class="small fw-normal text-body-secondary">
                          <?= (int) $summary['baseline_rows'] ?> recorded, never paid
                        </div>
                      </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                      <div class="text-body-secondary small">This month</div>
                      <div class="fw-semibold"><?= e(money($summary['this_month'])) ?></div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                      <div class="text-body-secondary small">All time</div>
                      <div class="fw-semibold"><?= e(money($summary['all_time'])) ?></div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                      <div class="text-body-secondary small">Open liability</div>
                      <div class="fw-semibold">
                        <?= e(money((float) $open['amount'])) ?>
                        <div class="small fw-normal text-body-secondary">
                          <?= (int) $open['levels'] ?> level<?= (int) $open['levels'] === 1 ? '' : 's' ?>
                          · not yet paid
                        </div>
                      </div>
                    </div>
                  </div>

                  <hr class="my-3" />

                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h6 class="mb-0">The queue</h6>
                    <span class="small text-body-secondary">
<?php if ($queue['seeded']): ?>
                      Seeded<?= $queue['baseline'] === null ? '' : ' · ' . e(format_datetime((string) $queue['baseline'])) ?>
                      · <?= (int) $queue['queued'] ?> position<?= (int) $queue['queued'] === 1 ? '' : 's' ?>
                      · next #<?= (int) $queue['next'] ?>
                      · deepest <?= (int) $queue['deepest'] ?> teammate<?= (int) $queue['deepest'] === 1 ? '' : 's' ?>
<?php else: ?>
                      Not seeded — run <code>bin/harvest_queue_seed.php</code>; nothing can pay until the queue exists.
<?php endif; ?>
                    </span>
                  </div>
<?php if ($queue['seeded'] && (int) $queue['queued'] === 0): ?>
                  <p class="small text-body-secondary mb-2">
                    Nobody holds the gate yet. The first member to reach
                    <?= TeamHarvestLevel::qualifyDirects() ?> direct members takes position #1.
                  </p>
<?php elseif ($queue['rows'] !== []): ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2">
                      <thead>
                        <tr>
                          <th scope="col">Position</th>
                          <th scope="col">Member</th>
                          <th scope="col">Qualified at</th>
                          <th scope="col" class="text-end">Teammates</th>
                          <th scope="col" class="text-end">Levels paid</th>
                          <th scope="col" class="text-end">Paid</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($queue['rows'] as $row): ?>
                        <tr>
                          <td class="text-nowrap fw-medium">#<?= (int) $row['position'] ?></td>
                          <td>
                            <a href="<?= e(url('admin/members/' . (int) $row['user_id'])) ?>" class="text-body">
                              @<?= e((string) $row['username']) ?>
                            </a>
                          </td>
                          <td class="small text-body-secondary text-nowrap"><?= e(format_date((string) $row['qualified_at'])) ?></td>
                          <td class="text-end"><?= (int) $row['teammates'] ?></td>
                          <td class="text-end"><?= (int) $row['credited_levels'] ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $row['credited_total'])) ?></td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>

                  <hr class="my-3" />

                  <h6 class="mb-2">The ladder in this window</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2">
                      <thead>
                        <tr>
                          <th scope="col">Level</th>
                          <th scope="col" class="text-end">Teammates needed</th>
                          <th scope="col" class="text-end">Amount</th>
                          <th scope="col" class="text-end">Paid awards</th>
                          <th scope="col" class="text-end">Paid total</th>
                          <th scope="col" class="text-end">Before go-live</th>
                          <th scope="col">Pays</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($levels as $level): ?>
<?php
    $beyond   = (int) $level['level'] > $maxLevels;
    $noAmount = (float) $level['amount'] <= 0.0;
?>
                        <tr>
                          <td class="text-nowrap fw-medium">Level <?= (int) $level['level'] ?></td>
                          <td class="text-end text-nowrap"><?= number_format((int) $level['threshold']) ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $level['amount'])) ?></td>
                          <td class="text-end"><?= (int) $level['credited'] ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $level['credited_total'])) ?></td>
                          <td class="text-end text-nowrap"><?= (int) $level['baseline'] ?></td>
                          <td class="text-nowrap">
<?php if ($beyond): ?>
                            <span class="badge bg-label-secondary">No</span>
                            <span class="small text-body-secondary">above the configured depth</span>
<?php elseif ($noAmount): ?>
                            <span class="badge bg-label-warning">No</span>
                            <span class="small text-body-secondary">no amount</span>
<?php else: ?>
                            <span class="badge bg-label-success">Yes</span>
<?php endif; ?>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

<?php if ($months !== []): ?>
                  <h6 class="mb-2">By month</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead>
                        <tr>
                          <th scope="col">Month</th>
                          <th scope="col" class="text-end">Decisions</th>
                          <th scope="col" class="text-end">Paid awards</th>
                          <th scope="col" class="text-end">Paid total</th>
                          <th scope="col" class="text-end">Before go-live</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($months as $month): ?>
                        <tr>
                          <td class="text-nowrap fw-medium"><?= e($monthLabel((string) $month['month'])) ?></td>
                          <td class="text-end"><?= (int) $month['awards'] ?></td>
                          <td class="text-end"><?= (int) $month['credited'] ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $month['credited_total'])) ?></td>
                          <td class="text-end text-nowrap"><?= (int) $month['baseline'] ?></td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>
                </div>
                <div class="card-footer small text-body-secondary">
                  Every credited figure counts paid levels only; a level recorded before go-live has its own column
                  and is never paid. The open liability is derived on read, never stored.
                  Export this block with <code>Export CSV → Team Harvest bonus</code>.
                </div>
              </div>
