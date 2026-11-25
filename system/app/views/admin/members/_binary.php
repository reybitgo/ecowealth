<?php
declare(strict_types=1);

/**
 * Admin member page — the Product Binary panel (22E).
 *
 * Read-only. Shows this member's node facts, the cached point balances,
 * the pair and points-matched lifetime counts, the pairs used today
 * against the daily cap, the blocked nodes in their subtree (with
 * reasons), and the recent volume and award rows. Every number traces to
 * a `binary_nodes`, `binary_volume` or `binary_awards` row — nothing here
 * is derived from a cache that the audit cannot explain.
 *
 * The member's own node is shown (upline, side) because that is the
 * admin's job; the tree itself is not rendered here — the *Open in tree*
 * link opens the binary explorer rooted at this member.
 *
 * @var array{
 *   node:array<string,mixed>|null,
 *   has_node:bool,
 *   totals:array<string,mixed>,
 *   awards:list<array<string,mixed>>,
 *   volume:list<array<string,mixed>>,
 *   blocked:list<array<string,mixed>>,
 *   pairs_today:int,
 *   daily_cap:int,
 * } $binary
 * @var array<string,mixed> $member
 * @var int                 $member_id
 *
 * Source split (plan 23D): product vs package points/pairs/earnings,
 * computed from the volume and award rows already loaded; qualification
 * state from the member's package.
 */

if (!$binary['has_node']): ?>
                  <div class="card mb-4" data-live-region="member-binary">
                    <div class="card-header"><h5 class="card-title mb-0">Product binary</h5></div>
                    <div class="card-body">
                      <p class="text-body-secondary small mb-0">
                        <strong>Not placed in the binary tree.</strong> This member has no binary node,
                        so no points can roll up to them and they cannot earn a pair.
                      </p>
                      <a
                        href="<?= e(url('admin/members/' . $member_id)) ?>"
                        class="btn btn-sm btn-label-primary"
                      >
                        Open the placement console
                      </a>
                      <span class="text-body-secondary small mt-2 d-block">
                        Only an admin can place a member, and only into an empty slot.
                      </span>
                    </div>
                  </div>
<?php return; endif;

$node      = $binary['node'];
$totals    = $binary['totals'];
$awards    = $binary['awards'];
$volume    = $binary['volume'];
$blocked   = $binary['blocked'];
$pairsToday= (int) ($binary['pairs_today'] ?? 0);
$dailyCap  = (int) ($binary['daily_cap'] ?? 0);

$uplineId   = (int) ($node['upline_id'] ?? 0);
$side       = (string) ($node['side'] ?? '');
$depth      = (int) ($node['depth'] ?? 0);
$path       = (string) ($node['path'] ?? '');
$state      = (string) ($node['state'] ?? '');
$placedAt   = (string) ($node['placed_at'] ?? '');
$placedById = (int) ($node['placed_by'] ?? 0);
$moves      = (int) ($node['move_count'] ?? 0);
$leftPts    = (int) ($totals['left_points'] ?? 0);
$rightPts   = (int) ($totals['right_points'] ?? 0);
$personal   = (int) ($totals['personal_points'] ?? 0);
$pairsPaid  = (int) ($totals['pairs_paid'] ?? 0);
$matched    = (int) ($totals['points_matched'] ?? 0);

$uplName = '';
if ($uplineId > 0) {
    $uplRow = Member::find($uplineId);
    $uplName = $uplRow !== null ? (string) $uplRow['username'] : '#' . $uplineId;
}

$placedByName = '';
if ($placedById > 0) {
    $pbRow = Member::find($placedById);
    $placedByName = $pbRow !== null ? (string) ($pbRow['full_name'] ?? $pbRow['username']) : '#' . $placedById;
}

/* ---- Source split: product vs package (plan 23D) ---- */
$productPoints = 0;
$packagePoints = 0;
$productPairs  = 0;
$packagePairs  = 0;
$productEarned = 0.0;
$packageEarned = 0.0;

foreach ($volume as $vrow) {
    if ((string) ($vrow['kind'] ?? '') !== 'team') {
        continue;
    }
    $points = (int) ($vrow['points'] ?? 0);
    if ($points <= 0) {
        continue;
    }
    if ((string) ($vrow['source'] ?? '') === 'activation') {
        $packagePoints += $points;
    } else {
        $productPoints += $points;
    }
}

foreach ($awards as $arow) {
    if ((string) ($arow['kind'] ?? '') !== 'pair') {
        continue;
    }
    $status = (string) ($arow['status'] ?? '');
    if (!in_array($status, ['pending', 'credited'], true)) {
        continue;
    }
    $amount = (float) ($arow['amount'] ?? 0);
    if ((string) ($arow['source'] ?? '') === 'activation') {
        $packagePairs += (int) ($arow['pair_count'] ?? 0);
        $packageEarned = round($packageEarned + $amount, 2);
    } else {
        $productPairs  += (int) ($arow['pair_count'] ?? 0);
        $productEarned = round($productEarned + $amount, 2);
    }
}

