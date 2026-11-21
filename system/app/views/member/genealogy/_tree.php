<?php
declare(strict_types=1);

/**
 * Binary tree — the no-JS list shell and recursive node list.
 *
 * Renders one root node and its descendants as a semantic, indented
 * `<ul role="tree">` list. Bounded by the requested depth: nodes beyond
 * the cap show a "more children" link instead of recursing.
 *
 * Phase 22G-S2: server-rendered first paint. d3 (added in 22G-S3) hydrates
 * this same markup into an SVG; without JavaScript the list is the document.
 *
 * @var array<string, mixed> $tree    nested root node from BinaryTreeService::subtree()['tree']
 * @var int                  $depth   requested depth below root (1..5)
 * @var int                  $root_id root node's user id
 * @var int                  $viewer_id viewer's user id
 * @var bool                 $is_admin
 * @var bool                 $can_register
 * @var bool                 $truncated
 */

$root = (array) $tree;

$sideLabel = static function (?string $side): string {
    return $side === 'left' ? 'L' : ($side === 'right' ? 'R' : '—');
};

$statusBadge = static function (string $status): string {
    return $status === 'active' ? 'bg-label-success' : 'bg-label-secondary';
};
?>
<ul
    role="tree"
    aria-label="Binary placement tree"
    class="binary-tree-list list-unstyled mb-0"
    data-tree-root="<?= (int) $root_id ?>"
    data-tree-depth="<?= (int) $depth ?>"
    data-tree-viewer="<?= (int) $viewer_id ?>"
>
<?= View::capture('member/genealogy/_tree_node', [
    'node'         => $root,
    'max_depth'    => (int) $depth,
    'viewer_id'    => (int) $viewer_id,
    'is_admin'     => (bool) $is_admin,
    'can_register' => (bool) $can_register,
]) ?>
</ul>

<?php if ((bool) $truncated): ?>
<div class="binary-tree-truncated alert alert-info py-3 mb-0 small mt-3">
    The tree is large — only the first 500 nodes are in this paint. Expand a
    branch to load its children.
</div>
<?php endif; ?>
