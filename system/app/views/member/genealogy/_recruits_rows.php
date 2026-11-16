<?php
declare(strict_types=1);

/**
 * Genealogy — the direct-recruit rows.
 *
 * Each row is keyed by member id so the client patches only the recruits
 * that changed; a new recruit appends one row without touching the rest.
 *
 * Phase 20F adds the **Harvest** cell: the member's stored queue position
 * (`#12`) or `—` when they have not qualified. The value is read from one
 * `HarvestQueue::map()` pass in the controller, never computed here — a
 * position is a fact (plan §10 Rule 9).
 *
 * @var list<array<string, mixed>> $direct
 * @var array<int, int>            $harvest_positions user_id => position
 */

$harvest_positions = $harvest_positions ?? [];
?>
                    <tbody data-live-region="genealogy-recruits-rows">
<?php if ($direct === []): ?>
                      <tr>
                        <td colspan="5" class="py-0">
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
<?php $position = $harvest_positions[(int) $row['id']] ?? null; ?>
                      <tr data-live-key="<?= (int) $row['id'] ?>">
                        <td>
                          <span class="fw-medium">@<?= e((string) $row['username']) ?></span>
                          <?php if (trim((string) ($row['full_name'] ?? '')) !== ''): ?>
                          <div class="member-meta"><?= e((string) $row['full_name']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= $row['package_name'] === null ? '<span class="text-body-secondary">—</span>' : e((string) $row['package_name']) ?></td>
                        <td><?= status_badge((string) $row['member_status']) ?></td>
                        <td class="text-nowrap">
<?php if ($position === null): ?>
                          <span class="text-body-secondary">—</span>
<?php else: ?>
                          <span class="badge bg-label-success">#<?= (int) $position ?></span>
<?php endif; ?>
                        </td>
                        <td class="text-nowrap"><span class="member-meta"><?= e(format_datetime((string) $row['created_at'])) ?></span></td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
