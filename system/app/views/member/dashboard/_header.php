<?php
declare(strict_types=1);

/**
 * Member dashboard — the header status pill and its shortcuts.
 *
 * A live region: a status change, an activation approval or activation
 * rejection bumps the per-member dashboard surface, so the pill matches
 * the membership card below without a reload. The greeting and the
 * referral card stay static.
 *
 * @var array<string, mixed> $member
 */

$status     = (string) ($member['member_status'] ?? '');
$hasPackage = ($member['package_id'] ?? null) !== null;

$statusTone = match ($status) {
    'active'    => 'success',
    'pending'   => 'warning',
    'suspended' => 'danger',
    default     => 'secondary',
};

$statusLabel = match (true) {
    $status === 'active' && $hasPackage => 'Active associate',
    $status === 'active'                => 'Standard member',
    $status === 'pending'               => 'Pending activation',
    $status === 'suspended'             => 'Suspended',
    default                             => ucfirst($status),
};
?>
                <div class="d-flex align-items-center gap-2 flex-wrap" data-live-region="dashboard-header">
                  <?= status_badge($status, [
                      'active'    => [$statusLabel, $statusTone],
                      'pending'   => ['Pending activation', $statusTone],
                      'suspended' => ['Suspended', $statusTone],
                  ]) ?>
                  <a href="<?= e(url('shop')) ?>" class="btn btn-sm btn-primary">
                    <i class="icon-base bx bx-store me-1"></i> Shop
                  </a>
                </div>
