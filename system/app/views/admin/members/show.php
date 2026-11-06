<?php
declare(strict_types=1);

/**
 * Admin member detail (plan §7 Phase 9; reference views/admin/user_view.php).
 *
 * Everything the back office needs about one membership in one place:
 * account, membership, wallet, orders, team and the audit trail.
 *
 * @var array<string, mixed>       $member
 * @var float                      $balance
 * @var float                      $withdrawable
 * @var list<array<string, mixed>> $activations
 * @var array{total:int, open:int, completed:int, spent:float} $orders
 * @var array{count:int, credited:float, this_month:float, last_at:?string} $commissions
 * @var array{direct:int, team:int, levels:int} $team
 * @var list<array<string, mixed>> $upline
 * @var list<array<string, mixed>> $audits
 * @var string                     $statement_url
 */

$id          = (int) $member['id'];
$status      = (string) $member['member_status'];
$isSuspended = $status === 'suspended';
$displayName = trim((string) ($member['full_name'] ?? '')) !== ''
    ? (string) $member['full_name']
    : (string) $member['username'];

$statusLabel = match (true) {
    $status === 'active' && $member['package_id'] !== null => 'Active associate',
    $status === 'active'                                 => 'Standard member',
    $status === 'pending'                                => 'Pending activation',
    $status === 'suspended'                              => 'Suspended',
    default                                              => ucfirst($status),
};

$referralLink = $member['referral_code'] === null
    ? ''
    : url('register?ref=' . rawurlencode((string) $member['referral_code']));
?>
            <div class="container-xxl flex-grow-1 container-p-y">
              <?= flash_render() ?>

              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <a href="<?= e(url('admin/members')) ?>" class="btn btn-sm btn-icon btn-text-secondary" aria-label="Back to members">
                      <i class="icon-base bx bx-chevron-left"></i>
                    </a>
                    <h4 class="mb-0">@<?= e((string) $member['username']) ?></h4>
                    <?= status_badge($status, ['active' => [$statusLabel, 'success']]) ?>
                  </div>
                  <p class="text-body-secondary mb-0">
                    <?= e($displayName) ?>
                    · <?= e((string) $member['email']) ?>
                    · joined <?= e(format_datetime((string) $member['created_at'])) ?>
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= e($statement_url) ?>" class="btn btn-label-primary">
                    <i class="icon-base bx bx-wallet me-1"></i> Wallet statement
                  </a>
                  <form
                    method="POST"
                    action="<?= e(url('admin/members/' . $id . '/status')) ?>"
                    data-confirm
                    data-confirm-title="<?= $isSuspended ? 'Restore this member?' : 'Suspend this member?' ?>"
                    data-confirm-text="<?= $isSuspended
                        ? 'The account can sign in and use the member portal again.'
                        : 'The member is signed out and cannot sign in until restored.' ?>"
                    data-confirm-button="<?= $isSuspended ? 'Restore member' : 'Suspend member' ?>"
                    data-confirm-tone="<?= $isSuspended ? '' : 'danger' ?>"
                  >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $isSuspended ? 'restore' : 'suspend' ?>" />
                    <button type="submit" class="btn btn-<?= $isSuspended ? 'success' : 'danger' ?>">
                      <i class="icon-base bx <?= $isSuspended ? 'bx-play-circle' : 'bx-block' ?> me-1"></i>
                      <?= $isSuspended ? 'Restore member' : 'Suspend member' ?>
                    </button>
                  </form>
                </div>
              </div>

              <div class="row g-4 mb-4">
                <!-- Account -->
                <div class="col-xl-4">
                  <div class="card h-100">
                    <div class="card-header"><h5 class="card-title mb-0">Account</h5></div>
                    <div class="card-body">
                      <div class="d-flex align-items-center gap-3 pb-3 mb-3 border-bottom">
                        <span class="avatar avatar-lg">
                          <span class="avatar-initial rounded-circle bg-label-primary fw-semibold fs-4">
                            <?= e(initials((string) $member['username'])) ?>
                          </span>
                        </span>
                        <div>
                          <h6 class="mb-0"><?= e($displayName) ?></h6>
                          <small class="text-body-secondary">
                            <?= e(ucfirst((string) $member['role'])) ?>
                            · <?= (int) $member['is_active'] === 1 ? 'sign-in enabled' : 'sign-in blocked' ?>
                          </small>
                        </div>
                      </div>
                      <table class="table table-borderless table-sm mb-0">
                        <tbody>
                          <tr>
                            <td class="text-body-secondary ps-0">Member ID</td>
                            <td class="text-end">#<?= $id ?></td>
                          </tr>
                          <tr>
                            <td class="text-body-secondary ps-0">Email</td>
                            <td class="text-end text-truncate"><?= e((string) $member['email']) ?></td>
                          </tr>
                          <tr>
                            <td class="text-body-secondary ps-0">Phone</td>
                            <td class="text-end"><?= e(trim((string) ($member['phone'] ?? '')) !== '' ? (string) $member['phone'] : '—') ?></td>
                          </tr>
                          <tr>
                            <td class="text-body-secondary ps-0">Referral code</td>
                            <td class="text-end"><code><?= e((string) ($member['referral_code'] ?? '—')) ?></code></td>
                          </tr>
                          <tr>
                            <td class="text-body-secondary ps-0">Last sign-in</td>
                            <td class="text-end text-nowrap"><?= e(format_datetime($member['last_login_at'] === null ? null : (string) $member['last_login_at'])) ?></td>
                          </tr>
                        </tbody>
                      </table>
