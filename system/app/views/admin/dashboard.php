<?php
declare(strict_types=1);

/**
 * Admin dashboard placeholder (Phase 1). Live account counts only —
 * commerce KPIs join as their phases ship (plan §7 Phase 1, §3.5).
 *
 * @var array{members:int, members_active:int, admins:int, settings:int} $stats
 * @var list<array<string, mixed>> $recent
 */

$me = auth_user();

$cards = [
    ['label' => 'Members', 'value' => $stats['members'], 'icon' => 'bx-group', 'tone' => 'primary', 'hint' => $stats['members_active'] . ' active'],
    ['label' => 'Back-office accounts', 'value' => $stats['admins'], 'icon' => 'bx-shield-quarter', 'tone' => 'info', 'hint' => 'admin + superadmin'],
    ['label' => 'Settings stored', 'value' => $stats['settings'], 'icon' => 'bx-cog', 'tone' => 'success', 'hint' => 'key/value configuration'],
    ['label' => 'Build phase', 'value' => APP_PHASE, 'icon' => 'bx-layer', 'tone' => 'warning', 'hint' => 'of 15'],
];

$roadmap = [
    ['phase' => 1, 'label' => 'Core skeleton: router, layouts, roles, settings, tests'],
    ['phase' => 2, 'label' => 'Products & storefront catalogue'],
    ['phase' => 3, 'label' => 'Packages & package contents'],
    ['phase' => 4, 'label' => 'Cart engine & cart UI'],
    ['phase' => 5, 'label' => 'E-wallet core: dual balance, ledger, top-ups'],
    ['phase' => 6, 'label' => 'Checkout & order placement'],
];
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Back office</h4>
                  <p class="text-body-secondary mb-0">
                    Signed in as <strong><?= e($me['username'] ?? 'admin') ?></strong>
                    (<?= e((string) ($me['role'] ?? 'admin')) ?>) · phase <?= (int) APP_PHASE ?> of 15
                  </p>
                </div>
                <a href="<?= e(url('dashboard')) ?>" class="btn btn-label-primary">
                  <i class="icon-base bx bx-user me-1"></i> Member view
                </a>
              </div>

              <div class="row g-6 mb-6">
<?php foreach ($cards as $card): ?>
                <div class="col-sm-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-4">
                        <div class="avatar">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e($card['icon']) ?> icon-md"></i>
                          </span>
                        </div>
                      </div>
                      <p class="text-body-secondary mb-1"><?= e($card['label']) ?></p>
                      <h3 class="mb-1"><?= e((string) $card['value']) ?></h3>
                      <small class="text-body-secondary"><?= e($card['hint']) ?></small>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <div class="row g-6">
                <div class="col-xl-7">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="mb-0">Newest accounts</h5>
                      <span class="badge bg-label-secondary"><?= count($recent) ?> shown</span>
                    </div>
                    <div class="table-responsive">
                      <table class="table">
                        <thead>
                          <tr>
                            <th>Account</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($recent === []): ?>
                          <tr>
                            <td colspan="4" class="text-center text-body-secondary py-6">No accounts yet.</td>
                          </tr>
<?php endif; ?>
<?php foreach ($recent as $row): ?>
                          <tr>
                            <td>
                              <div class="d-flex align-items-center gap-2">
                                <span class="avatar avatar-sm">
                                  <span class="avatar-initial rounded-circle bg-label-primary"><?= e(initials((string) $row['username'])) ?></span>
                                </span>
                                <div>
                                  <div class="fw-medium"><?= e((string) $row['username']) ?></div>
                                  <small class="text-body-secondary"><?= e((string) $row['email']) ?></small>
                                </div>
                              </div>
                            </td>
                            <td><?= e((string) $row['role']) ?></td>
                            <td><?= status_badge((int) $row['is_active'] === 1 ? 'active' : 'inactive') ?></td>
                            <td class="text-nowrap"><?= e(format_datetime((string) $row['created_at'])) ?></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <div class="col-xl-5">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="mb-0">Build roadmap</h5>
                    </div>
                    <div class="card-body">
                      <ul class="list-unstyled mb-0">
<?php $lastStep = count($roadmap) - 1; ?>
<?php foreach ($roadmap as $index => $step): ?>
                        <li class="d-flex align-items-start gap-3 <?= $index === $lastStep ? '' : 'mb-4' ?>">
                          <span class="badge rounded-pill <?= $step['phase'] <= APP_PHASE ? 'bg-label-success' : 'bg-label-secondary' ?> mt-1">
                            <?= $step['phase'] <= APP_PHASE ? '✓' : $step['phase'] ?>
                          </span>
                          <div>
                            <div class="fw-medium <?= $step['phase'] > APP_PHASE ? 'text-body-secondary' : '' ?>">
                              <?= e($step['label']) ?>
                            </div>
                          </div>
                        </li>
<?php endforeach; ?>
                      </ul>
                      <p class="text-body-secondary small mb-0 mt-4">
                        Menu items marked <span class="badge rounded-pill bg-label-secondary">Soon</span>
                        are config placeholders for later phases; they are hidden when APP_DEBUG is off.
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
