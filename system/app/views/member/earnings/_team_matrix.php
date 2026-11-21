<?php
declare(strict_types=1);

/**
 * Member Team Harvest statement — the 3×10 matrix and its roster
 * (Phase 20F, plan §12.5, Appendix C.3).
 *
 * The card shows the **whole** matrix — all ten levels, filled and empty —
 * and never hides a level the member has not reached (Rule 5). An empty
 * level reads "0 of 9" beside its amount, because the amount is the offer.
 * A level's state is `TeamHarvestService`'s member label (Appendix C.7):
 * *Paid*, *Before go-live*, *In progress*, or one of the two
 * complete-but-not-paying states — a level above the configured depth or
 * with a zero amount.
 *
 * Rule 3: the two exclusions are stated **here and nowhere else**, once,
 * verbatim. Rule 6: the purchases footnote is here too, once, in the
 * card's footnote. Rule 8: a member with a pre-go-live level reads the
 * footnote naming the date the plan started paying, and the *Earned*
 * figures elsewhere exclude that level entirely.
 *
 * Rule 7: the roster carries usernames and positions — the genealogy
 * already shows them — and no money at all.
 *
 * @var array<string, mixed>       $team_harvest TeamHarvestService::statusFor()
 * @var list<array<string, mixed>> $roster       a page of HarvestQueue::matrix()
 * @var Paginator                  $roster_pager
 * @var int                        $user_id      the session member (the matrix's "you")
 */

$team_harvest = $team_harvest ?? [];
$roster       = $roster ?? [];
$roster_pager = $roster_pager ?? Paginator::make(count($roster));
$user_id      = (int) ($user_id ?? 0);

$queued    = (bool) ($team_harvest['queued'] ?? false);
$teammates = (int) ($team_harvest['teammates'] ?? 0);
$levels    = is_array($team_harvest['levels'] ?? null) ? $team_harvest['levels'] : [];
$baseLevels = (int) ($team_harvest['baseline_levels'] ?? 0);
$baseline   = $team_harvest['baseline'] ?? null;

$tones = [
    TeamHarvestService::LEVEL_PAID         => 'success',
    TeamHarvestService::LEVEL_BASELINE     => 'secondary',
    TeamHarvestService::LEVEL_COMPLETE     => 'warning',
    TeamHarvestService::LEVEL_BEYOND_DEPTH => 'secondary',
    TeamHarvestService::LEVEL_NO_AMOUNT    => 'secondary',
    TeamHarvestService::LEVEL_IN_PROGRESS  => 'primary',
];

/* The roster shows slots as the member counts them: slot 1 is the first
 * teammate. The zero-based `slot` is the plan's arithmetic (§12.5). */
$slotLabel = static fn (int $slot): string => 'Slot ' . ($slot + 1);
?>
              <div class="card mb-4" data-live-region="earnings-team-matrix">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                  <div>
                    <h5 class="mb-1">Your Team matrix</h5>
                    <p class="text-body-secondary small mb-0">
                      All ten levels, filled and empty. A level is all-or-nothing: an empty block still
                      carries its amount, because the amount is the offer.
                    </p>
                  </div>
                  <span class="badge bg-label-primary text-nowrap"><?= $teammates ?> teammate<?= $teammates === 1 ? '' : 's' ?></span>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 commission-table">
                    <thead>
                      <tr>
                        <th scope="col">Level</th>
                        <th scope="col">Slots</th>
                        <th scope="col" class="text-end">Teammates needed</th>
                        <th scope="col" class="text-end">Amount</th>
                        <th scope="col">State</th>
                        <th scope="col" class="text-end">Progress</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($levels as $level): ?>
<?php
    $state = (string) ($level['status'] ?? TeamHarvestService::LEVEL_IN_PROGRESS);
    $tone  = $tones[$state] ?? 'secondary';
    $pct   = (float) ($level['progress'] ?? 0);
    $label = TeamHarvestService::LEVEL_MEMBER_LABELS[$state] ?? ucfirst($state);
