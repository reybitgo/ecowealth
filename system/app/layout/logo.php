<?php
/**
 * Eco-wealth brand logo.
 *
 * The artwork is the marketing site's logo (assets/logo.png), copied into
 * public/assets/img/ecowealth-logo.png so the app ships with it. When
 * $brand_mark is true the partial also prints the square icon variant,
 * which CSS swaps in once the vertical menu collapses.
 *
 * Optional: set $brand_logo_height before including this file to change the
 * rendered height (defaults to 40px; auth cards use a larger value).
 * Optional: set $brand_mark = true to ship the collapsed-menu icon.
 */
$brand_logo_height = (int) ($brand_logo_height ?? 40);
$brand_mark        = (bool) ($brand_mark ?? false);
?>
<span class="app-brand-logo demo">
  <img
    src="<?= e(url('assets/img/ecowealth-logo.png')) ?>"
    alt="Eco-wealth"
    class="app-brand-img"
    style="height: <?= $brand_logo_height ?>px; width: auto;"
  >
<?php if ($brand_mark): ?>
  <img
    src="<?= e(url('assets/img/ecowealth-mark.png')) ?>"
    alt=""
    aria-hidden="true"
    class="app-brand-img-collapsed"
    style="height: 2rem; width: auto;"
  >
<?php endif; ?>
</span>
