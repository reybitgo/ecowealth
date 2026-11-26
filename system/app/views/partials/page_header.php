<?php
declare(strict_types=1);

/**
 * Shared page header — one vertical rhythm for every page.
 *
 * The 24px `mb-6` gap below this row is the page rhythm used by the
 * commerce screens; new pages get it for free by rendering this partial
 * instead of hand-writing the heading markup. The description may contain
 * trusted inline markup (badges, live-status spans) and actions may be a
 * pre-rendered string or a callable that prints the action group.
 *
 * @var string                    $title       Page heading text.
 * @var string                    $description Pre-rendered description; may be empty.
 * @var string|callable|null      $actions     Pre-rendered action HTML or a printer.
 * @var array<string, string>     $attributes  Extra attributes on the header row.
 * @var string                    $align       start (default) or center.
 */

$description = (string) ($description ?? '');
$actions     = $actions ?? null;
$attributes  = $attributes ?? [];
$align       = ($align ?? 'start') === 'center' ? 'align-items-center' : 'align-items-start';
?>
<div class="d-flex flex-wrap <?= $align ?> justify-content-between gap-3 mb-6"<?php foreach ($attributes as $name => $value): ?> <?= e((string) $name) ?>="<?= e((string) $value) ?>"<?php endforeach; ?>>
  <div>
    <h4 class="mb-1"><?= e((string) $title) ?></h4>
<?php if ($description !== ''): ?>
    <p class="text-body-secondary mb-0"><?= $description ?></p>
<?php endif; ?>
  </div>
<?php if ($actions !== null): ?>
<?php if (is_callable($actions)): ?>
<?php $actions(); ?>
<?php elseif ((string) $actions !== ''): ?>
  <?= $actions ?>
<?php endif; ?>
<?php endif; ?>
</div>
