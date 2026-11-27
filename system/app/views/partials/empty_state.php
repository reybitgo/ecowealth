<?php
declare(strict_types=1);

/**
 * Empty state for tables, lists and cards.
 *
 * @var string $icon   Boxicons class (default 'bx bx-box')
 * @var string $title  Short headline
 * @var string $text   One-line explanation
 * @var string $action_href  Optional app path for the call to action
 * @var string $action_label Optional call-to-action label
 * @var bool   $compact Render the small variant used by dashboard widgets and report cards
 */

$icon         = icon_class((string) ($icon ?? 'bx-box'));
$title        = $title ?? 'Nothing here yet';
$text         = $text ?? '';
$action_href  = $action_href ?? '';
$action_label = $action_label ?? '';
$compact      = (bool) ($compact ?? false);
$hasAction    = $action_href !== '' && $action_label !== '';
?>
<div class="text-center <?= $compact ? 'py-4 px-3 empty-state-compact' : 'py-8 px-4' ?>">
  <div class="avatar <?= $compact ? 'avatar-sm mb-2' : 'avatar-lg mb-4' ?> mx-auto empty-state-avatar">
    <span class="avatar-initial rounded-circle bg-label-secondary">
      <i class="icon-base <?= e($icon) ?><?= $compact ? '' : ' icon-lg' ?>"></i>
    </span>
  </div>
  <h5 class="empty-state-title <?= $compact ? 'mb-1' : 'mb-2' ?>"><?= e($title) ?></h5>
<?php if ($text !== ''): ?>
  <p class="empty-state-text text-body-secondary <?= $compact ? ($hasAction ? 'small mb-3' : 'small mb-0') : 'mb-4' ?> mx-auto" style="max-width: 32rem"><?= e($text) ?></p>
<?php endif; ?>
<?php if ($hasAction): ?>
  <a href="<?= e(url($action_href)) ?>" class="btn btn-primary<?= $compact ? ' btn-sm' : '' ?>"><?= e($action_label) ?></a>
<?php endif; ?>
</div>
