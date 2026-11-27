<?php
declare(strict_types=1);

/**
 * Binary tree — per-node actions menu (baseline, no-JS version).
 *
 * Phase 22G-S2: links only. Phase 22G-S4 adds the in-node registration
 * modal: each "+" carries data-binary-modal-trigger plus
 * data-binary-upline / data-binary-side so the modal script locks the
 * placement. A slot-specific invite link (/register?ref=&upline=&side=)
 * is copied to the clipboard by the modal script.
 *
 * @var array<string, mixed> $node      one node card
 * @var array<string, mixed> $slots     side => ['free' => bool, 'user' => int|null]
 * @var int                  $viewer_id viewer's user id
 * @var bool                 $is_admin
 * @var bool                 $can_register
 */

$node   = (array) $node;
$slots  = (array) ($node['slots'] ?? []);
$userId = (int) ($node['id'] ?? 0);
$isYou  = (bool) ($node['is_you'] ?? false);
$username = (string) ($node['username'] ?? '');
$referral = (string) ($node['referral_code'] ?? '');
?>
<div class="binary-tree-actions d-flex align-items-center gap-1">
    <?php foreach (['left', 'right'] as $side): ?>
    <?php
        $slot = isset($slots[$side]) ? (array) $slots[$side] : ['free' => true, 'user' => null];
        $free = (bool) ($slot['free'] ?? true);
    ?>
    <?php if ($free): ?>
    <a
        href="<?= url('register?sponsor=' . rawurlencode($username) . '&binary_position=' . $side . '&return=genealogy') ?>"
        class="btn btn-sm btn-label-secondary binary-tree-slot-add"
        role="button"
        data-binary-modal-trigger
        data-binary-upline="<?= e($username) ?>"
        data-binary-side="<?= e($side) ?>"
        aria-label="Register into <?= (string) ucfirst($side) ?> slot under @<?= e($username) ?>"
        title="Register into <?= (string) ucfirst($side) ?> slot under @<?= e($username) ?>"
    ><i class="icon-base bx bx-plus"></i></a>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($referral !== ''): ?>
    <button
        type="button"
        class="btn btn-sm btn-label-secondary binary-tree-invite"
        data-binary-invite="1"
        data-binary-upline="<?= e($username) ?>"
        data-referral-code="<?= e($referral) ?>"
        title="Copy invite link"
        aria-label="Copy invite link for @<?= e($username) ?>"
    ><i class="icon-base bx bx-link"></i></button>
    <?php endif; ?>

    <?php if ((bool) $is_admin): ?>
    <a
        href="<?= url('admin/members/' . $userId) ?>"
        class="btn btn-sm btn-label-secondary binary-tree-view-member"
        title="View member"
        aria-label="View member"
    ><i class="icon-base bx bx-user"></i></a>
    <a
        href="<?= url('admin/members/' . $userId) ?>"
        class="btn btn-sm btn-label-secondary binary-tree-move-placement"
        title="Move placement"
        aria-label="Move placement"
    ><i class="icon-base bx bx-transfer"></i></a>
    <?php endif; ?>

    <?php if ($isYou && !$is_admin): ?>
    <a
        href="<?= url('dashboard') ?>"
        class="btn btn-sm btn-label-secondary binary-tree-view-earnings"
        title="Open your earnings"
        aria-label="Open your earnings"
    ><i class="icon-base bx bx-wallet"></i></a>
    <?php endif; ?>
</div>
