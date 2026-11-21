<?php
declare(strict_types=1);

/**
 * Admin order page — the member who placed the order.
 *
 * Read-only: wallet balances refresh with the order surface.
 *
 * @var array<string, mixed> $ctx
 */

$orderId = (int) $ctx['order']['id'];
$member  = $ctx['member'];
?>
                  <div class="card mb-6" data-live-region="order-member">
                    <div class="card-header"><h5 class="mb-0">Member</h5></div>
                    <div class="card-body">
<?php if ($member === null): ?>
                      <p class="text-body-secondary small mb-0">The account could not be loaded.</p>
<?php else: ?>
                      <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="avatar avatar-sm">
                          <span class="avatar-initial rounded-circle bg-label-primary"><?= e(initials((string) $member['username'])) ?></span>
                        </span>
                        <div class="min-w-0">
                          <a href="<?= e(url('admin/wallets/' . (int) $member['id'])) ?>" class="fw-semibold text-body">
                            <?= e((string) $member['username']) ?>
                          </a>
                          <div class="small text-body-secondary"><?= e(trim((string) ($member['full_name'] ?? '')) !== '' ? (string) $member['full_name'] : (string) $member['email']) ?></div>
                        </div>
                      </div>
                      <div class="d-flex justify-content-between py-1 small">
                        <span class="text-body-secondary">E-wallet</span>
                        <span class="fw-medium"><?= e(money((float) $member['ewallet_balance'])) ?></span>
                      </div>
                      <div class="d-flex justify-content-between py-1 small">
                        <span class="text-body-secondary">Withdrawable</span>
                        <span><?= e(money((float) $member['withdrawable_balance'])) ?></span>
                      </div>
                      <a href="<?= e(url('admin/wallets/' . (int) $member['id'])) ?>" class="btn btn-sm btn-label-primary w-100 mt-3">
                        <i class="icon-base bx bx-wallet me-1"></i> Open wallet
                      </a>
<?php endif; ?>
                    </div>
                  </div>
