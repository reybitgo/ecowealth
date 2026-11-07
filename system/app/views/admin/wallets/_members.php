<?php
declare(strict_types=1);

/**
 * Admin wallet monitor — member directory rows.
 *
 * Each row is keyed by user id so the client patches only the wallets that
 * changed; the links carry data-live-focus so focus survives a replacement.
 *
 * @var list<array<string, mixed>> $members
 */
?>
                    <tbody data-live-region="wallet-members">
<?php if ($members === []): ?>
                      <tr>
                        <td colspan="7" class="py-0">
<?php
    echo View::partial('empty_state', [
        'icon'  => 'bx bx-group',
        'title' => 'No members match these filters',
        'text'  => 'Try a different search, or clear the filters to list every wallet.',
    ]);
?>
                        </td>
                      </tr>
<?php endif; ?>
<?php foreach ($members as $member): ?>
<?php
    $memberId     = (int) $member['id'];
    $memberTotal  = round((float) $member['ewallet_balance'], 2);
    $memberWith   = round((float) $member['withdrawable_balance'], 2);
    $memberLocked = round($memberTotal - $memberWith, 2);

    /* Status, not role: the wallet detail page already shows the role,
     * and the monitor needs to distinguish pending from active members. */
    $memberStatus = (string) ($member['member_status'] ?? '');

    if ($memberStatus === '') {
        $memberStatus = (int) ($member['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
    }
?>
                      <tr data-live-key="<?= $memberId ?>">
                        <td>
                          <div class="d-flex align-items-center gap-3">
                            <span class="avatar avatar-sm">
                              <span class="avatar-initial rounded-circle bg-label-primary">
                                <?= e(initials((string) $member['username'])) ?>
                              </span>
                            </span>
                            <div class="min-w-0">
                              <a
                                href="<?= e(url('admin/wallets/' . $memberId)) ?>"
                                class="text-body fw-medium"
                                data-live-focus="member"
                              >
                                <?= e((string) $member['username']) ?>
                              </a>
                              <div class="small text-body-secondary">
                                <?= e(trim((string) ($member['full_name'] ?? '')) !== '' ? (string) $member['full_name'] : (string) $member['email']) ?>
                              </div>
                            </div>
                          </div>
                        </td>
                        <td><?= status_badge($memberStatus) ?></td>
                        <td class="text-end text-nowrap fw-semibold"><?= e(money($memberTotal)) ?></td>
                        <td class="text-end text-nowrap"><?= e(money($memberWith)) ?></td>
                        <td class="text-end text-nowrap text-body-secondary"><?= e(money($memberLocked)) ?></td>
                        <td class="text-nowrap small text-body-secondary">
                          <?= e($member['last_movement_at'] === null ? '—' : time_ago((string) $member['last_movement_at'])) ?>
                        </td>
                        <td class="text-end">
                          <a
                            href="<?= e(url('admin/wallets/' . $memberId)) ?>"
                            class="btn btn-sm btn-label-primary"
                            data-live-focus="open"
                          >
                            <i class="icon-base bx bx-wallet me-1"></i> Open
                          </a>
                        </td>
                      </tr>
<?php endforeach; ?>
                    </tbody>
