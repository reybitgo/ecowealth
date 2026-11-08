<?php
declare(strict_types=1);

/**
 * Admin member page — account card.
 *
 * @var array<string, mixed> $member
 * @var string $display_name
 * @var string $referral_link
 */

$id = (int) $member['id'];
?>
                  <div class="card h-100" data-live-region="member-account">
                    <div class="card-header"><h5 class="card-title mb-0">Account</h5></div>
                    <div class="card-body">
                      <div class="d-flex align-items-center gap-3 pb-3 mb-3 border-bottom">
                        <span class="avatar avatar-lg">
                          <span class="avatar-initial rounded-circle bg-label-primary fw-semibold fs-4">
                            <?= e(initials((string) $member['username'])) ?>
                          </span>
                        </span>
                        <div>
                          <h6 class="mb-0"><?= e($display_name) ?></h6>
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
<?php if ($referral_link !== ''): ?>
                      <div class="referral-box mt-3">
                        <input type="text" class="form-control referral-link-input" value="<?= e($referral_link) ?>" readonly onfocus="this.select()" aria-label="Referral link" />
                        <button
                          type="button"
                          class="btn btn-icon btn-text-secondary"
                          data-copy="<?= e($referral_link) ?>"
                          data-copy-tooltip="Copy link"
                          data-bs-toggle="tooltip"
                          title="Copy link"
                          aria-label="Copy referral link"
                        ><i class="icon-base bx bx-copy"></i></button>
                      </div>
<?php endif; ?>
                    </div>
                  </div>
