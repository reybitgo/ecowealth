<?php
declare(strict_types=1);

/**
 * Admin member directory — Team Harvest queue card (Phase 20E, plan §9
 * Rules 7–8, Appendix C.8).
 *
 * Who is qualified, in what order, how far each member is, and what the
 * network still owes. Read-only: nothing on this card writes a queue row, an
 * award or a wallet movement.
 *
 * Rule 7: the card explains **every** empty state and names the next action.
 * There are three of them:
 *
 *   not seeded            run `bin/harvest_queue_seed.php`
 *   seeded, nobody in it  nobody holds the gate yet
 *   seeded and qualified  the positions below, with the counters
 *
 * Rule 8: the open liability is a number, never a warning. It is
 * `openLiability()`'s total and it is labelled *not yet paid*.
 *
 * @var array<string, mixed>|null $harvest
 */

$harvest = $harvest ?? null;

$gate      = TeamHarvestLevel::qualifyDirects();
$ledgerUrl = url('admin/commissions?ledger=team_harvest');

if ($harvest === null) {
    $harvest = [
        'queued' => 0, 'positions' => 0, 'next' => 1, 'deepest' => 0,
        'seeded' => false, 'baseline' => null, 'awards' => 0,
        'credited_awards' => 0, 'baseline_awards' => 0,
        'credited_total' => 0.0, 'baseline_total' => 0.0,
        'rows' => [],
        'open' => ['levels' => 0, 'members' => 0, 'amount' => 0.0, 'by_level' => []],
    ];
}

$open    = array_merge(['levels' => 0, 'members' => 0, 'amount' => 0.0], (array) ($harvest['open'] ?? []));
$seeded  = (bool) ($harvest['seeded'] ?? false);
$queued  = (int) ($harvest['queued'] ?? 0);
$rows    = is_array($harvest['rows'] ?? null) ? $harvest['rows'] : [];
$baseline = $harvest['baseline'] ?? null;
?>
              <div class="card mb-4" data-live-region="member-harvest">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Team Harvest queue</h5>
                    <p class="text-body-secondary small mb-0">
                      A member enters the queue when they hold <?= $gate ?> direct member accounts.
                      Positions are fixed for ever; a member's team fills with qualified members who join after them.
                    </p>
                  </div>
                  <div class="d-flex flex-wrap align-items-center gap-2">
<?php if ($seeded): ?>
                    <span class="badge bg-label-success text-nowrap">
                      Seeded<?= $baseline === null || $baseline === '' ? '' : ' · ' . e(format_datetime((string) $baseline)) ?>
                    </span>
<?php else: ?>
                    <span class="badge bg-label-warning text-nowrap">Not seeded</span>
<?php endif; ?>
                    <a href="<?= e($ledgerUrl) ?>" class="btn btn-sm btn-label-primary">Open the ledger</a>
                  </div>
                </div>
<?php if (!$seeded): ?>
                <div class="card-body">
                  <div class="alert alert-warning mb-0" role="status">
                    <strong>Not seeded.</strong>
                    No position has been assigned, so the queue is empty and nothing can pay.
                    Run <code>bin/harvest_queue_seed.php</code> to reconstruct the positions from the tree,
                    then enable the bonus in Settings.
                  </div>
                </div>
<?php elseif ($queued === 0): ?>
                <div class="card-body">
                  <div class="alert alert-info mb-0" role="status">
                    <strong>Nobody holds the gate yet.</strong>
                    The first member to reach <?= $gate ?> direct members takes position #1.
                  </div>
                </div>
<?php else: ?>
                <div class="card-body pb-0">
                  <div class="row g-3">
                    <div class="col-6 col-md-3">
                      <div class="status-tile">
                        <div class="status-tile-label">Queued</div>
                        <div class="status-tile-value"><?= $queued ?></div>
                      </div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="status-tile">
                        <div class="status-tile-label">Next position</div>
                        <div class="status-tile-value">#<?= (int) ($harvest['next'] ?? ($queued + 1)) ?></div>
                      </div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="status-tile">
                        <div class="status-tile-label">Deepest team</div>
                        <div class="status-tile-value"><?= (int) ($harvest['deepest'] ?? 0) ?> <span class="small fw-normal">teammates</span></div>
                      </div>
                    </div>
                    <div class="col-6 col-md-3">
                      <div class="status-tile">
                        <div class="status-tile-label">Open liability <span class="text-body-secondary">· not yet paid</span></div>
                        <div class="status-tile-value"><?= e(money((float) $open['amount'])) ?></div>
                      </div>
                    </div>
                  </div>
                  <p class="small text-body-secondary mt-3 mb-0">
                    <?= (int) $harvest['credited_awards'] ?> level<?= (int) $harvest['credited_awards'] === 1 ? '' : 's' ?>
                    paid (<?= e(money((float) $harvest['credited_total'])) ?>) ·
                    <?= (int) $harvest['baseline_awards'] ?> recorded before go-live
                    (<?= e(money((float) $harvest['baseline_total'])) ?> — never paid) ·
                    <?= (int) $open['levels'] ?> level<?= (int) $open['levels'] === 1 ? '' : 's' ?>
                    across <?= (int) $open['members'] ?> member<?= (int) $open['members'] === 1 ? '' : 's' ?>
                    would be evaluated as complete now.
                  </p>
                </div>
                <div class="table-responsive mt-3">
                  <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th scope="col">Position</th>
                        <th scope="col">Member</th>
                        <th scope="col">Qualified at</th>
                        <th scope="col" class="text-end">Teammates</th>
                        <th scope="col" class="text-end">At go-live</th>
                        <th scope="col" class="text-end">Levels paid</th>
                        <th scope="col" class="text-end">Paid</th>
                        <th scope="col">Source</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($rows as $row): ?>
<?php
    $position = (int) $row['position'];
    $qualifiedAt = (string) ($row['qualified_at'] ?? '');
    $baselineN = (int) ($row['baseline_n'] ?? 0);
?>
                      <tr>
                        <td class="text-nowrap fw-medium">#<?= $position ?></td>
                        <td>
                          <a href="<?= e(url('admin/members/' . (int) $row['user_id'])) ?>" class="text-body">
                            @<?= e((string) $row['username']) ?>
                          </a>
                        </td>
                        <td class="text-nowrap small text-body-secondary">
                          <?= $qualifiedAt === '' ? '—' : e(format_date($qualifiedAt)) ?>
                        </td>
                        <td class="text-end"><?= (int) $row['teammates'] ?></td>
                        <td class="text-end text-body-secondary"><?= $baselineN ?></td>
                        <td class="text-end">
                          <?= (int) $row['credited_levels'] ?>
<?php if ((int) $row['baseline_levels'] > 0): ?>
                          <div class="small text-body-secondary"><?= (int) $row['baseline_levels'] ?> before go-live</div>
<?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap"><?= e(money((float) $row['credited_total'])) ?></td>
                        <td class="text-nowrap small text-body-secondary"><?= e((string) $row['source']) ?></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
<?php endif; ?>
                <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2 small text-body-secondary">
                  <span>
                    Read-only. A position is never renumbered; a level pays once, ever.
<?php if ($seeded && $queued > 0): ?>
                    Showing the first <?= count($rows) ?> of <?= $queued ?> position<?= $queued === 1 ? '' : 's' ?>.
<?php endif; ?>
                  </span>
                  <span class="text-nowrap">
                    Open liability is derived, never stored: it moves with every qualification.
                  </span>
                </div>
              </div>
