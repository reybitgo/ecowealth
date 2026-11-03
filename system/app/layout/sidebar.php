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
 * Items from a later phase render disabled with a "Soon" pill while
 * APP_DEBUG is on, and are hidden entirely in production. Items the
 * viewer may not see (admin items in the member layout, superadmin
 * items for a plain admin) never render at all.
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

        $live = nav_item_live($item);
        if (!$live && !APP_DEBUG) {
            continue;
        }

        $item['_live'] = $live;
        $items[]       = $item;
    }

    if ($items === []) {
        continue;
    }
?>
            <li class="menu-header mt-7">
              <span class="menu-header-text"><?= e($section['label']) ?></span>
            </li>
<?php foreach ($items as $item): ?>
<?php if (($item['type'] ?? '') === 'post'): ?>
            <li class="menu-item">
              <form method="POST" action="<?= e(url($item['path'])) ?>" class="w-100">
                <?= csrf_field() ?>
                <button type="submit" class="menu-link w-100 border-0 bg-transparent text-start">
                  <i class="menu-icon icon-base <?= e($item['icon']) ?>"></i>
                  <div><?= e($item['label']) ?></div>
                </button>
              </form>
            </li>
<?php elseif (!$item['_live']): ?>
            <li class="menu-item disabled">
              <a
                href="javascript:void(0)"
                class="menu-link"
                aria-disabled="true"
                title="Built in phase <?= (int) ($item['phase'] ?? 0) ?>"
              >
                <i class="menu-icon icon-base <?= e($item['icon']) ?>"></i>
                <div><?= e($item['label']) ?></div>
                <span class="badge rounded-pill bg-label-secondary ms-auto">Soon</span>
              </a>
            </li>
<?php else: ?>
            <li class="menu-item <?= $current_nav === $item['key'] ? 'active' : '' ?>">
              <a href="<?= e(url($item['path'])) ?>" class="menu-link">
                <i class="menu-icon icon-base <?= e($item['icon']) ?>"></i>
                <div><?= e($item['label']) ?></div>
<?php if (!empty($item['badge']) && !empty($nav_badges[$item['badge']])): ?>
                <span class="badge rounded-pill bg-label-primary ms-auto"><?= (int) $nav_badges[$item['badge']] ?></span>
<?php endif; ?>
              </a>
            </li>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
          </ul>
