<?php
declare(strict_types=1);

/**
 * S-Login console — the "Recent sessions" card.
 *
 * One live region: the total badge, the rows and the audit footer swap
 * together. The footer link is static data, but it lives inside the card
 * so the region is one coherent block; the client defers the swap while
 * focus is inside it.
 *
 * @var list<array<string, mixed>> $recent  last sessions, newest first
 * @var array{active:int, expired:int, logged_out:int, all:int, overdue:int} $counts
 * @var string $audit_url
 */
?>
                  <div class="card h-100" data-live-region="slogin-recent">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="mb-0">Recent sessions</h5>
                        <p class="text-body-secondary small mb-0">Newest first</p>
                      </div>
                      <span class="badge rounded-pill bg-label-secondary"><?= (int) $counts['all'] ?> total</span>
                    </div>
<?php if ($recent === []): ?>
                    <div class="card-body text-body-secondary">
                      No super-login has been used yet.
                    </div>
<?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col">Member</th>
                            <th scope="col">Started</th>
                            <th scope="col">Status</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($recent as $row): ?>
                          <tr>
                            <td>
                              <span class="fw-medium">@<?= e((string) $row['target_username']) ?></span>
                              <small class="d-block text-body-secondary">by @<?= e((string) $row['superadmin_name']) ?></small>
                            </td>
                            <td class="small text-nowrap"><?= e(time_ago((string) $row['created_at'])) ?></td>
                            <td><?= status_badge((string) $row['status']) ?></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php endif; ?>
                    <div class="card-footer text-end">
                      <a href="<?= e($audit_url) ?>" class="btn btn-sm btn-label-primary">Open the full audit trail</a>
                    </div>
                  </div>
