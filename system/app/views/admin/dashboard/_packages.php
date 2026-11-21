<?php
declare(strict_types=1);

/**
 * Admin dashboard package list card.
 *
 * @var list<array<string, mixed>> $packages
 */

$packages = $packages ?? [];
?>
                <div class="col-xl-4" data-live-region="dashboard-packages">
                  <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                      <div>
                        <h5 class="card-title mb-1">Packages</h5>
                        <p class="text-body-secondary small mb-0">Entry packages members can activate.</p>
                      </div>
                      <a href="<?= e(url('admin/packages')) ?>" class="btn btn-sm btn-label-primary">Manage</a>
                    </div>
                    <div class="card-body pt-2">
<?php if ($packages === []): ?>
                      <div class="text-center text-body-secondary py-6">
                        <i class="icon-base bx bx-box icon-lg d-block mb-2"></i>
                        <p class="mb-3">No packages yet.</p>
                        <a href="<?= e(url('admin/packages/new')) ?>" class="btn btn-sm btn-primary">Create the first package</a>
                      </div>
<?php else: ?>
<?php $lastPackage = count($packages) - 1; ?>
<?php foreach ($packages as $index => $package): ?>
                      <div class="d-flex align-items-center gap-3 py-3 <?= $index === $lastPackage ? '' : 'border-bottom' ?>">
                        <span class="avatar avatar-sm flex-shrink-0">
                          <span class="avatar-initial rounded bg-label-primary">
                            <i class="icon-base bx bx-box"></i>
                          </span>
                        </span>
                        <div class="flex-grow-1 min-w-0">
                          <h6 class="mb-0 text-truncate"><?= e((string) $package['name']) ?></h6>
                          <small class="text-body-secondary">
                            Direct referral <?= e(money((float) ($package['direct_referral_bonus'] ?? 0))) ?>
                          </small>
                        </div>
                        <div class="text-end flex-shrink-0">
                          <div class="fw-semibold"><?= e(money((float) ($package['price'] ?? 0))) ?></div>
                          <?= status_badge((string) ($package['status'] ?? 'inactive')) ?>
                        </div>
                        <a
                          href="<?= e(url('admin/packages/' . (int) $package['id'] . '/edit')) ?>"
                          class="btn btn-icon btn-sm btn-label-secondary flex-shrink-0"
                          aria-label="Edit <?= e((string) $package['name']) ?>"
                        >
                          <i class="icon-base bx bx-edit"></i>
                        </a>
                      </div>
<?php endforeach; ?>
<?php endif; ?>
                    </div>
                  </div>
                </div>
