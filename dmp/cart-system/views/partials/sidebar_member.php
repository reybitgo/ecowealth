<?php

/**
 * @file   views/partials/sidebar_member.php
 * @brief  Sidebar for member pages
 */
?>
<?php
$cp      = current_page();
$user    = Auth::user();
$initial = strtoupper(substr($user['username'] ?? 'U', 0, 1));
$name    = $user['full_name'] ?: ('@' . $user['username']);
$view    = $_GET['view'] ?? '';

// Build nav items
$nav = [
  ['page' => 'dashboard', 'icon' => '🏠', 'label' => 'Dashboard',        'pages' => ['dashboard']],
  ['page' => 'earnings',  'icon' => '💰', 'label' => 'Earnings',         'pages' => ['earnings']],
  ['page' => 'cap_status', 'icon' => '🛡️', 'label' => 'Lifetime Cap',     'pages' => ['cap_status']],
  ['page' => 'dfi_history', 'icon' => '📅', 'label' => 'DFI History',      'pages' => ['dfi_history']],
  'SEPARATOR:Network',
];
$hasBinary = Auth::isAdmin() || Package::hasPairing((int)($user['package_id'] ?? 0));
if ($hasBinary) {
  $nav[] = ['page' => 'genealogy&view=binary',  'icon' => '🌳', 'label' => 'Binary Tree',      'pages' => ['genealogy'], 'view' => 'binary'];
}
$nav[] = ['page' => 'genealogy&view=referral', 'icon' => '👥', 'label' => 'Referral Network', 'pages' => ['genealogy'], 'view' => 'referral'];
$nav[] = 'SEPARATOR:Account';

// Staff accounts (admin/superadmin) are not themselves members — no upgrades.
// Standard accounts cannot be upgraded. Members only see the button when a plan-compatible higher-tier package exists.
$canUpgrade = false;
if (!Auth::isAdmin() && ($user['reg_payment_method'] ?? '') !== 'standard') {
  $curPkgId = (int)($user['package_id'] ?? 0);
  $curFee   = 0.0;
  $curPkg   = $curPkgId ? Package::find($curPkgId) : null;
  if ($curPkg) {
    $curFee = (float)$curPkg['entry_fee'];
  }
  foreach (Package::all(true) as $pkg) {
    if ((int)$pkg['id'] !== $curPkgId
        && (float)$pkg['entry_fee'] > $curFee
        && Package::upgradeCompatible($curPkgId, (int)$pkg['id'])
    ) {
      $canUpgrade = true;
      break;
    }
  }
}
if ($canUpgrade) {
  $nav[] = ['page' => 'upgrade', 'icon' => '⬆️', 'label' => 'Upgrade Package', 'pages' => ['upgrade']];
}

$nav[] = ['page' => 'register&sponsor=' . $user['username'], 'icon' => '➕', 'label' => 'Register Member', 'pages' => ['register']];
$nav[] = ['page' => 'ewallet_transfer', 'icon' => '💱', 'label' => 'Send Money', 'pages' => ['ewallet_transfer']];
$nav[] = ['page' => 'payout',  'icon' => '💳', 'label' => 'Payouts',   'pages' => ['payout']];

// Shop section (plan §5.4) — visible to any logged-in session using this sidebar.
$cartBadge = Cart::itemCountForMember((int) ($user['id'] ?? 0));
$nav[] = 'SEPARATOR:Shop';
$nav[] = ['page' => 'shop',        'icon' => '🛍️', 'label' => 'Shop',      'pages' => ['shop']];
$nav[] = ['page' => 'shop_orders', 'icon' => '🧾', 'label' => 'My Orders', 'pages' => ['shop_orders', 'shop_order']];
$nav[] = ['page' => 'cart',        'icon' => '🛒', 'label' => 'Cart',      'pages' => ['cart'], 'badge' => $cartBadge, 'badge_class' => 'sidebar-cart-badge'];

// Add Admin View link if the logged-in user is an admin browsing as member
if (Auth::isAdmin()) {
  $nav[] = 'SEPARATOR:Admin';
  $nav[] = ['page' => 'admin', 'icon' => '📊', 'label' => 'Admin View', 'pages' => []];
}

