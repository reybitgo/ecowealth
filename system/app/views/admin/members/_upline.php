<?php
declare(strict_types=1);

/**
 * Admin member page — sponsor chain card.
 *
 * @var list<array<string, mixed>> $upline
 */
?>
                  <div class="card mb-4" data-live-region="member-upline">
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
