<?php
declare(strict_types=1);

/**
 * Sidebar menu — rendered from app/layout/nav.php (plan §3.5).
 *
 * Included by app/layout/app_header.php with:
 *   $layout      member | admin
 *   $user        current user row (null for guests)
 *   $current_nav active item key
 *   $nav_badges  optional counters, e.g. ['cart' => 3]
 *
 * Only items whose phase has shipped render. Keeping unbuilt screens
 * out of the menu is what makes the layout feel finished: every link
 * is a live destination, never a "Soon" placeholder. Items the viewer
 * may not see (admin items in the member layout, superadmin items for
 * a plain admin) never render at all.
 */

$nav_layout = $layout === 'admin' ? 'admin' : 'member';
$nav_menu   = nav_config()[$nav_layout] ?? ['title' => '', 'sections' => []];
?>
          <ul class="menu-inner py-1">
<?php foreach ($nav_menu['sections'] as $section): ?>
<?php
    /* Filter first: a section with nothing to show disappears. */
    $items = [];
    foreach ($section['items'] ?? [] as $item) {
        if (!nav_can_see($item, $user)) {
            continue;
        }

        if (!nav_item_live($item)) {
            continue;
        }

        $items[] = $item;
    }

    if ($items === []) {
        continue;
    }
?>
            <li class="menu-header mt-7">
              <span class="menu-header-text"><?= e($section['label']) ?></span>
            </li>
<?php foreach ($items as $item): ?>
<?php
    $is_active  = $current_nav === ($item['key'] ?? '');
    $is_new_tab = ($item['target'] ?? '') === '_blank';
    $link_attrs = ($is_active ? ' aria-current="page"' : '')
        . ($is_new_tab ? ' target="_blank" rel="noopener"' : '');
?>
<?php if (($item['type'] ?? '') === 'post'): ?>
            <li class="menu-item">
              <form method="POST" action="<?= e(url($item['path'])) ?>" class="w-100">
                <?= csrf_field() ?>
                <button type="submit" class="menu-link w-100 border-0 bg-transparent text-start">
                  <i class="menu-icon icon-base <?= e(icon_class($item['icon'])) ?>"></i>
                  <div><?= e($item['label']) ?></div>
                </button>
              </form>
            </li>
<?php else: ?>
<?php
    /* Badged items keep their badge element in the DOM even at zero, hidden.
     * The cart script updates every [data-cart-badge] in place after an AJAX
     * add/remove; an element that only existed above zero could never be
     * revealed client-side (the count would jump in the navbar but not here). */
    $badge_key   = !empty($item['badge']) ? (string) $item['badge'] : '';
    $badge_count = $badge_key !== '' ? (int) ($nav_badges[$badge_key] ?? 0) : 0;
?>
            <li class="menu-item <?= $is_active ? 'active' : '' ?>">
              <a href="<?= e(url($item['path'])) ?>" class="menu-link"<?= $link_attrs ?>>
                <i class="menu-icon icon-base <?= e(icon_class($item['icon'])) ?>"></i>
                <div><?= e($item['label']) ?></div>
<?php if ($badge_key !== ''): ?>
                <span class="badge rounded-pill bg-label-primary ms-auto" data-nav-badge="<?= e($badge_key) ?>"<?= $badge_key === 'cart' ? ' data-cart-badge' : '' ?><?= $badge_count > 0 ? '' : ' hidden' ?>><?= $badge_count ?></span>
<?php endif; ?>
              </a>
            </li>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
          </ul>