<?php if ($referralLink !== ''): ?>
                      <div class="referral-box mt-3">
                        <input type="text" class="form-control referral-link-input" value="<?= e($referralLink) ?>" readonly onfocus="this.select()" aria-label="Referral link" />
                        <button
                          type="button"
                          class="btn btn-label-primary"
                          data-copy="<?= e($referralLink) ?>"
                          data-copy-label="Copy"
                          aria-label="Copy referral link"
                        ><i class="icon-base bx bx-copy me-1"></i><span>Copy</span></button>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
                </div>

                <!-- Membership -->
                <div class="col-xl-4">
                  <div class="card h-100">
                    <div class="card-header"><h5 class="card-title mb-0">Membership</h5></div>
                    <div class="card-body">
                      <div class="row g-3">
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Status</div>
                            <div class="status-tile-value"><?= e($statusLabel) ?></div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Package</div>
                            <div class="status-tile-value"><?= $member['package_name'] === null ? 'None' : e((string) $member['package_name']) ?></div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Sponsor</div>
                            <div class="status-tile-value">
                              <?= $member['sponsor_username'] === null ? 'Company' : e('@' . (string) $member['sponsor_username']) ?>
                            </div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Joined as</div>
                            <div class="status-tile-value"><?= e(ucfirst((string) $member['reg_payment_method'])) ?></div>
                          </div>
                        </div>
                        <div class="col-12">
                          <div class="status-tile">
                            <div class="status-tile-label">Activated</div>
                            <div class="status-tile-value">
                              <?= e(format_datetime($member['activated_at'] === null ? null : (string) $member['activated_at'])) ?>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="d-flex align-items-center justify-content-between mt-3 small">
                        <span class="text-body-secondary">Team</span>
                        <span>
                          <strong><?= (int) $team['team'] ?></strong> total ·
                          <?= (int) $team['direct'] ?> direct ·
                          <?= (int) $team['levels'] ?> level<?= (int) $team['levels'] === 1 ? '' : 's' ?>
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Wallet -->
                <div class="col-xl-4">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">Wallet</h5>
                      <a href="<?= e($statement_url) ?>" class="btn btn-sm btn-label-primary">Open</a>
                    </div>
                    <div class="card-body">
                      <div class="row g-3">
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Balance</div>
                            <div class="status-tile-value"><?= e(money($balance)) ?></div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Withdrawable</div>
                            <div class="status-tile-value"><?= e(money($withdrawable)) ?></div>
                          </div>
                        </div>
                      </div>

                      <hr class="my-3" />

                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Orders placed</span>
                        <strong><?= (int) $orders['total'] ?></strong>
                      </div>
                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Paid to the shop</span>
                        <strong><?= e(money($orders['spent'])) ?></strong>
                      </div>
                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Commission credited</span>
                        <strong><?= e(money($commissions['credited'])) ?></strong>
                      </div>
                      <div class="d-flex align-items-center justify-content-between small py-1">
                        <span class="text-body-secondary">Activation requests</span>
                        <strong><?= count($activations) ?></strong>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-4">
                <!-- Activations -->
                <div class="col-xl-8">
                  <div class="card h-100">
                    <div class="card-header">
                      <h5 class="card-title mb-0">Activation history</h5>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                          <tr>
                            <th>#</th>
                            <th>Package</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                            <th>Requested</th>
                          </tr>
                        </thead>
                        <tbody>
