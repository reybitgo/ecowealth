<?php
declare(strict_types=1);

/**
 * Admin wallet page — member identity.
 *
 * @var array<string, mixed> $member
 */
?>
                  <div data-live-region="wallet-heading">
                    <h4 class="mb-1">@<?= e((string) $member['username']) ?></h4>
                    <p class="text-body-secondary mb-0">
                      <?= e(trim((string) ($member['full_name'] ?? '')) !== '' ? (string) $member['full_name'] : (string) $member['email']) ?>
                      · <?= e((string) $member['email']) ?>
                    </p>
                  </div>
