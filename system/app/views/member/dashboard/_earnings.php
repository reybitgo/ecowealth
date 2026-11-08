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
 */

$commissions   = $commissions ?? ['count' => 0, 'credited' => 0.0, 'this_month' => 0.0, 'last_at' => null];
$team          = $team ?? ['direct' => 0, 'team' => 0, 'levels' => 0];
$recentHistory = $recent_history ?? [];
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
                    </div>
                  </div>
