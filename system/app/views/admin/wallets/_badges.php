<?php
declare(strict_types=1);

/**
 * Admin wallet page — role and member-status badges.
 *
 * @var array<string, mixed> $member
 */

$memberStatus = (string) ($member['member_status'] ?? '');

if ($memberStatus === '') {
    $memberStatus = (int) ($member['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
}

$roleTone = match ((string) $member['role']) {
    'superadmin' => 'danger',
    'admin'      => 'info',
    default      => 'secondary',
};
?>
                  <span class="d-inline-flex align-items-center gap-2" data-live-region="wallet-badges">
                    <span class="badge bg-label-<?= e($roleTone) ?>"><?= e(ucfirst((string) $member['role'])) ?></span>
                    <?= status_badge($memberStatus) ?>
                  </span>
