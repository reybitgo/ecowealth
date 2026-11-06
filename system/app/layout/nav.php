<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — menu configuration (plan §3.5)
 *
 *  Sections → items → route, icon, badge. Rendered by
 *  app/layout/sidebar.php inside the member and admin layouts.
 *
 *  Item keys
 *    key    unique within the layout; matches $current_nav
 *    label  menu text
 *    path   app path handed to url()  (never a hard-coded "/…")
 *    icon   Boxicons class
 *    guard  any | member | admin | superadmin — decides visibility
 *    phase  the build phase that ships it. Items from a later phase are
 *           hidden until that phase lands, so every visible link is live.
 *    badge  optional key into $nav_badges
 *    type   'post' renders a CSRF-protected form button (sign out)
 * ============================================================== */

return [
    /* ================= Member portal ============================ */
    'member' => [
        'title'    => 'Member portal',
        'sections' => [
            [
                'label' => 'Shopping',
                'items' => [
                    ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => 'dashboard', 'icon' => 'bx-home-smile', 'guard' => 'member', 'phase' => 1],
                    ['key' => 'shop', 'label' => 'Shop', 'path' => 'shop', 'icon' => 'bx-store', 'guard' => 'member', 'phase' => 1],
                    ['key' => 'cart', 'label' => 'Cart', 'path' => 'cart', 'icon' => 'bx-cart', 'guard' => 'member', 'phase' => 4, 'badge' => 'cart'],
                    ['key' => 'orders', 'label' => 'My orders', 'path' => 'orders', 'icon' => 'bx-receipt', 'guard' => 'member', 'phase' => 6],
                ],
            ],
            [
                'label' => 'Membership',
                'items' => [
                    ['key' => 'packages', 'label' => 'Packages', 'path' => 'packages', 'icon' => 'bx-box', 'guard' => 'member', 'phase' => 3],
                    ['key' => 'membership', 'label' => 'Membership', 'path' => 'membership', 'icon' => 'bx-medal', 'guard' => 'member', 'phase' => 9],
                    ['key' => 'genealogy', 'label' => 'Genealogy', 'path' => 'genealogy', 'icon' => 'bx-git-branch', 'guard' => 'member', 'phase' => 9],
                    ['key' => 'earnings', 'label' => 'Earnings', 'path' => 'earnings', 'icon' => 'bx-line-chart', 'guard' => 'member', 'phase' => 10],
                ],
            ],
            [
                'label' => 'Money',
                'items' => [
                    ['key' => 'wallet', 'label' => 'E-wallet', 'path' => 'wallet', 'icon' => 'bx-wallet', 'guard' => 'member', 'phase' => 5],
                    ['key' => 'payouts', 'label' => 'Payouts', 'path' => 'payouts', 'icon' => 'bx-money-withdraw', 'guard' => 'member', 'phase' => 13],
                ],
            ],
            [
                'label' => 'Account',
                'items' => [
                    ['key' => 'profile', 'label' => 'Profile', 'path' => 'profile', 'icon' => 'bx-user-circle', 'guard' => 'member', 'phase' => 6],
                    ['key' => 'password', 'label' => 'Change password', 'path' => 'profile/password', 'icon' => 'bx-lock-alt', 'guard' => 'member', 'phase' => 1],
                    ['key' => 'logout', 'label' => 'Sign out', 'path' => 'logout', 'icon' => 'bx-power-off', 'guard' => 'member', 'type' => 'post'],
                ],
            ],
        ],
    ],

    /* ================= Back office ============================== */
    'admin' => [
        'title'    => 'Back office',
        'sections' => [
            [
                'label' => 'Overview',
                'items' => [
                    ['key' => 'admin.dashboard', 'label' => 'Dashboard', 'path' => 'admin', 'icon' => 'bx-home-smile', 'guard' => 'admin', 'phase' => 1],
                    ['key' => 'admin.storefront', 'label' => 'Storefront', 'path' => 'shop', 'icon' => 'bx-store', 'guard' => 'admin', 'phase' => 1, 'target' => '_blank'],
                    /* Admins may shop too (the member guard admits them), so the
                     * drawer's cart has a home in this sidebar as well. */
                    ['key' => 'admin.cart', 'label' => 'Cart', 'path' => 'cart', 'icon' => 'bx-cart', 'guard' => 'admin', 'phase' => 4, 'badge' => 'cart'],
                ],
            ],
            [
                'label' => 'Catalogue',
                'items' => [
                    ['key' => 'admin.products', 'label' => 'Products', 'path' => 'admin/products', 'icon' => 'bx-package', 'guard' => 'admin', 'phase' => 2],
                    ['key' => 'admin.packages', 'label' => 'Packages', 'path' => 'admin/packages', 'icon' => 'bx-box', 'guard' => 'admin', 'phase' => 3],
                ],
            ],
            [
                'label' => 'Commerce',
                'items' => [
                    ['key' => 'admin.orders', 'label' => 'Orders', 'path' => 'admin/orders', 'icon' => 'bx-receipt', 'guard' => 'admin', 'phase' => 6],
                    ['key' => 'admin.members', 'label' => 'Members', 'path' => 'admin/members', 'icon' => 'bx-group', 'guard' => 'admin', 'phase' => 9],
                    ['key' => 'admin.activations', 'label' => 'Activations', 'path' => 'admin/activations', 'icon' => 'bx-check-shield', 'guard' => 'admin', 'phase' => 9],
                    ['key' => 'admin.codes', 'label' => 'Registration codes', 'path' => 'admin/codes', 'icon' => 'bx-key', 'guard' => 'admin', 'phase' => 9],
                ],
            ],
            [
                'label' => 'Finance',
                'items' => [
                    ['key' => 'admin.commissions', 'label' => 'Commissions', 'path' => 'admin/commissions', 'icon' => 'bx-line-chart', 'guard' => 'admin', 'phase' => 10],
                    ['key' => 'admin.wallets', 'label' => 'E-wallet monitor', 'path' => 'admin/wallets', 'icon' => 'bx-wallet', 'guard' => 'admin', 'phase' => 5],
                    /* Admins may open their own statement; the page lives at
                     * /wallet and renders inside the back-office layout for
                     * them (same pattern as admin.cart, Phase 4). */
                    ['key' => 'admin.wallet', 'label' => 'My e-wallet', 'path' => 'wallet', 'icon' => 'bx-credit-card', 'guard' => 'admin', 'phase' => 5],
                    ['key' => 'admin.payouts', 'label' => 'Payouts', 'path' => 'admin/payouts', 'icon' => 'bx-money-withdraw', 'guard' => 'admin', 'phase' => 13],
                ],
            ],
            [
                'label' => 'System',
                'items' => [
                    ['key' => 'admin.settings', 'label' => 'Settings', 'path' => 'admin/settings', 'icon' => 'bx-cog', 'guard' => 'admin', 'phase' => 7],
                    ['key' => 'admin.slogin', 'label' => 'S-Login console', 'path' => 'slogin', 'icon' => 'bx-shield-quarter', 'guard' => 'superadmin', 'phase' => 14],
                    /* The top-bar identity row opens this page; admins get the
                     * back-office layout (same pattern as admin.cart / admin.wallet). */
                    ['key' => 'admin.profile', 'label' => 'My profile', 'path' => 'profile', 'icon' => 'bx-user-circle', 'guard' => 'admin', 'phase' => 6],
                    ['key' => 'admin.password', 'label' => 'Change password', 'path' => 'profile/password', 'icon' => 'bx-lock-alt', 'guard' => 'admin', 'phase' => 1],
                    ['key' => 'logout', 'label' => 'Sign out', 'path' => 'logout', 'icon' => 'bx-power-off', 'guard' => 'member', 'type' => 'post'],
                ],
            ],
        ],
    ],
];
