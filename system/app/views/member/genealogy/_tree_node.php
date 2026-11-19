<?php
declare(strict_types=1);

/**
 * Binary tree — one node in the no-JS list.
 *
 * Recursive include, bounded by the depth cap set in BinaryTreeService
 * (children beyond the cap are already pruned; `has_more` flags them).
 *
 * @var array<string, mixed> $node      one node card from the JSON tree
 * @var int                  $max_depth max depth below root
 * @var int                  $viewer_id viewer's user id
 * @var bool                 $is_admin
 * @var bool                 $can_register
 */

$node       = (array) $node;
$children   = (array) ($node['children'] ?? []);
$hasMore    = (bool) ($node['has_more'] ?? false);
$userId     = (int) ($node['id'] ?? 0);
$nodeDepth  = (int) ($node['depth'] ?? 0);
$isYou      = (bool) ($node['is_you'] ?? false);
$username   = (string) ($node['username'] ?? '');
$fullName   = (string) ($node['full_name'] ?? '');
$side       = (string) ($node['side'] ?? null);
$status     = (string) ($node['status'] ?? '');
$points     = (array) ($node['points'] ?? []);
$slots      = (array) ($node['slots'] ?? []);

$sideClass = $side === 'left' ? 'text-primary' : ($side === 'right' ? 'text-danger' : 'text-body-secondary');
$sideLabel = $side === 'left' ? 'L' : ($side === 'right' ? 'R' : '—');

$statusBadge = static function (string $status): string {
    return $status === 'active' ? 'bg-label-success' : 'bg-label-secondary';
};

$memberName = $is_admin && $fullName !== ''
    ? e($fullName) . ' <span class="text-body-secondary small fw-normal">@' . e($username) . '</span>'
    : '@' . e($username);
?>
<li
    role="treeitem"
    aria-level="<?= max(1, $nodeDepth + 1) ?>"
    aria-expanded="<?= $children !== [] || $hasMore ? 'true' : 'false' ?>"
    class="binary-tree-node mb-3"
    data-node-id="<?= $userId ?>"
    data-node-depth="<?= $nodeDepth ?>"
>
    <div class="d-flex align-items-start gap-2 binary-tree-node-row">
        <!-- Expander -->
        <?php if ($children !== [] || $hasMore): ?>
        <span
            class="binary-tree-expander d-inline-flex align-items-center justify-content-center"
            style="width:1.25rem;height:1.25rem;"
            role="button"
            tabindex="0"
            aria-label="Toggle children"
        ><i class="icon-base bx bx-chevron-right"></i></span>
        <?php else: ?>
        <span class="binary-tree-spacer" style="width:1.25rem;height:1.25rem;"></span>
        <?php endif; ?>

        <!-- Node content -->
        <div class="flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-1 mb-1">
                <span class="badge rounded-pill <?= $statusBadge($status) ?> small"><?= e(ucfirst($status)) ?></span>
                <?php if ($isYou): ?><span class="badge bg-label-info small">You</span><?php endif; ?>
                <span class="badge rounded-pill <?= $sideClass ?> small"><?= e($sideLabel) ?></span>
                <span class="text-body-secondary small">depth <?= $nodeDepth ?></span>
            </div>
            <div class="fw-medium text-truncate"><?= $memberName ?></div>
            <div class="binary-tree-points small text-body-secondary">
                <span class="text-primary fw-bold">L <?= (int) ($points['left'] ?? 0) ?></span>
                <span class="text-danger fw-bold">R <?= (int) ($points['right'] ?? 0) ?></span>
                <?php if (isset($points['pairs'])): ?>
                <span class="text-success fw-bold">P <?= (int) ($points['pairs'] ?? 0) ?></span>
                <?php endif; ?>
                <?php if (isset($points['pairs_today'])): ?>
                <span class="text-info fw-bold">today <?= (int) ($points['pairs_today'] ?? 0) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <?= View::capture('member/genealogy/_tree_actions', [
            'node'         => $node,
            'slots'        => $slots,
            'viewer_id'    => (int) $viewer_id,
            'is_admin'     => (bool) $is_admin,
            'can_register' => (bool) $can_register,
        ]) ?>
    </div>

    <?php if ($children !== []): ?>
    <ul role="group" class="binary-tree-children list-unstyled mt-2 ms-4 border-start border-secondary">
        <?php foreach ($children as $child): ?>
            <?= View::capture('member/genealogy/_tree_node', [
                'node'         => $child,
                'max_depth'    => (int) $max_depth,
                'viewer_id'    => (int) $viewer_id,
                'is_admin'     => (bool) $is_admin,
                'can_register' => (bool) $can_register,
            ]) ?>
        <?php endforeach; ?>
    </ul>
    <?php elseif ($hasMore): ?>
    <div class="binary-tree-more small text-body-secondary ms-4 mt-1">
        <a
            href="<?= url('genealogy?view=binary&root=' . $userId . '&depth=' . min((int) $max_depth, $nodeDepth + 1)) ?>"
            class="text-decoration-none text-secondary"
        >
            More children <i class="icon-base bx bx-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>
</li>
