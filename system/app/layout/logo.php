<?php
/**
 * Eco-wealth brand logo.
 *
 * The artwork is the marketing site's logo (assets/logo.png), copied into
 * public/assets/img/ecowealth-logo.png so the app ships with it.
 *
 * Optional: set $brand_logo_height before including this file to change the
 * rendered height (defaults to 40px; auth cards use a larger value).
 */
$brand_logo_height = (int) ($brand_logo_height ?? 40);
?>
<span class="app-brand-logo demo">
  <img
    src="<?= e(url('assets/img/ecowealth-logo.png')) ?>"
    alt="Eco-wealth"
    class="app-brand-img"
    style="height: <?= $brand_logo_height ?>px; width: auto; display: block;"
  >
</span>
