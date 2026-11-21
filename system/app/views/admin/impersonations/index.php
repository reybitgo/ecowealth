<?php
declare(strict_types=1);

/**
 * S-Login audit trail (plan §7 Phase 14, Appendix A
 * GET /admin/impersonations).
 *
 * Every superadmin impersonation, its target, origin and terminal
 * status. The raw URL token is never shown — only its hash is stored,
 * so there is nothing here that could re-open a session.
 *
 * @var array{status:string, q:string, from:string, to:string} $filters
 * @var list<array<string, mixed>> $sessions
 * @var Paginator $pager
 * @var array{active:int, expired:int, logged_out:int, all:int, overdue:int} $counts
 * @var int $swept  sessions marked expired by this page load
 * @var array{sessions:int, active:int, expired:int, logged_out:int, superadmins:int, targets:int, today:int} $totals
 * @var string $console_url
 * @var string $audit_url
 */

$tabs = [
    ''           => ['label' => 'All sessions', 'count' => $counts['all']],
    'active'     => ['label' => 'Active',       'count' => $counts['active']],
    'expired'    => ['label' => 'Expired',      'count' => $counts['expired']],
    'logged_out' => ['label' => 'Logged out',   'count' => $counts['logged_out']],
];

$tabUrl = static function (string $status) use ($audit_url): string {
    return $status === '' ? $audit_url : $audit_url . '?status=' . rawurlencode($status);
};
?>
            <div class="container-xxl flex-grow-1 container-p-y sl-audit-page">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-6">
                <div>
                  <h4 class="mb-1">Super-login audit</h4>
                  <p class="text-body-secondary mb-0">
                    Who opened whose member portal, from where, and how the session ended.
                    The URL token is stored only as a SHA-256 hash and is never shown.
                  </p>
                </div>
                <a href="<?= e($console_url) ?>" class="btn btn-label-primary">
                  <i class="icon-base bx bx-shield-quarter me-1"></i> S-Login console
                </a>
              </div>

              <div class="row g-6 mb-6">
                <div class="col-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-body-secondary small">Sessions today</span>
                        <i class="icon-base bx bx-calendar-check fs-4 text-primary"></i>
                      </div>
                      <h4 class="mb-0"><?= (int) $totals['today'] ?></h4>
                      <small class="text-body-secondary"><?= (int) $totals['sessions'] ?> in total</small>
                    </div>
                  </div>
                </div>
                <div class="col-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-body-secondary small">Active now</span>
                        <i class="icon-base bx bx-radio-circle-marked fs-4 text-success"></i>
                      </div>
                      <h4 class="mb-0"><?= (int) $counts['active'] ?></h4>
                      <small class="text-body-secondary"><?= (int) $totals['superadmins'] ?> superadmin<?= (int) $totals['superadmins'] === 1 ? '' : 's' ?> · <?= (int) $totals['targets'] ?> member<?= (int) $totals['targets'] === 1 ? '' : 's' ?></small>
                    </div>
                  </div>
                </div>
                <div class="col-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-body-secondary small">Expired</span>
                        <i class="icon-base bx bx-time-five fs-4 text-warning"></i>
                      </div>
                      <h4 class="mb-0"><?= (int) $counts['expired'] ?></h4>
                      <small class="text-body-secondary">TTL, origin change or account change</small>
                    </div>
                  </div>
                </div>
                <div class="col-6 col-xl-3">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-body-secondary small">Logged out</span>
                        <i class="icon-base bx bx-log-out-circle fs-4 text-secondary"></i>
                      </div>
                      <h4 class="mb-0"><?= (int) $counts['logged_out'] ?></h4>
                      <small class="text-body-secondary">Ended with Exit / Sign out</small>
                    </div>
                  </div>
                </div>
              </div>

<?php if ((int) ($swept ?? 0) > 0): ?>
              <div class="alert alert-info d-flex align-items-start gap-2 mb-6" role="status">
                <i class="icon-base bx bx-info-circle fs-4"></i>
                <div>
                  <strong><?= (int) $swept ?> session<?= (int) $swept === 1 ? '' : 's' ?> passed their TTL</strong>
                  while no request arrived; they were marked expired when this page opened.
                </div>
              </div>
<?php endif; ?>

              <div class="card">
                <div class="card-header border-bottom pb-0">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <ul class="nav nav-tabs card-header-tabs mb-0" role="tablist">
<?php foreach ($tabs as $status => $tab): ?>
                      <li class="nav-item" role="presentation">
                        <a
                          class="nav-link <?= $filters['status'] === $status ? 'active' : '' ?>"
                          href="<?= e($tabUrl($status)) ?>"
<?= $filters['status'] === $status ? ' aria-selected="true"' : '' ?>
                        >
                          <?= e($tab['label']) ?>
                          <span class="badge rounded-pill bg-label-secondary ms-1"><?= (int) $tab['count'] ?></span>
                        </a>
                      </li>
<?php endforeach; ?>
                    </ul>
                    <form method="GET" action="<?= e($audit_url) ?>" class="d-flex flex-wrap gap-2 py-3">
                      <input type="hidden" name="status" value="<?= e($filters['status']) ?>" />
                      <?= per_page_hidden() ?>
                      <input
                        type="search"
                        name="q"
                        class="form-control"
                        value="<?= e($filters['q']) ?>"
                        placeholder="Superadmin, member, IP or device…"
                        aria-label="Search the S-Login audit"
                      />
                      <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>" aria-label="From date" />
                      <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>" aria-label="To date" />
                      <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== ''): ?>
                      <a href="<?= e($tabUrl($filters['status'])) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                    </form>
                  </div>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 sl-audit-table">
                    <thead>
                      <tr>
                        <th scope="col">Started</th>
                        <th scope="col">Superadmin</th>
                        <th scope="col">Member</th>
                        <th scope="col">IP</th>
                        <th scope="col">Device</th>
                        <th scope="col">Expires</th>
                        <th scope="col">Status</th>
                      </tr>
                    </thead>
                    <tbody>
<?php if ($sessions === []): ?>
                      <tr>
                        <td colspan="7" class="text-center text-body-secondary py-6">
                          No session matches these filters.
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($sessions as $row): ?>
                      <tr>
                        <td class="text-nowrap">
                          <span data-bs-toggle="tooltip" title="<?= e((string) $row['created_at']) ?>">
                            <?= e(format_datetime((string) $row['created_at'])) ?>
                          </span>
                          <small class="d-block text-body-secondary"><?= e(time_ago((string) $row['created_at'])) ?></small>
                        </td>
                        <td>
                          <span class="fw-medium">@<?= e((string) $row['superadmin_name']) ?></span>
                          <small class="d-block text-body-secondary">#<?= (int) $row['superadmin_id'] ?></small>
                        </td>
                        <td>
                          <span class="fw-medium">@<?= e((string) $row['target_username']) ?></span>
                          <small class="d-block text-body-secondary">#<?= (int) $row['target_user_id'] ?></small>
                        </td>
                        <td class="text-nowrap"><code><?= e((string) $row['ip']) ?></code></td>
                        <td>
                          <span data-bs-toggle="tooltip" title="<?= e((string) $row['user_agent']) ?>">
                            <?= e(user_agent_label((string) $row['user_agent'])) ?>
                          </span>
                        </td>
                        <td class="text-nowrap small"><?= e(format_datetime((string) $row['expires_at'])) ?></td>
                        <td><?= status_badge((string) $row['status']) ?></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>

<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Sessions', 'form_action' => url('admin/impersonations')]) ?>
            </div>
