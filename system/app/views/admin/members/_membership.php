<?php
declare(strict_types=1);

/**
 * Admin member page — membership card.
 *
 * @var array<string, mixed> $member
 * @var string $status_label
 * @var array{direct:int, team:int, levels:int} $team
 */
?>
                  <div class="card h-100" data-live-region="member-membership">
                    <div class="card-header"><h5 class="card-title mb-0">Membership</h5></div>
                    <div class="card-body">
                      <div class="row g-3">
                        <div class="col-6">
                          <div class="status-tile">
                            <div class="status-tile-label">Status</div>
                            <div class="status-tile-value"><?= e($status_label) ?></div>
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