<?php if ($activations === []): ?>
                          <tr>
                            <td colspan="6" class="text-center text-body-secondary py-5">No activation requests.</td>
                          </tr>
<?php endif; ?>
<?php foreach ($activations as $activation): ?>
                          <tr>
                            <td class="text-body-secondary">#<?= (int) $activation['id'] ?></td>
                            <td class="text-nowrap"><?= e((string) $activation['package_name']) ?></td>
                            <td><?= e(payment_method_label((string) $activation['payment_method'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(money((float) $activation['amount'])) ?></td>
                            <td>
                              <?= status_badge((string) $activation['status']) ?>
<?php if ((string) ($activation['reject_reason'] ?? '') !== ''): ?>
                              <div class="member-meta"><?= e((string) $activation['reject_reason']) ?></div>
<?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                              <span class="member-meta"><?= e(date('M j, Y', (int) strtotime((string) $activation['created_at']))) ?></span>
                              <div class="member-meta"><?= e(date('g:i A', (int) strtotime((string) $activation['created_at']))) ?></div>
                            </td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <!-- Upline + audit -->
                <div class="col-xl-4">
                  <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0">Upline</h5></div>
                    <div class="card-body">
<?php if ($upline === []): ?>
                      <p class="text-body-secondary small mb-0">No sponsor — this account is the top of its chain.</p>
<?php else: ?>
                      <ul class="list-unstyled mb-0 small">
<?php foreach ($upline as $row): ?>
                        <li class="d-flex align-items-center justify-content-between gap-2 py-1 border-bottom">
                          <span>
                            <span class="badge bg-label-secondary me-1">L<?= (int) $row['level'] ?></span>
                            <a href="<?= e(url('admin/members/' . (int) $row['id'])) ?>" class="text-body">
                              @<?= e((string) $row['username']) ?>
                            </a>
                          </span>
                          <span class="text-body-secondary">
                            <?= $row['member']['package_name'] === null ? 'no package' : e((string) $row['member']['package_name']) ?>
                          </span>
                        </li>
<?php endforeach; ?>
                      </ul>
<?php endif; ?>
                    </div>
                  </div>

                  <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Recent audit</h5></div>
                    <ul class="list-group list-group-flush">
<?php if ($audits === []): ?>
                      <li class="list-group-item text-body-secondary small">No audit entries yet.</li>
<?php endif; ?>
<?php foreach ($audits as $entry): ?>
                      <li class="list-group-item">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                          <span class="small fw-medium"><?= e((string) $entry['action']) ?></span>
                          <span class="member-meta text-nowrap"><?= e(format_datetime((string) $entry['created_at'])) ?></span>
                        </div>
                        <div class="member-meta"><?= e((string) ($entry['meta_json'] ?? '')) ?></div>
                      </li>
<?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
