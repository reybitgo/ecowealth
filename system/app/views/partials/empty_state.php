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
 */

$icon         = icon_class((string) ($icon ?? 'bx-box'));
$title        = $title ?? 'Nothing here yet';
$text         = $text ?? '';
$action_href  = $action_href ?? '';
$action_label = $action_label ?? '';
?>
<div class="text-center py-8 px-4">
  <div class="avatar avatar-lg mb-4">
    <span class="avatar-initial rounded-circle bg-label-secondary">
      <i class="icon-base <?= e($icon) ?> icon-lg"></i>
    </span>
  </div>
  <h5 class="mb-2"><?= e($title) ?></h5>
<?php if ($text !== ''): ?>
  <p class="text-body-secondary mb-4 mx-auto" style="max-width: 32rem"><?= e($text) ?></p>
<?php endif; ?>
<?php if ($action_href !== '' && $action_label !== ''): ?>
  <a href="<?= e(url($action_href)) ?>" class="btn btn-primary"><?= e($action_label) ?></a>
<?php endif; ?>
</div>