/* Qualification: does this member hold a binary-enabled package? */
$memberPackageId = (int) ($member['package_id'] ?? 0);
$qualified       = $memberPackageId > 0 && Package::binaryEnabled($memberPackageId);
$qualifiedLabel  = $qualified
    ? 'Binary package — earns package pairs'
    : 'No binary package — package activations place no points here';
?>
                  <div class="card mb-4" data-live-region="member-binary">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <h5 class="card-title mb-0">Product binary</h5>
                      <span class="badge bg-label-<?= e(BinaryNode::STATUS_BADGES[$state] ?? 'secondary') ?>">
                        <?= e(BinaryNode::STATUS_LABELS[$state] ?? $state) ?>
                      </span>
                    </div>
                    <div class="card-body">
                      <div class="row g-3 mb-3">
                        <div class="col-sm-6 col-lg-3">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Upline</div>
                              <div class="fw-semibold"><?= e($uplName !== '' ? $uplName : '—') ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Side</div>
                              <div class="fw-semibold"><?= e(BinaryNode::SIDE_LABELS[$side] ?? $side) ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Depth</div>
                              <div class="fw-semibold"><?= $depth ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Path</div>
                              <div class="fw-semibold text-truncate" style="max-width:12rem" title="<?= e($path) ?>"><?= e($path) ?></div>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="row g-3 mb-3">
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Left points</div>
                              <div class="fw-semibold"><?= e(number_format($leftPts, 0, '.', '')) ?> pts</div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Right points</div>
                              <div class="fw-semibold"><?= e(number_format($rightPts, 0, '.', '')) ?> pts</div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Personal points</div>
                              <div class="fw-semibold"><?= e(number_format($personal, 0, '.', '')) ?> pts</div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Points matched</div>
                              <div class="fw-semibold"><?= e(number_format($matched, 0, '.', '')) ?> pts</div>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="row g-3 mb-3">
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Pairs paid</div>
                              <div class="fw-semibold"><?= $pairsPaid ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Pairs today</div>
                              <div class="fw-semibold"><?= $pairsToday ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Daily cap</div>
                              <div class="fw-semibold"><?= $dailyCap ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Room today</div>
                          <div class="fw-semibold"><?= e(number_format(max(0, $dailyCap - $pairsToday), 0, '.', '')) ?></div>
                            </div>
                          </div>
                        </div>
                      </div>

                      <!-- Source split: product vs package (plan 23D) -->
                      <div class="row g-3 mb-3">
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Product points</div>
                              <div class="fw-semibold"><?= e(number_format($productPoints, 0, '.', '')) ?> pts</div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Package points</div>
                              <div class="fw-semibold"><?= e(number_format($packagePoints, 0, '.', '')) ?> pts</div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Product pairs</div>
                              <div class="fw-semibold"><?= $productPairs ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-3 col-sm-6">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Package pairs</div>
                              <div class="fw-semibold"><?= $packagePairs ?></div>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="row g-3 mb-3">
                        <div class="col-xl-6 col-sm-12">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Product earned</div>
                              <div class="fw-semibold"><?= e(money($productEarned)) ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-6 col-sm-12">
                          <div class="card card-small text-body-secondary">
                            <div class="card-body py-2">
                              <div class="small text-uppercase text-body-secondary">Package earned</div>
                              <div class="fw-semibold"><?= e(money($packageEarned)) ?></div>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="mb-3">
                        <div class="small text-uppercase text-body-secondary mb-1">Package qualification</div>
                        <span class="badge bg-label-<?= $qualified ? 'success' : 'secondary' ?>"><?= e($qualifiedLabel) ?></span>
                      </div>
<?php if ($blocked !== []): ?>
                      <div class="mb-3">
                        <div class="small text-uppercase text-body-secondary mb-1">Blocked pairs in your subtree</div>
                        <table class="table table-sm align-middle mb-0">
                          <thead>
                            <tr>
                              <th scope="col" style="width:34%">Member</th>
                              <th scope="col" style="width:26%">Side</th>
                              <th scope="col" class="text-end" style="width:16%">Left</th>
                              <th scope="col" class="text-end" style="width:16%">Right</th>
                              <th scope="col">Reason</th>
                            </tr>
                          </thead>
                          <tbody>
<?php foreach ($blocked as $row): ?>
                            <tr>
                              <td class="text-nowrap">
                                @<?= e((string) ($row['username'] ?? '')) ?>
                                <span class="small text-body-secondary d-block">#<?= (int) ($row['user_id'] ?? 0) ?></span>
                              </td>
                              <td class="text-nowrap"><?= e(BinaryNode::SIDE_LABELS[(string) ($row['side'] ?? '')] ?? $row['side'] ?? '—') ?></td>
                              <td class="text-end text-nowrap"><?= e(number_format((int) ($row['left_points'] ?? 0), 0, '.', '')) ?></td>
                              <td class="text-end text-nowrap"><?= e(number_format((int) ($row['right_points'] ?? 0), 0, '.', '')) ?></td>
                              <td class="text-body-secondary small"><?= e((string) ($row['reason'] ?? '')) ?></td>
                            </tr>
