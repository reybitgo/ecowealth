<?php
declare(strict_types=1);

/**
 * Genealogy — the team grouped by level (level 2 and deeper).
 *
 * A live region: a new registration can add a member at any depth, so
 * the whole level grid is replaced while the card header stays static.
 *
 * @var array<int, list<array<string, mixed>>> $deeper
 */
?>
              <div data-live-region="genealogy-team">
<?php if ($deeper === []): ?>
                <p class="text-body-secondary small mb-0">
                  No second-level members yet. Your direct recruits' own recruits will show up here.
                </p>
<?php else: ?>
                <div class="row g-4">
<?php foreach ($deeper as $level => $rows): ?>
                  <div class="col-md-6 col-xl-4">
                    <div class="border rounded-3 p-3 h-100">
                      <div class="team-level-chip">
                        <span class="badge bg-label-primary">Level <?= (int) $level ?></span>
                        <span class="text-body-secondary small"><?= count($rows) ?> member<?= count($rows) === 1 ? '' : 's' ?></span>
                      </div>
                      <ul class="list-unstyled mb-0 small">
<?php foreach ($rows as $row): ?>
                        <li class="d-flex align-items-center justify-content-between gap-2 py-1">
                          <span class="text-truncate">
                            @<?= e((string) $row['username']) ?>
                            <?php if ($row['package_name'] !== null): ?>
                            <span class="text-body-secondary d-block member-meta"><?= e((string) $row['package_name']) ?></span>
                            <?php endif; ?>
                          </span>
                          <?= status_badge((string) $row['member_status']) ?>
                        </li>
<?php endforeach; ?>
                      </ul>
                    </div>
                  </div>
<?php endforeach; ?>
                </div>
<?php endif; ?>
              </div>
