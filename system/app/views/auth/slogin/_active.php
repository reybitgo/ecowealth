<?php
declare(strict_types=1);

/**
 * S-Login console — the "Active sessions" card.
 *
 * One live region: the header's count badge and the list swap together, so
 * the badge and the rows can never disagree. The empty state is inside the
 * region, so the first session of the day swaps the message for the table
 * in the same card.
 *
 * @var list<array<string, mixed>> $active  live sessions (inside TTL)
 * @var array{active:int, expired:int, logged_out:int, all:int, overdue:int} $counts
 */
?>
                  <div class="card h-100" data-live-region="slogin-active">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="mb-0">Active sessions</h5>
                        <p class="text-body-secondary small mb-0">Live, not-yet-expired tabs</p>
                      </div>
                      <span class="badge rounded-pill bg-label-success"><?= (int) $counts['active'] ?></span>
                    </div>
<?php if ($active === []): ?>
                    <div class="card-body text-body-secondary">
                      No member session is open right now.
                    </div>
<?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th scope="col">Member</th>
                            <th scope="col">Opened by</th>
                            <th scope="col">Started</th>
                            <th scope="col">Ends</th>
                          </tr>
                        </thead>
                        <tbody>
<?php foreach ($active as $row): ?>
                          <tr>
                            <td><span class="fw-medium">@<?= e((string) $row['target_username']) ?></span></td>
                            <td class="small">@<?= e((string) $row['superadmin_name']) ?></td>
                            <td class="small text-nowrap"><?= e(time_ago((string) $row['created_at'])) ?></td>
                            <td class="small text-nowrap"><?= e(format_datetime((string) $row['expires_at'])) ?></td>
                          </tr>
<?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
<?php endif; ?>
                  </div>
