<?php
declare(strict_types=1);

/**
 * Genealogy — the upline chain and the member's own row.
 *
 * A live region: an ancestor's status or package change rewrites their
 * row, and the member's own row follows their status, package and full
 * name. The referral block below the card stays static.
 *
 * @var array<string, mixed>       $member
 * @var list<array<string, mixed>> $upline       SponsorService::chain()
 * @var string                     $display_name
 */

$display_name = $display_name ?? (
    trim((string) ($member['full_name'] ?? '')) !== ''
        ? (string) $member['full_name']
        : (string) $member['username']
);
?>
              <div data-live-region="genealogy-upline">
<?php if ($upline === []): ?>
                <div class="alert alert-secondary py-3 mb-0 small">
                  This account is the top of its chain — it has no sponsor.
                </div>
<?php else: ?>
                <div class="genealogy-upline">
<?php foreach ($upline as $row): ?>
<?php $sponsor = $row['member']; ?>
                  <div class="upline-row">
                    <span class="upline-level">L<?= (int) $row['level'] ?></span>
                    <div class="flex-grow-1 min-w-0">
                      <div class="fw-medium text-truncate">
                        <?= e((string) $sponsor['username']) ?>
                        <span class="text-body-secondary small">
                          <?= $sponsor['package_name'] === null ? '· no package' : '· ' . e((string) $sponsor['package_name']) ?>
                        </span>
                      </div>
                      <div class="member-meta">
                        <?= e(ucfirst(str_replace('_', ' ', (string) $sponsor['member_status']))) ?>
                        · joined <?= e(format_datetime((string) $sponsor['created_at'])) ?>
                      </div>
                    </div>
                    <?= status_badge((string) $sponsor['member_status']) ?>
                  </div>
<?php endforeach; ?>
                  <div class="upline-row is-self">
                    <span class="upline-level"><i class="icon-base bx bx-user"></i></span>
                    <div class="flex-grow-1 min-w-0">
                      <div class="fw-medium text-truncate">
                        <?= e($display_name) ?> <span class="text-body-secondary small">· you</span>
                      </div>
                      <div class="member-meta">
                        <?= $member['package_name'] === null ? 'No package' : e((string) $member['package_name']) ?>
                      </div>
                    </div>
                    <?= status_badge((string) $member['member_status']) ?>
                  </div>
                </div>
<?php endif; ?>
              </div>
