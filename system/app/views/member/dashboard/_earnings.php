<?php
declare(strict_types=1);

/**
 * Member dashboard — the earnings card.
 *
 * A live region: a credited commission (through the wallet movement that
 * pays it), a new downline line and a new activation all re-render the
 * card in place. The card has no form, so it is replaced freely.
 *
 * Phase 18 splits the repeat-purchase figure into what is **credited**
 * (already in the wallet), what is **pending release** (earned, not in
 * the wallet yet) and the **total** the member has earned as an upline
 * (plan §10.2, ESC-08). The pending line is hidden at zero so an
 * ordinary dashboard is unchanged.
 *
 * @var array{count:int, credited:float, this_month:float, last_at:?string} $commissions
 * @var array{direct:int, team:int, levels:int} $team
 * @var list<array<string, mixed>> $recent_history
 * @var array{totals:array<string, mixed>, status:array<string, mixed>, enabled:bool}|null $unilevel
 */

$commissions   = $commissions ?? ['count' => 0, 'credited' => 0.0, 'this_month' => 0.0, 'last_at' => null];
$team          = $team ?? ['direct' => 0, 'team' => 0, 'levels' => 0];
$recentHistory = $recent_history ?? [];
$unilevel      = $unilevel ?? null;

/* The repeat-purchase tile is a read-out: the member's Team chain
 * earnings plus the two-clock Active status (17E, plan §9). It is
 * hidden only when the stream has never been on and the member has no
 * history. */
$repeatOn      = is_array($unilevel);
$repeatTotals  = $repeatOn ? ($unilevel['totals'] ?? []) : [];
$repeatStatus  = $repeatOn ? ($unilevel['status'] ?? []) : [];
$repeatCredited = (float) ($repeatTotals['credited'] ?? 0);
$repeatPending = (float) ($repeatTotals['pending'] ?? 0);
$repeatAwards  = (int) ($repeatTotals['count'] ?? 0) + (int) ($repeatTotals['pending_count'] ?? 0);
$repeatTotal   = round($repeatCredited + $repeatPending, 2);
$repeatEnabled = (bool) ($unilevel['enabled'] ?? false);
$repeatShow    = $repeatOn && ($repeatEnabled || $repeatAwards > 0);

$repeatPeriod = (string) ($repeatStatus['period'] ?? UnilevelCycle::periodOf());
$repeatMonth  = strtotime($repeatPeriod . '-01') === false
    ? $repeatPeriod
    : date('F Y', (int) strtotime($repeatPeriod . '-01'));
$repeatActive = (bool) ($repeatStatus['active'] ?? true);

/* The escrow totals carry the next promised release; without one the
 * copy stays at "after the return window" (plan §10.6). */
$repeatNext        = $repeatTotals['next_release_at'] ?? null;
$repeatPendingNote = 'Pending amounts become withdrawable after the return window';

if ($repeatNext !== null && trim((string) $repeatNext) !== '') {
    $repeatPendingNote .= ' — next release ' . format_date((string) $repeatNext);
}

$repeatPendingNote .= '.';
?>
                  <div class="card h-100" data-live-region="dashboard-earnings">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">Earnings</h5>
                      <div class="d-flex gap-2">
                        <a href="<?= e(url('genealogy')) ?>" class="btn btn-sm btn-label-secondary">Genealogy</a>
                        <a href="<?= e(url('earnings')) ?>" class="btn btn-sm btn-label-primary">All awards</a>
                      </div>
                    </div>
                    <div class="card-body">
                      <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="avatar">
                          <span class="avatar-initial rounded bg-label-warning">
                            <i class="icon-base bx bx-line-chart"></i>
                          </span>
                        </span>
                        <div>
                          <div class="h4 mb-0"><?= e(money((float) $commissions['credited'])) ?></div>
                          <small class="text-body-secondary">Total credited</small>
                        </div>
                      </div>

                      <ul class="list-unstyled mb-0 small">
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">This month</span>
                          <strong><?= e(money((float) $commissions['this_month'])) ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Awards</span>
                          <strong><?= (int) $commissions['count'] ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1 border-bottom">
                          <span class="text-body-secondary">Direct recruits</span>
                          <strong><?= (int) $team['direct'] ?></strong>
                        </li>
                        <li class="d-flex align-items-center justify-content-between py-1">
                          <span class="text-body-secondary">Team size</span>
                          <strong><?= (int) $team['team'] ?></strong>
                        </li>
                      </ul>

<?php if ($recentHistory !== []): ?>
                      <hr class="my-3" />
                      <h6 class="mb-2">Recent activations</h6>
                      <ul class="list-unstyled mb-0 small">
<?php foreach ($recentHistory as $activation): ?>
                        <li class="d-flex align-items-center justify-content-between gap-2 py-1">
                          <span class="text-truncate">
                            <?= e((string) $activation['package_name']) ?>
                            <span class="text-body-secondary d-block member-meta">
                              <?= e(format_datetime((string) $activation['created_at'])) ?>
                            </span>
                          </span>
                          <?= status_badge((string) $activation['status']) ?>
                        </li>
<?php endforeach; ?>
                      </ul>
<?php else: ?>
                      <div class="mt-3">
<?php
    echo View::partial('empty_state', [
        'compact'      => true,
        'icon'         => 'bx bx-line-chart',
        'title'        => 'No commissions yet',
        'text'         => 'A direct referral is credited the moment someone you sponsored activates a package.',
        'action_href'  => 'earnings',
        'action_label' => 'See earnings',
    ]);
?>
                      </div>
<?php endif; ?>

<?php if ($repeatShow): ?>
                      <hr class="my-3" />
                      <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <h6 class="mb-0">Repeat-purchase bonus</h6>
                        <span class="badge <?= $repeatActive ? 'bg-label-success' : 'bg-label-secondary' ?>">
                          <?= $repeatActive ? 'Active' : 'Not Active' ?> for <?= e($repeatMonth) ?>
                        </span>
                      </div>
                      <ul class="list-unstyled mb-0 small">
                        <li class="d-flex align-items-center justify-content-between py-1">
                          <span class="text-body-secondary">Credited</span>
                          <strong><?= e(money($repeatCredited)) ?></strong>
                        </li>
<?php if ($repeatPending > 0): ?>
                        <li class="d-flex align-items-center justify-content-between py-1">
                          <span class="text-body-secondary">Pending release</span>
                          <strong class="text-info"><?= e(money($repeatPending)) ?></strong>
                        </li>
<?php endif; ?>
                        <li class="d-flex align-items-center justify-content-between py-1 border-top">
                          <span class="text-body-secondary">Total earned</span>
                          <strong><?= e(money($repeatTotal)) ?></strong>
                        </li>
                      </ul>
                      <p class="small text-body-secondary mt-2 mb-0">
<?php if ($repeatAwards === 0): ?>
                        No awards yet — a <strong>Team chain</strong> order earns your level when it is paid.
<?php else: ?>
                        <?= $repeatAwards ?> award<?= $repeatAwards === 1 ? '' : 's' ?> at levels 1–10 from your
                        <strong>Team chain</strong>'s paid orders.
<?php endif; ?>
<?php if ($repeatPending > 0): ?>
                        <?= e($repeatPendingNote) ?>
<?php endif; ?>
                        Your own purchases keep you Active; your Team chain pays you.
                        <a href="<?= e(url('earnings?ledger=repeat')) ?>">See the repeat-purchase statement</a>.
                      </p>
<?php endif; ?>
                    </div>
                  </div>
