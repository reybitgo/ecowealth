<?php
declare(strict_types=1);

/**
 * Binary tree — per-node actions menu (baseline, no-JS version).
 *
 * Phase 22G-S2: links only. The in-node registration modal (22G-S4) adds
 * the locked-upline/side modal with live validation; here the + is a plain
 * link to the prefilled registration form.
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
?>
<div class="binary-tree-actions d-flex align-items-center gap-1">
    <?php foreach (['left', 'right'] as $side): ?>
    <?php
        $slot = isset($slots[$side]) ? (array) $slots[$side] : ['free' => true, 'user' => null];
        $free = (bool) ($slot['free'] ?? true);
    ?>
    <?php if ($free): ?>
    <a
        href="<?= url('register?sponsor=' . rawurlencode((string) ($node['username'] ?? '')) . '&binary_position=' . $side . '&return=genealogy') ?>"
        class="btn btn-sm btn-outline-secondary binary-tree-slot-add"
        role="button"
        aria-label="Register into <?= (string) ucfirst($side) ?> slot under @<?= e((string) ($node['username'] ?? '')) ?>"
        title="Register into <?= (string) ucfirst($side) ?> slot under @<?= e((string) ($node['username'] ?? '')) ?>"
    ><i class="icon-base bx bx-plus"></i></a>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php if ((bool) $is_admin): ?>
    <a
        href="<?= url('admin/members/' . $userId) ?>"
        class="btn btn-sm btn-outline-secondary binary-tree-view-member"
        title="View member"
        aria-label="View member"
    ><i class="icon-base bx bx-user"></i></a>
    <a
        href="<?= url('admin/members/' . $userId) ?>"
        class="btn btn-sm btn-outline-secondary binary-tree-move-placement"
        title="Move placement"
        aria-label="Move placement"
    ><i class="icon-base bx bx-transfer"></i></a>
    <?php endif; ?>

    <?php if ($isYou && !$is_admin): ?>
    <a
        href="<?= url('dashboard') ?>"
        class="btn btn-sm btn-outline-secondary binary-tree-view-earnings"
        title="Open your earnings"
        aria-label="Open your earnings"
    ><i class="icon-base bx bx-wallet"></i></a>
    <?php endif; ?>
</div>
