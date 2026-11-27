<?php
declare(strict_types=1);

/**
 * Reports — General Harvest bonus (Phase 21E, plan §9, Appendix C.2/C.5).
 *
 * The four cards of the phase in one region:
 *
 *   summary   what the window paid, who it reached and what was recorded
 *             before go-live — never mixed (Rule 4)
 *   levels    the ladder's ten levels with their windowed decisions
 *   months    the decisions by calendar month
 *   bands     how the board's money is spread across position ranges
 *   board     the state strip, the frontier, the backlog and the open liability
 *
 * The numbers come from `Report::generalHarvest*()` — the same read model
 * the ledger tab's counters use — so the reports desk and the ledger
 * cannot disagree (P21E-T13).
 *
 * @var array{credited:float, count:int, recipients:int, baseline:float,
 *            baseline_rows:int, awards:int, average:float, all_time:float,
 *            this_month:float} $general_harvest
 * @var list<array{level:int, amount:float, threshold:int, slots:int,
 *                 credited:int, credited_total:float, baseline:int,
 *                 baseline_total:float}> $general_harvest_levels
 * @var list<array{month:string, awards:int, credited:int,
 *                 credited_total:float, baseline:int, baseline_total:float}> $general_harvest_months
 * @var list<array{band:string, from:int, to:int, nodes:int, paid:float,
 *                 credited:int, baseline:int}> $general_harvest_bands
 * @var array{state:array<string, mixed>, frontier:list<array<string, mixed>>,
 *            backlog:array<string, mixed>, open:array<string, mixed>} $general_harvest_board
 * @var array{from:string, to:string, days:int, label:string} $range
 */

$summary  = array_merge([
    'credited' => 0.0, 'count' => 0, 'recipients' => 0, 'baseline' => 0.0,
    'baseline_rows' => 0, 'awards' => 0, 'average' => 0.0, 'all_time' => 0.0,
    'this_month' => 0.0,
], $general_harvest ?? []);

$levels   = is_array($general_harvest_levels ?? null) ? $general_harvest_levels : [];
$months   = is_array($general_harvest_months ?? null) ? $general_harvest_months : [];
$bands    = is_array($general_harvest_bands ?? null) ? $general_harvest_bands : [];
$board    = array_merge([
    'state'    => [], 'frontier' => [], 'backlog' => [], 'open' => [],
], $general_harvest_board ?? []);

$state    = is_array($board['state'] ?? null) ? $board['state'] : [];
$frontier = is_array($board['frontier'] ?? null) ? $board['frontier'] : [];
$backlog  = is_array($board['backlog'] ?? null) ? $board['backlog'] : [];
$open     = is_array($board['open'] ?? null) ? $board['open'] : [];

$armed      = (bool) ($state['armed'] ?? false);
$enabled    = (bool) ($state['enabled'] ?? false);
$baselineN  = $state['baseline_n'] ?? null;
$settledN   = (int) ($state['settled_n'] ?? 0);
$queueN     = (int) ($state['n'] ?? 0);
$maxLevels  = (int) ($state['max_levels'] ?? GeneralHarvestLevel::LEVEL_MAX);
$positions  = (int) ($backlog['positions'] ?? 0);
$openAmount = (float) ($open['amount'] ?? 0.0);
$pairs      = is_array($open['pairs'] ?? null) ? $open['pairs'] : [];

$range = $range ?? ['label' => ''];

$monthLabel = static fn (string $month): string => format_month($month, $month);

$ledgerUrl = url('admin/commissions?ledger=general_harvest');
?>
              <div class="card mb-6 report-general-harvest-card" data-live-region="report-general-harvest">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">General Harvest bonus</h5>
                    <p class="text-body-secondary small mb-0">
                      One shared board pays across team lines — level money decided in
                      <?= e($range['label']) ?> — no purchases, no rank, no month.
                    </p>
                  </div>
                  <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="<?= e($ledgerUrl) ?>" class="btn btn-sm btn-label-primary">Ledger</a>
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
                        <?= e(money($openAmount)) ?>
                        <div class="small fw-normal text-body-secondary">
                          <?= (int) count($pairs) ?> level<?= (int) count($pairs) === 1 ? '' : 's' ?>
                          · not yet paid
                        </div>
                      </div>
                    </div>
                  </div>

                  <hr class="my-3" />

                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h6 class="mb-0">The board</h6>
                    <span class="small text-body-secondary">
<?php if (!$armed): ?>
                      Not armed — run <code>bin/general_harvest_arm.php</code>; nothing can pay until the board has a baseline.
<?php elseif (!$enabled): ?>
                      Armed, switched off — N₀ = <?= $baselineN === null ? '—' : (int) $baselineN ?>; positions are still recorded.
<?php elseif ($positions > 0): ?>
                      The queue is at N = <?= $queueN ?> but the board is settled only to <?= $settledN ?>
                      — <?= $positions ?> position<?= $positions === 1 ? '' : 's' ?> worth
                      <strong><?= e(money($openAmount)) ?></strong> not yet paid.
                      Run <code>bin/general_harvest_catchup.php</code> to settle them.
<?php else: ?>
                      Armed and settled — the frontier below shows what is about to pay.
<?php endif; ?>
                    </span>
                  </div>

<?php if ($frontier !== []): ?>
                  <h6 class="mb-2">The frontier — what is about to pay</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3 report-table">
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
                          <td class="text-end"><?= $missing > 0 ? '#' . $nextN : '—' ?></td>
                          <td class="text-end text-nowrap"><?= e(money($amount)) ?></td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>

                  <hr class="my-3" />

                  <h6 class="mb-2">The ladder in this window</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3 report-table">
                      <thead>
                        <tr>
                          <th scope="col">Level</th>
                          <th scope="col" class="text-end">Members needed</th>
                          <th scope="col" class="text-end">Block size</th>
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
                          <td class="text-end text-nowrap"><?= number_format((int) $level['slots']) ?></td>
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
                    <table class="table table-sm align-middle mb-0 report-table">
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

<?php if ($bands !== []): ?>
                  <h6 class="mb-2">Where the money goes — position bands</h6>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 report-table">
                      <thead>
                        <tr>
                          <th scope="col">Band</th>
                          <th scope="col" class="text-end">Nodes</th>
                          <th scope="col" class="text-end">Paid</th>
                          <th scope="col" class="text-end">Credited</th>
                          <th scope="col" class="text-end">Before go-live</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($bands as $band): ?>
                        <tr>
                          <td class="text-nowrap fw-medium"><?= e((string) $band['band']) ?></td>
                          <td class="text-end"><?= (int) $band['nodes'] ?></td>
                          <td class="text-end text-nowrap"><?= e(money((float) $band['paid'])) ?></td>
                          <td class="text-end"><?= (int) $band['credited'] ?></td>
                          <td class="text-end"><?= (int) $band['baseline'] ?></td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>

                  <div class="card-footer small text-body-secondary">
                    Every credited figure counts paid levels only; a level recorded before go-live has its own column
                    and is never paid. The open liability is derived on read, never stored.
                    Export this block with <code>Export CSV → General Harvest bonus</code>.
                  </div>
                </div>
              </div>