?>
                      <tr data-level="<?= (int) $level['level'] ?>">
                        <td class="text-nowrap fw-medium">Level <?= (int) $level['level'] ?></td>
                        <td class="text-nowrap">
                          <?= number_format((int) $level['filled']) ?> of <?= number_format((int) $level['slots']) ?>
                        </td>
                        <td class="text-end text-nowrap"><?= number_format((int) $level['threshold']) ?></td>
                        <td class="text-end text-nowrap fw-semibold"><?= e(money((float) $level['amount'])) ?></td>
                        <td class="text-nowrap">
                          <span class="badge bg-label-<?= e($tone) ?>"><?= e($label) ?></span>
                        </td>
                        <td class="text-end text-nowrap">
                          <div class="d-inline-block commission-matrix-progress" style="min-width: 6rem">
                            <div
                              class="progress"
                              role="progressbar"
                              aria-valuenow="<?= (int) round($pct) ?>"
                              aria-valuemin="0"
                              aria-valuemax="100"
                              aria-label="Level <?= (int) $level['level'] ?> slots filled"
                              style="height: 6px"
                            >
                              <div class="progress-bar bg-<?= e($tone) ?>" style="width: <?= $pct ?>%"></div>
                            </div>
                            <span class="small text-body-secondary"><?= e(number_format($pct, 1, '.', '')) ?>%</span>
                          </div>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body border-top">
                  <p class="small text-body-secondary mb-3">
                    <i class="icon-base bx bx-info-circle me-1"></i>
                    <?= e(TeamHarvestService::EXCLUSIONS_COPY) ?>
                  </p>

<?php if ($queued): ?>
                  <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                    <h6 class="mb-0">Teammates, in qualification order</h6>
                    <span class="small text-body-secondary">
                      <?= (int) $roster_pager->total ?> teammate<?= $roster_pager->total === 1 ? '' : 's' ?>
                    </span>
                  </div>

<?php if ($roster === []): ?>
                  <p class="small text-body-secondary mb-0">
                    No teammates yet. Your first one is the next member below you who qualifies after you did.
                  </p>
<?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                      <thead>
                        <tr>
                          <th scope="col">Position</th>
                          <th scope="col">Member</th>
                          <th scope="col">Slot</th>
                          <th scope="col">Level</th>
                          <th scope="col">Placed under</th>
                          <th scope="col">Qualified</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($roster as $row): ?>
<?php
    $slot    = (int) $row['slot'];
    $under   = is_array($row['placed_under'] ?? null) ? $row['placed_under'] : [];
    $underId = (int) ($under['user_id'] ?? 0);
?>
                        <tr data-slot="<?= $slot ?>">
                          <td class="text-nowrap fw-medium">#<?= (int) $row['position'] ?></td>
                          <td class="text-nowrap">
                            @<?= e((string) $row['username']) ?>
                          </td>
                          <td class="text-nowrap"><?= e($slotLabel($slot)) ?></td>
                          <td class="text-nowrap">Level <?= (int) $row['level'] ?></td>
                          <td class="text-nowrap small text-body-secondary">
<?php if ($underId === 0 || $underId === $user_id): ?>
                            You
<?php else: ?>
                            @<?= e((string) ($under['username'] ?? '')) ?>
<?php endif; ?>
                          </td>
                          <td class="text-nowrap small text-body-secondary">
                            <?= e(format_date((string) $row['qualified_at'])) ?>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

<?= View::partial('pagination', [
    'pager'            => $roster_pager,
    'pager_label'      => 'Teammates',
    'page_param'       => 'roster_page',
    'per_page_param'   => 'roster_per_page',
    'pagination_label' => 'Teammate roster pagination',
]) ?>
<?php endif; ?>
<?php endif; ?>
                </div>

                <div class="card-footer small text-body-secondary">
                  <p class="mb-1"><?= e(TeamHarvestService::ALL_OR_NOTHING_COPY) ?></p>
                  <p class="mb-1"><?= e(TeamHarvestService::PURCHASES_COPY) ?></p>
<?php if ($baseLevels > 0): ?>
                  <p class="mb-0">
                    <?= e(sprintf(
                        TeamHarvestService::BASELINE_COPY,
                        $baseline === null || (string) $baseline === '' ? 'go-live' : format_date((string) $baseline)
                    )) ?>
                  </p>
<?php endif; ?>
                </div>
              </div>
