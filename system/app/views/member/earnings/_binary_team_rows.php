<?php
declare(strict_types=1);

/**
 * Member Product binary statement — one side's roster rows (22F, plan §10,
 * Appendix C.3).
 *
 * Each row is one team member under the viewer's node on this side:
 * username, status badge, package, own points and branch points.
 * Branch points are that member's subtree personal points — never an
 * upline figure (plan §14.12, D-22).
 *
 * @var list<array<string, mixed>> $rows
 * @var string                     $side        'left' or 'right'
 * @var string                     $pageParam   'left_page' or 'right_page'
 * @var string                     $perPageParam
 * @var Paginator                  $pager
 * @var string                     $emptyLabel  'Left' or 'Right'
 * @var string                     $inviteHint  HTML invite sentence
 */

$sideLabel = (string) ($side === 'left' ? 'Left' : 'Right');
$sideBadge = $side === 'left' ? 'bg-label-primary' : 'bg-label-info';
?>
<?php if ($rows === []): ?>
              <div class="text-center py-4">
                <i class="icon-base bx bx-user-plus text-body-muted mb-2" style="font-size:2rem"></i>
                <p class="text-body-secondary mb-2">
                  <strong>No members on your <?= e(strtolower($sideLabel)) ?> team yet.</strong>
                </p>
                <p class="text-body-secondary small mb-0">
                  Place a repeat purchase on the <?= e(strtolower($sideLabel)) ?> side or share an
                  invite link to grow your team.
                </p>
              </div>
<?php else: ?>
                    <table class="table table-hover align-middle mb-0">
                      <thead>
                        <tr>
                          <th scope="col" style="width:28%">Member</th>
                          <th scope="col" style="width:16%" class="text-end">Status</th>
                          <th scope="col" style="width:18%">Package</th>
                          <th scope="col" class="text-end" style="width:16%">Own points</th>
                          <th scope="col" class="text-end" style="width:16%">Branch points</th>
                          <th scope="col" style="width:6%" class="text-end">Side</th>
                        </tr>
                      </thead>
                      <tbody data-live-region="earnings-binary-team-<?= e($side) ?>-rows">
<?php foreach ($rows as $row): ?>
<?php
    $status     = (string) ($row['member_status'] ?? '');
    $isActive   = (int) ($row['is_active'] ?? 0);
    $eligible   = $status === 'active' && $isActive === 1;
    $statusText = $eligible ? 'Active' : ($status === 'active' ? 'Active (account off)' : 'Inactive');
    $badgeTone  = $eligible ? 'bg-label-success' : 'bg-label-warning';
    $pkgName    = (string) ($row['package_name'] ?? '');
    $ownPts     = (int) ($row['own_points'] ?? 0);
    $branchPts  = (int) ($row['branch_points'] ?? 0);
?>
                        <tr>
                          <td>
                            <span class="fw-medium">@<?= e($row['username']) ?></span>
                            <?php if ($pkgName !== ''): ?>
                              <div class="small text-body-secondary"><?= e($pkgName) ?></div>
                            <?php endif; ?>
                          </td>
                          <td class="text-end">
                            <span class="badge <?= e($badgeTone) ?> text-capitalize"><?= e($statusText) ?></span>
                          </td>
                          <td class="text-body-secondary small">
                            <?= $pkgName !== '' ? e($pkgName) : '—' ?>
                          </td>
                          <td class="text-end text-nowrap fw-semibold">
                            <?= e(number_format($ownPts, 0, '.', '')) ?> pts
                          </td>
                          <td class="text-end text-nowrap fw-semibold">
                            <?= e(number_format($branchPts, 0, '.', '')) ?> pts
                          </td>
                          <td class="text-end text-nowrap">
                            <span class="badge <?= e($sideBadge) ?> text-capitalize xs"><?= e($sideLabel) ?></span>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
<?php endif; ?>
