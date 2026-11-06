<?php
declare(strict_types=1);

/**
 * Admin member directory (plan §7 Phase 9; reference views/admin/users.php).
 *
 * @var array{total:int, active:int, pending:int, suspended:int, with_package:int, new_week:int} $stats
 * @var array{q:string, status:string, package:string} $filters
 * @var Paginator                  $pager
 * @var list<array<string, mixed>> $members
 * @var list<array{id:int, name:string, members:int}> $packages
 */

$query      = (string) $filters['q'];
$status     = (string) $filters['status'];
$package    = (string) $filters['package'];
$hasFilters = $query !== '' || $status !== '' || $package !== '';

$cards = [
    ['label' => 'Members', 'value' => (string) $stats['total'], 'icon' => 'bx-group', 'tone' => 'primary'],
    ['label' => 'Active', 'value' => (string) $stats['active'], 'icon' => 'bx-check-circle', 'tone' => 'success'],
    ['label' => 'Pending', 'value' => (string) $stats['pending'], 'icon' => 'bx-time-five', 'tone' => 'warning'],
    ['label' => 'Suspended', 'value' => (string) $stats['suspended'], 'icon' => 'bx-block', 'tone' => 'danger'],
    ['label' => 'With a package', 'value' => (string) $stats['with_package'], 'icon' => 'bx-medal', 'tone' => 'info'],
];
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                  <h4 class="mb-1">Members</h4>
                  <p class="text-body-secondary mb-0">
                    Sponsor chain, package, status and wallet for every member account.
                  </p>
                </div>
                <span class="badge rounded-pill bg-label-primary"><?= (int) $stats['new_week'] ?> joined this week</span>
              </div>

              <div class="row g-4 mb-4">
<?php foreach ($cards as $card): ?>
                <div class="col-6 col-xl">
                  <div class="card h-100">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded bg-label-<?= e($card['tone']) ?>">
                            <i class="icon-base <?= e(icon_class($card['icon'])) ?>"></i>
                          </span>
                        </span>
                      </div>
                      <p class="text-body-secondary mb-1 small"><?= e($card['label']) ?></p>
                      <h5 class="mb-0"><?= e($card['value']) ?></h5>
                    </div>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <div class="card">
                <div class="card-header">
                  <h5 class="card-title mb-0">Member directory</h5>
                  <small class="text-body-secondary">Search by member, sponsor or referral code.</small>
                </div>
                <div class="card-body py-3 border-top">
                  <form method="GET" action="<?= e(url('admin/members')) ?>" class="membership-filters">
                    <input
                      type="search"
                      class="form-control"
                      name="q"
                      value="<?= e($query) ?>"
                      placeholder="Username, name, email, sponsor or code…"
                      aria-label="Search members"
                    />
                    <select class="form-select" name="status" aria-label="Filter by status">
                      <option value="">All statuses</option>
<?php foreach (['active', 'pending', 'inactive', 'suspended'] as $option): ?>
                      <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
<?php endforeach; ?>
                    </select>
                    <select class="form-select" name="package" aria-label="Filter by package">
                      <option value="">All packages</option>
                      <option value="none" <?= $package === 'none' ? 'selected' : '' ?>>No package</option>
<?php foreach ($packages as $row): ?>
                      <option value="<?= (int) $row['id'] ?>" <?= $package === (string) $row['id'] ? 'selected' : '' ?>>
                        <?= e($row['name']) ?> (<?= (int) $row['members'] ?>)
                      </option>
<?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-label-primary">Filter</button>
<?php if ($hasFilters): ?>
                    <a href="<?= e(url('admin/members')) ?>" class="btn btn-label-secondary">Clear</a>
<?php endif; ?>
                  </form>
                </div>

                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Member</th>
                        <th>Package</th>
                        <th>Sponsor</th>
                        <th>Status</th>
                        <th class="text-end">Wallet</th>
                        <th>Joined</th>
                        <th class="text-end">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
<?php if ($members === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-group',
        'title'        => $hasFilters ? 'No members match these filters' : 'No members yet',
        'text'         => $hasFilters
            ? 'Try a different search, status or package.'
            : 'Members appear here as soon as they register.',
        'action_href'  => $hasFilters ? 'admin/members' : '',
        'action_label' => $hasFilters ? 'Clear filters' : '',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($members as $row): ?>
<?php
    $id       = (int) $row['id'];
    $rowStatus = (string) $row['member_status'];
    $isSuspended = $rowStatus === 'suspended';
?>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <span class="member-row-avatar"><?= e(initials((string) $row['username'])) ?></span>
                            <div class="min-w-0">
                              <a href="<?= e(url('admin/members/' . $id)) ?>" class="text-body fw-medium">
                                @<?= e((string) $row['username']) ?>
                              </a>
                              <div class="member-meta text-truncate">
                                <?= e(trim((string) ($row['full_name'] ?? '')) !== '' ? (string) $row['full_name'] : (string) $row['email']) ?>
                              </div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <?= $row['package_name'] === null
                              ? '<span class="text-body-secondary">—</span>'
                              : e((string) $row['package_name']) ?>
                        </td>
                        <td>
                          <?= $row['sponsor_username'] === null
                              ? '<span class="text-body-secondary">company</span>'
                              : e('@' . (string) $row['sponsor_username']) ?>
                        </td>
                        <td><?= status_badge($rowStatus) ?></td>
                        <td class="text-end member-wallet-cell">
                          <?= e(money((float) $row['ewallet_balance'])) ?>
                          <small><?= e(money((float) $row['withdrawable_balance'])) ?> withdrawable</small>
                        </td>
                        <td class="text-nowrap">
                          <span class="member-meta"><?= e(format_datetime((string) $row['created_at'])) ?></span>
                        </td>
                        <td class="text-end">
                          <div class="d-inline-flex align-items-center gap-1">
                            <a
                              href="<?= e(url('admin/members/' . $id)) ?>"
                              class="btn btn-sm btn-icon btn-label-primary"
                              title="View member"
                              aria-label="View @<?= e((string) $row['username']) ?>"
                            ><i class="icon-base bx bx-show"></i></a>

                            <form
                              method="POST"
                              action="<?= e(url('admin/members/' . $id . '/status')) ?>"
                              class="d-inline"
                              data-confirm
                              data-confirm-title="<?= $isSuspended ? 'Restore this member?' : 'Suspend this member?' ?>"
                              data-confirm-text="<?= $isSuspended
                                  ? 'The account can sign in and use the member portal again.'
                                  : 'The member is signed out and cannot sign in until restored.' ?>"
                              data-confirm-button="<?= $isSuspended ? 'Restore' : 'Suspend' ?>"
                              data-confirm-tone="<?= $isSuspended ? '' : 'danger' ?>"
                            >
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="<?= $isSuspended ? 'restore' : 'suspend' ?>" />
                              <button
                                type="submit"
                                class="btn btn-sm btn-icon btn-label-<?= $isSuspended ? 'success' : 'danger' ?>"
                                title="<?= $isSuspended ? 'Restore' : 'Suspend' ?>"
                                aria-label="<?= $isSuspended ? 'Restore' : 'Suspend' ?> @<?= e((string) $row['username']) ?>"
                              ><i class="icon-base bx <?= $isSuspended ? 'bx-play-circle' : 'bx-block' ?>"></i></button>
                            </form>
                          </div>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card-body pt-2">
<?= View::partial('pagination', ['pager' => $pager, 'pager_label' => 'Members']) ?>
                </div>
              </div>
            </div>
