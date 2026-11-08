<?php
declare(strict_types=1);

/**
 * Membership page — current membership card.
 *
 * @var array<string, mixed> $member
 * @var string $display_name
 * @var bool   $has_package
 */
?>
              <div class="card member-hero mb-4" data-live-region="membership-current">
                <div class="card-body">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-6">
                      <div class="d-flex align-items-center gap-3">
                        <div class="avatar avatar-lg">
                          <span class="avatar-initial rounded-circle bg-label-primary fw-semibold fs-4">
                            <?= e(initials((string) $member['username'])) ?>
                          </span>
                        </div>
                        <div>
                          <h5 class="mb-1"><?= e($display_name) ?></h5>
                          <div class="text-body-secondary small">
                            @<?= e((string) $member['username']) ?>
                            · joined <?= e(format_datetime((string) $member['created_at'])) ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="col-lg-6">
                      <div class="row g-3">
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Package</div>
                            <div class="status-tile-value">
                              <?= $has_package ? e((string) $member['package_name']) : 'None' ?>
                            </div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Activated</div>
                            <div class="status-tile-value">
                              <?= e(format_datetime($member['activated_at'] === null ? null : (string) $member['activated_at'])) ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
