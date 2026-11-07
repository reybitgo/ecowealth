<?php
declare(strict_types=1);

/**
 * Genealogy page (plan §7 Phase 9; reference views/member/genealogy.php).
 *
 * The sponsor chain only — no binary legs, no placement mode. Upline
 * is nearest-first (level 1 = direct sponsor); the team is grouped by
 * level, breadth-first.
 *
 * @var array<string, mixed>       $member
 * @var list<array<string, mixed>> $upline       SponsorService::chain()
 * @var list<array<string, mixed>> $direct       level-1 downline
 * @var array<int, list<array<string, mixed>>> $deeper
 * @var int                        $team_count
 * @var int                        $levels_used
 * @var string                     $referral_code
 * @var string                     $referral_link
 */

$displayName = trim((string) ($member['full_name'] ?? '')) ?: (string) $member['username'];
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Genealogy</h4>
                  <p class="text-body-secondary mb-0">
                    Your sponsor chain and your team. Commissions follow this chain — one sponsor per member.
                  </p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                  <span class="badge rounded-pill bg-label-primary"><?= (int) $team_count ?> in team</span>
                  <span class="badge rounded-pill bg-label-secondary"><?= (int) $levels_used ?> level<?= (int) $levels_used === 1 ? '' : 's' ?></span>
                </div>
              </div>

              <div class="row g-4">
                <!-- Upline -->
                <div class="col-xl-6">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Upline chain</h5>
                      <small class="text-body-secondary">Nearest sponsor first, up to ten levels.</small>
                    </div>
                    <div class="card-body">
<?php if ($upline === []): ?>
                      <div class="alert alert-secondary py-3 mb-0 small">
                        This account is the top of its chain — it has no sponsor.
                      </div>
<?php else: ?>
                      <div class="genealogy-upline">
<?php foreach ($upline as $row): ?>
<?php $sponsor = $row['member']; ?>
                        <div class="upline-row">
                          <span class="upline-level">L<?= (int) $row['level'] ?></span>
                          <div class="flex-grow-1 min-w-0">
                            <div class="fw-medium text-truncate">
                              <?= e((string) $sponsor['username']) ?>
                              <span class="text-body-secondary small">
                                <?= $sponsor['package_name'] === null ? '· no package' : '· ' . e((string) $sponsor['package_name']) ?>
                              </span>
                            </div>
                            <div class="member-meta">
                              <?= e(ucfirst(str_replace('_', ' ', (string) $sponsor['member_status']))) ?>
                              · joined <?= e(format_datetime((string) $sponsor['created_at'])) ?>
                            </div>
                          </div>
                          <?= status_badge((string) $sponsor['member_status']) ?>
                        </div>
<?php endforeach; ?>
                        <div class="upline-row is-self">
                          <span class="upline-level"><i class="icon-base bx bx-user"></i></span>
                          <div class="flex-grow-1 min-w-0">
                            <div class="fw-medium text-truncate">
                              <?= e($displayName) ?> <span class="text-body-secondary small">· you</span>
                            </div>
                            <div class="member-meta">
                              <?= $member['package_name'] === null ? 'No package' : e((string) $member['package_name']) ?>
                            </div>
                          </div>
                          <?= status_badge((string) $member['member_status']) ?>
                        </div>
                      </div>
<?php endif; ?>

                      <hr class="my-4" />

                      <label class="form-label small text-body-secondary" for="genealogyReferral">Your referral link</label>
                      <div class="referral-box">
                        <input
                          type="text"
                          class="form-control referral-link-input"
                          id="genealogyReferral"
                          value="<?= e($referral_link) ?>"
                          readonly
                          onfocus="this.select()"
                        />
                        <button
                          type="button"
                          class="btn btn-label-primary"
                          data-copy="<?= e($referral_link) ?>"
                          data-copy-label="Copy"
                          data-copy-tooltip="Copy link"
                          data-bs-toggle="tooltip"
                          title="Copy link"
                          aria-label="Copy referral link"
                        ><i class="icon-base bx bx-copy me-1"></i><span data-copy-text>Copy</span></button>
                      </div>
                      <div class="small text-body-secondary mt-2">Code <strong><?= e($referral_code) ?></strong></div>
                    </div>
                  </div>
                </div>

                <!-- Direct recruits -->
                <div class="col-xl-6">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="card-title mb-0">Direct recruits</h5>
                        <small class="text-body-secondary">Level 1 — members you sponsored.</small>
                      </div>
                      <span class="badge bg-label-primary"><?= count($direct) ?></span>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Member</th>
                            <th>Package</th>
                            <th>Status</th>
                            <th>Joined</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($direct === []): ?>
                          <tr>
                            <td colspan="4" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-user-plus',
        'title'        => 'No direct recruits yet',
        'text'         => 'Share your referral link — new signups appear here immediately.',
    ]);
?>
                            </td>
                          </tr>
<?php endif; ?>
<?php foreach ($direct as $row): ?>
                          <tr>
                            <td>
                              <span class="fw-medium">@<?= e((string) $row['username']) ?></span>
                              <?php if (trim((string) ($row['full_name'] ?? '')) !== ''): ?>
                              <div class="member-meta"><?= e((string) $row['full_name']) ?></div>
                              <?php endif; ?>
                            </td>
                            <td><?= $row['package_name'] === null ? '<span class="text-body-secondary">—</span>' : e((string) $row['package_name']) ?></td>
                            <td><?= status_badge((string) $row['member_status']) ?></td>
                            <td class="text-nowrap"><span class="member-meta"><?= e(format_datetime((string) $row['created_at'])) ?></span></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php if (isset($recruits_pager) && $recruits_pager instanceof Paginator): ?>
                    <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $recruits_pager, 'pager_label' => 'Recruits']) ?>
                    </div>
<?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Team by level -->
              <div class="card mt-4">
                <div class="card-header">
                  <h5 class="card-title mb-0">Team by level</h5>
                  <small class="text-body-secondary">Breadth-first through sponsor links, up to ten levels.</small>
                </div>
                <div class="card-body">
<?php if ($deeper === []): ?>
                  <p class="text-body-secondary small mb-0">
                    No second-level members yet. Your direct recruits' own recruits will show up here.
                  </p>
<?php else: ?>
                  <div class="row g-4">
<?php foreach ($deeper as $level => $rows): ?>
                    <div class="col-md-6 col-xl-4">
                      <div class="border rounded-3 p-3 h-100">
                        <div class="team-level-chip">
                          <span class="badge bg-label-primary">Level <?= (int) $level ?></span>
                          <span class="text-body-secondary small"><?= count($rows) ?> member<?= count($rows) === 1 ? '' : 's' ?></span>
                        </div>
                        <ul class="list-unstyled mb-0 small">
<?php foreach ($rows as $row): ?>
                          <li class="d-flex align-items-center justify-content-between gap-2 py-1">
                            <span class="text-truncate">
                              @<?= e((string) $row['username']) ?>
                              <?php if ($row['package_name'] !== null): ?>
                              <span class="text-body-secondary d-block member-meta"><?= e((string) $row['package_name']) ?></span>
                              <?php endif; ?>
                            </span>
                            <?= status_badge((string) $row['member_status']) ?>
                          </li>
<?php endforeach; ?>
                        </ul>
                      </div>
                    </div>
<?php endforeach; ?>
                  </div>
<?php endif; ?>
                </div>
              </div>
            </div>
