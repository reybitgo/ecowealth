<?php
declare(strict_types=1);

/**
 * Stat card — the one summary tile used by every KPI row (UX-10).
 *
 * The portals used to carry four near-identical card implementations
 * (.stat-card, .commission-stat-card, .wallet-stat-card,
 * .report-kpi-card) with the figure at three different heading levels.
 * Every tile now renders through this partial and the `.stat-card` /
 * `.stat-value` pair in app.css, so the same figure is the same size on
 * every page.
 *
 * @var string $label      Card label.
 * @var string $value      The figure, already formatted.
 * @var string $hint       Optional context line; trusted inline markup.
 * @var string $icon       Boxicons class.
 * @var string $tone       Bootstrap label tone (primary, success, …).
 * @var string $href       Optional destination; renders the card as a link.
 * @var string $badge      Optional status pill under the figure.
 * @var string $badge_tone Tone for the status pill.
 * @var string $badge_icon Boxicons class for the status pill.
 */

$label     = (string) ($label ?? '');
$value     = (string) ($value ?? '');
$hint      = (string) ($hint ?? '');
$icon      = (string) ($icon ?? 'bx-box');
$tone      = (string) ($tone ?? 'primary');
$href      = (string) ($href ?? '');
$badge     = (string) ($badge ?? '');
$badgeTone = (string) ($badge_tone ?? 'secondary');
$badgeIcon = (string) ($badge_icon ?? '');

$tag   = $href === '' ? 'div' : 'a';
$class = 'card h-100 stat-card' . ($href === '' ? '' : ' text-reset text-decoration-none');
$attrs = $href === '' ? '' : ' href="' . e(url($href)) . '"';
?>
<<?= $tag ?> class="<?= e($class) ?>"<?= $attrs ?>>
  <div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
      <span class="avatar avatar-sm">
        <span class="avatar-initial rounded bg-label-<?= e($tone) ?>">
          <i class="icon-base <?= e(icon_class($icon)) ?>"></i>
        </span>
      </span>
      <span class="text-body-secondary small text-end"><?= e($label) ?></span>
    </div>
    <h4 class="stat-value mb-2"><?= e($value) ?></h4>
<?php if ($badge !== ''): ?>
    <span class="badge rounded-pill bg-label-<?= e($badgeTone) ?><?= $hint === '' ? '' : ' mb-2' ?>">
<?php if ($badgeIcon !== ''): ?>
      <i class="icon-base <?= e(icon_class($badgeIcon)) ?> me-1"></i>
<?php endif; ?>
      <?= e($badge) ?>
    </span>
<?php endif; ?>
<?php if ($hint !== ''): ?>
    <p class="text-body-secondary small mb-0"><?= $hint ?></p>
<?php endif; ?>
  </div>
</<?= $tag ?>>
