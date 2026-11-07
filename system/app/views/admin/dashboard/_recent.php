<?php
declare(strict_types=1);

/**
 * Admin dashboard recent-accounts card.
 *
 * @var list<array<string, mixed>> $recent
 */

$recent = $recent ?? [];

$roleTone = static fn (string $role): string => match ($role) {
    'superadmin' => 'info',
    'admin'      => 'primary',
    default      => 'secondary',
};
?>
                <div class="col-xl-8" data-live-region="dashboard-recent">
                  <div class="card h-100">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="card-title mb-1">Recent accounts</h5>
                        <p class="text-body-secondary small mb-0">The newest sign-ups across the platform.</p>
                      </div>
                      <span class="badge rounded-pill bg-label-secondary"><?= count($recent) ?> shown</span>
                    </div>
                    <div class="table-responsive">
                      <table class="table dashboard-table mb-0">
                        <thead>
                          <tr>
                            <th>Account</th>
                            <th class="d-none d-md-table-cell">Role</th>
                            <th>Status</th>
                            <th class="d-none d-lg-table-cell">Joined</th>
                            <th class="d-none d-lg-table-cell text-end text-nowrap">Last sign-in</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($recent === []): ?>
                          <tr>
                            <td colspan="5" class="text-center text-body-secondary py-6">No accounts yet.</td>
                          </tr>
<?php endif; ?>
<?php foreach ($recent as $row): ?>
<?php
    $role = (string) ($row['role'] ?? 'member');
    $fullName = trim((string) ($row['full_name'] ?? ''));

    /* The member status is the source of truth (pending / active /
     * suspended), not is_active: a pending member can sign in, so
     * is_active alone showed them as "Active" here while the member
     * directory correctly said "Pending" (Phase 9 QA follow-up). */
    $status = (string) ($row['member_status'] ?? '');

    if ($status === '') {
        $status = (int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
    }
?>
                          <tr>
                            <td>
                              <div class="d-flex align-items-center gap-3">
                                <span class="avatar avatar-sm flex-shrink-0">
                                  <span class="avatar-initial rounded-circle bg-label-<?= e($roleTone($role)) ?> fw-semibold">
                                    <?= e(initials((string) ($row['username'] ?? 'U'))) ?>
                                  </span>
                                </span>
                                <div class="min-w-0">
                                  <div class="fw-medium text-truncate"><?= e((string) ($row['username'] ?? '')) ?></div>
                                  <small class="text-body-secondary d-block text-truncate">
                                    <?= e($fullName !== '' ? $fullName : (string) ($row['email'] ?? '')) ?>
                                  </small>
                                </div>
                              </div>
                            </td>
                            <td class="d-none d-md-table-cell"><span class="badge bg-label-<?= e($roleTone($role)) ?>"><?= e(ucfirst($role)) ?></span></td>
                            <td><?= status_badge($status) ?></td>
                            <td class="d-none d-lg-table-cell text-body-secondary text-nowrap"><?= e(date('M j, Y', strtotime((string) ($row['created_at'] ?? 'now')) ?: time())) ?></td>
                            <td class="d-none d-lg-table-cell text-body-secondary text-nowrap text-end"><?= e(time_ago((string) ($row['last_login_at'] ?? ''))) ?></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
