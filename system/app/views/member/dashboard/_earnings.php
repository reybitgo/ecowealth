<?php
declare(strict_types=1);

/**
 * Member dashboard — the earnings card.
 *
 * A live region: a credited commission (through the wallet movement that
 * pays it), a new downline line and a new activation all re-render the
 * card in place. The card has no form, so it is replaced freely.
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

/* The repeat-purchase tile is a read-out: the member's upline earnings
 * plus the two-clock Active status (17E, plan §9). It is hidden only
 * when the stream has never been on and the member has no history. */
$repeatOn      = is_array($unilevel);
$repeatTotals  = $repeatOn ? ($unilevel['totals'] ?? []) : [];
$repeatStatus  = $repeatOn ? ($unilevel['status'] ?? []) : [];
$repeatAwards  = (int) ($repeatTotals['count'] ?? 0);
$repeatCredited = (float) ($repeatTotals['credited'] ?? 0);
$repeatEnabled = (bool) ($unilevel['enabled'] ?? false);
$repeatShow    = $repeatOn && ($repeatEnabled || $repeatAwards > 0);

$repeatPeriod = (string) ($repeatStatus['period'] ?? UnilevelCycle::periodOf());
$repeatMonth  = strtotime($repeatPeriod . '-01') === false
    ? $repeatPeriod
    : date('F Y', (int) strtotime($repeatPeriod . '-01'));
$repeatActive = (bool) ($repeatStatus['active'] ?? true);
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
                      <div class="alert alert-info py-2 small mb-0 mt-3">
                        No commissions yet. A direct referral is credited the moment someone you sponsored
                        activates a package — <a href="<?= e(url('earnings')) ?>">see earnings</a>.
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
                      <div class="d-flex align-items-center justify-content-between gap-2">
                        <span class="text-body-secondary small">Credited as an upline</span>
                        <strong class="h6 mb-0"><?= e(money($repeatCredited)) ?></strong>
                      </div>
                      <p class="small text-body-secondary mt-2 mb-0">
                        <?= $repeatAwards === 0
                            ? 'No awards yet — a level is credited when a paid order in your team reaches it.'
                            : $repeatAwards . ' award' . ($repeatAwards === 1 ? '' : 's') . ' at levels 1–10.' ?>
                        Your own purchases never pay you;
                        <a href="<?= e(url('earnings?ledger=repeat')) ?>">see the repeat-purchase statement</a>.
                      </p>
<?php endif; ?>
                    </div>
                  </div>