$nav[] = 'SEPARATOR:Site';
$nav[] = ['page' => 'profile', 'icon' => '⚙️', 'label' => 'Profile & Settings', 'pages' => ['profile']];
$nav[] = ['page' => '__frontend__', 'icon' => '🌐', 'label' => 'View Frontend', 'pages' => []];

// Declared in a view partial, so a second include would be a fatal
// "Cannot redeclare". The body is only emitted when the function is CALLED.
// (Defect M14, tmp/shop/remedy.md.)
if (!function_exists('memberNavActive')) {
function memberNavActive($item, $cp, $view)
{
  if (!isset($item['pages'])) return false;
  if (!in_array($cp, $item['pages'])) return false;
  if (isset($item['view'])) return $view === $item['view'];
  return true;
}
} // end if (!function_exists('memberNavActive'))

if (!function_exists('renderSidebarNav')) {
function renderSidebarNav($nav, $cp, $user, $view, $initial, $name)
{ ?>
  <div class="sidebar-brand">
    <div class="brand-icon">
      <img src="<?= APP_URL ?>/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/../../assets/img/logo.png') ?>" alt="AltasFarm Livelihood">
    </div>
    <div>
      <div class="brand-name"><?= e(setting('site_name', APP_NAME)) ?></div>
      <div class="brand-sub">Member Portal</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($nav as $item):
      if (is_string($item)) {
        echo '<div class="nav-section-label">' . e(substr($item, 10)) . '</div>';
        continue;
      }
      $active = memberNavActive($item, $cp, $view);
      $href   = $item['page'] === '__frontend__'
        ? APP_URL . '/'
        : link_to($item['page']);
      $target = $item['page'] === '__frontend__' ? ' target="_blank" rel="noopener"' : '';
    ?>
      <a href="<?= $href ?>" <?= $target ?> class="nav-item-link <?= $active ? 'active' : '' ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= e($item['label']) ?>
        <?php if (isset($item['badge'])): ?><span class="nav-badge<?= !empty($item['badge_class']) ? ' ' . e($item['badge_class']) : '' ?><?= (int) $item['badge'] > 0 ? '' : ' d-none' ?>"><?= (int) $item['badge'] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?php if (Auth::isSuperadmin()): ?>
    <a href="<?= link_to('slogin') ?>" target="_blank" rel="noopener" class="nav-item-link">
      <span class="nav-icon">⭐</span> Super Login
    </a>
    <?php endif; ?>
    <a href="<?= link_to('logout') ?>" class="nav-item-link">
      <span class="nav-icon">🚪</span> Logout
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar">
        <?php if (!empty($user['photo'])): ?>
          <img src="<?= APP_URL ?>/uploads/<?= e($user['photo']) ?>" alt="">
        <?php else: ?>
          <?= $initial ?>
        <?php endif; ?>
      </div>
      <div class="user-info">
        <div class="user-name"><?= e($name) ?></div>
        <div class="user-role"><?= e(($user['reg_payment_method'] ?? '') === 'standard' ? 'Standard' : ($user['package_name'] ?? 'Member')) ?></div>
      </div>
    </div>
  </div>
<?php } ?>
<?php } // end if (!function_exists('renderSidebarNav')) ?>

<!-- Desktop sidebar (hidden on <lg) -->
<div class="sidebar d-none d-lg-flex flex-column">
  <?php renderSidebarNav($nav, $cp, $user, $view, $initial, $name); ?>
</div>

<!-- Mobile offcanvas sidebar -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel"
  style="width:var(--sidebar-w)!important;background:var(--sidebar-bg)!important;">
  <div class="offcanvas-header d-flex align-items-center" style="padding:.5rem 0 0;border:none;">
    <!-- Phase 8.6: aria-labelledby pointed at #mobileSidebarLabel, which does
         not exist anywhere in the codebase — a dangling reference, so the
         offcanvas announced itself as unlabelled. Visually hidden rather than
         removed so the header layout is untouched. -->
    <h2 class="visually-hidden mb-0" id="mobileSidebarLabel">Main navigation</h2>
    <button type="button" class="btn-close ms-auto me-3 mt-2"
      data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0 d-flex flex-column" style="overflow-y:auto;">
    <?php renderSidebarNav($nav, $cp, $user, $view, $initial, $name); ?>
  </div>
</div>