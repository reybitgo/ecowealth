<?php
declare(strict_types=1);

/**
 * Genealogy — the direct-recruit rows.
 *
 * Each row is keyed by member id so the client patches only the recruits
 * that changed; a new recruit appends one row without touching the rest.
 *
 * @var list<array<string, mixed>> $direct
 */
?>
                    <tbody data-live-region="genealogy-recruits-rows">
<?php if ($direct === []): ?>
                      <tr>
                        <td colspan="4" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'         => 'bx bx-user-plus',
        'title'        => 'No direct recruits yet',
        'text'         => 'Share your referral link — new signups appear here immediately.',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($direct as $row): ?>
                      <tr data-live-key="<?= (int) $row['id'] ?>">
                        <td>
                          <span class="fw-medium">@<?= e((string) $row['username']) ?></span>
                          <?php if (trim((string) ($row['full_name'] ?? '')) !== ''): ?>
                          <div class="member-meta"><?= e((string) $row['full_name']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= $row['package_name'] === null ? '<span class="text-body-secondary">—</span>' : e((string) $row['package_name']) ?></td>
                        <td><?= status_badge((string) $row['member_status']) ?></td>
                        <td class="text-nowrap"><span class="member-meta"><?= e(format_datetime((string) $row['created_at'])) ?></span></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