<?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
<?php endif; ?>

                      <div class="row g-3 mb-3">
                        <div class="col-xl-6">
                          <div class="card card-small">
                            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                              <h6 class="mb-0">Recent volume</h6>
                            </div>
                            <div class="card-body p-2">
                              <table class="table table-sm align-middle mb-0">
                                <thead>
                                  <tr>
                                    <th scope="col" class="text-nowrap" style="width:22%">When</th>
                                    <th scope="col" style="width:14%" class="text-end">Side</th>
                                    <th scope="col" class="text-end" style="width:14%">Kind</th>
                                    <th scope="col" class="text-end" style="width:16%">Points</th>
                                    <th scope="col" class="text-end" style="width:12%">Source</th>
                                    <th scope="col">Order</th>
                                  </tr>
                                </thead>
                                <tbody>
<?php if ($volume === []): ?>
                                  <tr>
                                    <td colspan="6" class="text-body-secondary text-center py-2">No volume rows yet.</td>
                                  </tr>
<?php else: ?>
<?php foreach (array_slice($volume, 0, 8) as $row): ?>
                                  <tr>
                                    <td class="text-nowrap small">
                                      <?= e(format_datetime((string) ($row['created_at'] ?? ''))) ?>
                                    </td>
                                    <td class="text-end text-nowrap small">
                                      <?= e(BinaryNode::SIDE_LABELS[(string) ($row['side'] ?? '')] ?? $row['side'] ?? '—') ?>
                                    </td>
                                    <td class="text-end text-nowrap small">
                                      <?= e(ucfirst((string) ($row['kind'] ?? ''))) ?>
                                    </td>
                                    <td class="text-end text-nowrap small fw-semibold">
                                      <?= e(number_format((int) ($row['points'] ?? 0), 0, '.', '')) ?> pts
                                    </td>
                                    <td class="text-end text-nowrap small">
                                      <?= e((string) ($row['source'] ?? 'order') === 'activation' ? 'Package' : 'Product') ?>
                                    </td>
                                    <td class="text-body-secondary small">
                                      #<?= (int) ($row['order_id'] ?? 0) ?>
                                    </td>
                                  </tr>
<?php endforeach; ?>
<?php endif; ?>
                                </tbody>
                              </table>
                            </div>
                          </div>
                        </div>
                        <div class="col-xl-6">
                          <div class="card card-small">
                            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                              <h6 class="mb-0">Recent awards</h6>
                            </div>
                            <div class="card-body p-2">
                              <table class="table table-sm align-middle mb-0">
                                <thead>
                                  <tr>
                                    <th scope="col" class="text-nowrap" style="width:22%">When</th>
                                    <th scope="col" style="width:14%" class="text-end">Kind</th>
                                    <th scope="col" class="text-end" style="width:16%">Amount</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end" style="width:12%">Source</th>
                                    <th scope="col" class="text-end" style="width:18%">Order</th>
                                  </tr>
                                </thead>
                                <tbody>
<?php if ($awards === []): ?>
                                  <tr>
                                    <td colspan="6" class="text-body-secondary text-center py-2">No award rows yet.</td>
                                  </tr>
<?php else: ?>
<?php foreach (array_slice($awards, 0, 8) as $row): ?>
<?php
    $awardStatus = (string) ($row['status'] ?? '');
    $amount      = (float) ($row['amount'] ?? 0);
?>
                                  <tr>
                                    <td class="text-nowrap small">
                                      <?= e(format_datetime((string) ($row['created_at'] ?? ''))) ?>
                                    </td>
                                    <td class="text-end text-nowrap small">
                                      <?= e(ucfirst((string) ($row['kind'] ?? ''))) ?>
                                    </td>
                                    <td class="text-end text-nowrap small fw-semibold">
                                      <?= e(money($amount)) ?>
                                    </td>
                                    <td class="text-nowrap small">
                                      <?= status_badge($awardStatus, BinaryAward::STATUS_BADGES) ?>
                                    </td>
                                    <td class="text-end text-nowrap small">
                                      <?= e((string) ($row['source'] ?? 'order') === 'activation' ? 'Package' : 'Product') ?>
                                    </td>
                                    <td class="text-body-secondary small text-end">
                                      #<?= (int) ($row['order_id'] ?? 0) ?>
                                    </td>
                                  </tr>
<?php endforeach; ?>
<?php endif; ?>
                                </tbody>
                              </table>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="mt-3">
                        <a
                          href="<?= e(url('genealogy?view=binary&root=' . (int) $member['id'])) ?>"
                          class="btn btn-sm btn-label-primary"
                        >
                          Open in tree
                        </a>
                        <span class="text-body-secondary small ms-2">
                          Opens the binary tree rooted at this member.
                        </span>
                      </div>

                    </div>
                  </div>

<?php unset($node, $totals, $awards, $volume, $blocked, $uplineId, $side, $depth, $path, $state, $placedAt, $placedById, $moves, $leftPts, $rightPts, $personal, $pairsPaid, $matched, $uplName, $uplRow, $placedByName, $pbRow, $pairsToday, $dailyCap); ?>
