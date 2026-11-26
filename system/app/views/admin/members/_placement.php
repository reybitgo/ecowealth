<?php
declare(strict_types=1);

/**
 * Admin member page — binary placement panel (Phase 22C).
 *
 * Shows the member's binary node facts and an audited empty-subtree move
 * form. Hidden entirely when the member has no node.
 *
 * @var array{node:array<string,mixed>|null, has_node:bool, slots:list<array{side:string, free:bool}>} $binary_placement
 * @var array<string,mixed> $member
 * @var int                 $member_id
 */

if (!$binary_placement['has_node']): ?>
                  <div class="card mb-6" data-live-region="member-placement">
                    <div class="card-header"><h5 class="card-title mb-0">Binary placement</h5></div>
                    <div class="card-body">
                      <p class="text-body-secondary small mb-0">
                        This member has no binary node yet. They will be placed
                        at registration or by the backfill tool.
                      </p>
                    </div>
                  </div>
<?php return; endif;

$node   = $binary_placement['node'];
$slots  = $binary_placement['slots'];
$upline = $node['upline_id'] !== null ? (int) $node['upline_id'] : 0;
$side   = (string) ($node['side'] ?? '');
$depth  = (int) ($node['depth'] ?? 0);
$path   = (string) ($node['path'] ?? '');
$state  = (string) ($node['state'] ?? '');
$placed = (string) ($node['placed_at'] ?? '');
$moves  = (int) ($node['move_count'] ?? 0);
$uplName = '';

if ($upline > 0) {
    $uplRow = Member::find($upline);
    $uplName = $uplRow !== null ? (string) $uplRow['username'] : '#' . $upline;
}
?>
                  <div class="card mb-6" data-live-region="member-placement">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Binary placement</h5>
                      <span class="badge bg-label-<?= e(BinaryNode::STATUS_BADGES[$state] ?? 'secondary') ?>">
                        <?= e(BinaryNode::STATUS_LABELS[$state] ?? $state) ?>
                      </span>
                    </div>
                    <div class="card-body">
                      <table class="table table-sm mb-3">
                        <tr>
                          <th style="width:35%" class="text-body-secondary">Upline</th>
                          <td><?= e($uplName) ?></td>
                        </tr>
                        <tr>
                          <th class="text-body-secondary">Side</th>
                          <td><?= e(BinaryNode::SIDE_LABELS[$side] ?? $side) ?></td>
                        </tr>
                        <tr>
                          <th class="text-body-secondary">Depth</th>
                          <td><?= $depth ?></td>
                        </tr>
                        <tr>
                          <th class="text-body-secondary">Path</th>
                          <td class="text-truncate" style="max-width:16rem" title="<?= e($path) ?>"><?= e($path) ?></td>
                        </tr>
                        <tr>
                          <th class="text-body-secondary">Placed at</th>
                          <td><?= e($placed) ?></td>
                        </tr>
                        <tr>
                          <th class="text-body-secondary">Moves</th>
                          <td><?= $moves ?></td>
                        </tr>
                      </table>

                      <form
                        method="POST"
                        action="<?= e(url('admin/members/' . $member_id . '/placement')) ?>"
                        class="d-inline"
                        data-confirm
                        data-confirm-title="Move this member in the binary tree?"
                        data-confirm-text="Moving an empty subtree is audited. The member keeps their sponsor and package."
                        data-confirm-button="Move"
                      >
                        <?= csrf_field() ?>
                        <div class="row g-3">
                          <div class="col-md-4">
                            <label class="form-label">New binary upline</label>
                            <input
                              type="text"
                              name="binary_upline"
                              class="form-control"
                              placeholder="Username"
                              required
                            />
                          </div>
                          <div class="col-md-3">
                            <label class="form-label">Position</label>
                            <div class="d-flex gap-2">
                              <label class="form-check">
                                <input class="form-check-input" type="radio" name="binary_position" value="left" checked />
                                <span class="form-check-label">Left</span>
                              </label>
                              <label class="form-check">
                                <input class="form-check-input" type="radio" name="binary_position" value="right" />
                                <span class="form-check-label">Right</span>
                              </label>
                            </div>
                          </div>
                          <div class="col-md-5 d-flex align-items-end">
                            <button
                              type="submit"
                              class="btn btn-label-primary w-100"
                              data-confirm-button="Move"
                            >Move member</button>
                          </div>
                        </div>
                      </form>

                      <p class="text-body-secondary small mt-3">
                        Only an empty subtree can be moved. A node that already
                        carries volume or awards is refused.
                      </p>
                    </div>
                  </div>

<?php unset($node, $slots, $upline, $side, $depth, $path, $state, $placed, $moves, $uplName, $uplRow); ?>
