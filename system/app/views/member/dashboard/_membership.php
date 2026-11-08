<?php
declare(strict_types=1);

/**
 * Member dashboard — the membership card.
 *
 * A live region: an activation request, an approval or rejection, a status
 * change and a package assignment all bump the per-member dashboard
 * surface, so the card body (package, alerts and tiles) re-renders in
 * place. The column wrapper stays in the page.
 *
 * @var array<string, mixed>      $member
 * @var array<string, mixed>|null $open_activation
 * @var int                       $activation_count
 */

$status          = (string) ($member['member_status'] ?? '');
$hasPackage      = ($member['package_id'] ?? null) !== null;
$openActivation  = $open_activation ?? null;
$activationCount = (int) ($activation_count ?? 0);
?>
                  <div class="card h-100" data-live-region="dashboard-membership">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title mb-0">Membership</h5>
                      <a href="<?= e(url('membership')) ?>" class="btn btn-sm btn-label-primary">Manage</a>
                    </div>
                    <div class="card-body">
<?php if ($hasPackage): ?>
                      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-3 border-bottom">
                        <div>
                          <h5 class="mb-0"><?= e((string) $member['package_name']) ?></h5>
                          <small class="text-body-secondary">Associate package</small>
                        </div>
                        <span class="badge bg-label-success">Active</span>
                      </div>
<?php elseif ($status === 'pending'): ?>
                      <div class="alert alert-warning d-flex align-items-start gap-2 py-3" role="alert">
                        <i class="icon-base bx bx-time-five fs-5"></i>
                        <div class="small">
                          Your account is waiting for activation.
<?php if ($openActivation !== null): ?>
                          A <strong><?= e(str_replace('_', ' ', (string) $openActivation['status'])) ?></strong>
                          request for <?= e((string) $openActivation['package_name']) ?> is with the review team.
<?php else: ?>
                          Choose a package, redeem a code or upload a payment proof on the membership page.
<?php endif; ?>
                        </div>
                      </div>
<?php elseif ($status === 'suspended'): ?>
                      <div class="alert alert-danger d-flex align-items-start gap-2 py-3" role="alert">
                        <i class="icon-base bx bx-block fs-5"></i>
                        <div class="small">This account is suspended. Contact support to restore it.</div>
                      </div>
<?php else: ?>
                      <div class="alert alert-info d-flex align-items-start gap-2 py-3" role="alert">
                        <i class="icon-base bx bx-store fs-5"></i>
                        <div class="small">
                          You have a standard membership: shopping is open, and no package is attached.
                          Contact support to add an associate package.
                        </div>
                      </div>
<?php endif; ?>

                      <div class="row g-3">
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Member since</div>
                            <div class="status-tile-value"><?= e(format_datetime((string) $member['created_at'])) ?></div>
                          </div>
                        </div>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Activated</div>
                            <div class="status-tile-value"><?= e(format_datetime($member['activated_at'] === null ? null : (string) $member['activated_at'])) ?></div>
                          </div>
                        </div>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Sponsor</div>
                            <div class="status-tile-value">
                              <?= $member['sponsor_username'] === null ? '—' : e('@' . (string) $member['sponsor_username']) ?>
                            </div>
                          </div>
                        </div>
                        <div class="col-sm-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Activation requests</div>
                            <div class="status-tile-value"><?= $activationCount ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
