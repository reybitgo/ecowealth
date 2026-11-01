<?php

/**
 * @file   views/partials/topbar.php
 * @brief  Topbar for member and admin pages
 */
?>
<?php
// === Load the correct user based on ?id= (for admin view) ===
if (isset($_GET['page']) && $_GET['page'] === 'admin_user_view' && !empty($_GET['id']) && Auth::isAdmin()) {
    $topbarUser = getUserById((int)$_GET['id']);

    if (!$topbarUser) {
        flash('error', 'User not found.');
        redirect('/?page=admin_users');
    }
    $walletUser = $topbarUser;
} else {
    // Fallback: should not happen if routed correctly
    $topbarUser = Auth::user();
    // Superadmins act as an admin proxy — surface the acting admin's wallet.
    $walletUser = Auth::isSuperadmin() ? (User::find(Auth::actingAdminId()) ?: $topbarUser) : $topbarUser;
}
$topbarBalance = fmt_money($walletUser['ewallet_balance'] ?? 0);
$initials      = strtoupper(substr($topbarUser['username'] ?? 'U', 0, 1));
$isMember      = ($topbarUser['role'] ?? '') === 'member';
// Cart badge — any logged-in session can shop (§5.8); one cheap GROUP BY-free count.
$topbarCartCount = 0;
if (Auth::check()) {
    $topbarCart = Cart::getActive(Auth::id());
    $topbarCartCount = $topbarCart ? (int) Cart::getTotals((int) $topbarCart['id'])['total_items'] : 0;
}
?>
<div class="topbar-wrapper no-print">
    <!-- Hamburger (mobile only — triggers offcanvas) -->
    <button class="btn btn-sm btn-light d-lg-none me-1 border-0"
        type="button"
        data-bs-toggle="offcanvas"
        data-bs-target="#mobileSidebar"
        aria-controls="mobileSidebar"
        style="font-size:1.2rem;padding:.3rem .55rem;">
        ☰
    </button>

    <div class="topbar-title"><?= e($pageTitle ?? 'Dashboard') ?></div>

    <div class="d-flex align-items-center gap-2">
        <?php if ($isMember || Auth::isSuperadmin()): ?>
            <div class="topbar-balance d-none d-sm-flex">
                <span class="bal-label">Balance</span>
                <span class="bal-amount" id="topbarBalance"><?= $topbarBalance ?></span>
            </div>
            <?php if (!empty($topbarUser['cd_active'])): ?>
                <div class="topbar-balance" style="background:linear-gradient(135deg,#fef3c7,#fffbeb);border-color:rgba(245,158,11,0.3);">
                    <span class="bal-label" style="color:#d97706;">CD</span>
                    <span class="bal-amount" style="color:#d97706;font-size:.8rem;">⏳ Active</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="dropdown">
            <button class="topbar-balance border-0 dropdown-toggle" type="button"
                data-bs-toggle="dropdown" aria-expanded="false"
                title="<?= e($topbarUser['username'] ?? '') ?>" style="cursor:pointer;">
                <span class="bal-label"><?= Auth::isAdmin() ? 'Admin' : 'User' ?></span>
                <span class="bal-amount">@<?= e($topbarUser['username'] ?? '') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-3 py-1 mt-1" style="min-width:160px;font-size:.82rem;">
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-1.5" href="<?= link_to(Auth::isAdmin() ? 'admin_settings' : 'profile') ?>">
                        <span style="font-size:.95rem;"><?= Auth::isAdmin() ? '⚙️' : '👤' ?></span>
                        <span><?= Auth::isAdmin() ? 'Settings' : 'Profile' ?></span>
                    </a>
                </li>
                <?php if (Auth::isSuperadmin()): ?>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-1.5" href="<?= link_to('slogin') ?>" target="_blank" rel="noopener">
                        <span style="font-size:.95rem;">⭐</span>
                        <span>Super Login</span>
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <hr class="dropdown-divider my-1">
                </li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 py-1.5" href="<?= link_to('logout') ?>">
                        <span style="font-size:.95rem;">🚪</span>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>

        <?php if (Auth::isAdmin() && ($_GET['page'] ?? '') === 'admin_settings'): ?>
            <button class="btn btn-sm btn-outline-light" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas"
                title="Settings navigation" style="font-size:1.1rem;line-height:1;">
                ⚙️
            </button>
        <?php endif; ?>

        <?php if (Auth::check()): ?>
        <button type="button" class="btn btn-sm btn-light position-relative border-0 topbar-cart-btn"
                data-bs-toggle="offcanvas" data-bs-target="#cartOffcanvas"
                title="Cart" style="font-size:1.05rem;line-height:1;padding:.3rem .55rem;">
            🛒
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary topbar-cart-badge<?= $topbarCartCount > 0 ? '' : ' d-none' ?>"><?= $topbarCartCount ?></span>
        </button>
        <?php endif; ?>
    </div>
</div>
<?php if (defined('MEMBER_MAINTENANCE_BANNER') && MEMBER_MAINTENANCE_BANNER && !Auth::isAdmin()): ?>
  <div class="alert alert-warning mb-0 rounded-0 no-print"
       role="alert"
       style="position:sticky;top:var(--topbar-h);z-index:1029;border:0;border-bottom:1px solid rgba(245,158,11,.4);padding:.6rem 1.5rem;font-size:.85rem;text-align:center;">
    ⚠️ <strong>System Currently Under Maintenance</strong>
  </div>
<?php endif; ?>
