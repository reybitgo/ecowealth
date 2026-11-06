<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — reference route inventory (plan §2.5)
 *
 *  The reference system (dmp/cart-system/index.php) dispatches 98
 *  `?page=` values. `pages` below is the port's coverage checklist:
 *  every reference page appears exactly once, with
 *
 *    disposition  port  — built in the target app
 *                 defer — designed for, not built in this scope
 *                 drop  — deliberately not ported
 *    phase        the phase that ships it (plan §7)
 *    targets      what the page becomes:
 *                   ['ROUTE', 'GET',  '/path']   → app/routes.php
 *                   ['ROUTE', 'POST', '/path']   → app/routes.php
 *                   ['PAGE',  'GET',  'login']   → public/login.php
 *                   ['CLI',   'CLI',  'bin/x.php']
 *
 *  `extensions` lists routes the port adds that have no reference
 *  page of their own (Appendix A). Keep it in sync with routes.php.
 *
 *  tests/Cases/ParityTest.php (P1-T12) fails when a page marked
 *  *Port* with a phase at or below APP_PHASE has no matching route,
 *  when a not-yet-due route is registered, or when the inventory
 *  loses/gains a page or a duplicate.
 *
 *  Counts: 98 reference pages = 69 port · 6 defer · 23 drop.
 *  Two per-page refinements against §2.5's group tally:
 *    • auto_select_upline is dropped (binary placement); the rest
 *      of the register group is ported — §2.5 notes the binary
 *      upline AJAX is not ported.
 *    • admin_shop_expire is ported as bin/shop_expire.php (CLI),
 *      so the returns/refund group leaves 3 deferred pages, not 4.
 *    • admin_order_hold / admin_order_resume are inventoried in the
 *      build but deferred to Phase 8: the 10-status order machine in
 *      §4.4 has no `on_hold` state. See docs/phase-log.md (Phase 7).
 * ============================================================== */

$route = static fn (string $method, string $path): array => ['ROUTE', strtoupper($method), $path];
$page  = static fn (string $name): array => ['PAGE', 'GET', $name];
$cli   = static fn (string $path): array => ['CLI', 'CLI', $path];

$port = static fn (string $name, int $phase, array $targets, string $note = ''): array => [
    'page'        => $name,
    'disposition' => 'port',
    'phase'       => $phase,
    'targets'     => $targets,
    'note'        => $note,
];

$defer = static fn (string $name, string $note): array => [
    'page'        => $name,
    'disposition' => 'defer',
    'phase'       => null,
    'targets'     => [],
    'note'        => $note,
];

$drop = static fn (string $name, string $note): array => [
    'page'        => $name,
    'disposition' => 'drop',
    'phase'       => null,
    'targets'     => [],
    'note'        => $note,
];

$extension = static fn (string $method, string $path, int $phase, string $note = ''): array => [
    'method' => strtoupper($method),
    'path'   => $path,
    'phase'  => $phase,
    'note'   => $note,
];

return [
    'pages' => [
        /* ---------- Auth: login / logout (kept standalone) ------------ */
        $port('login', 1, [$page('login')], 'Standalone login page; GET form + POST sign-in.'),
        $port('do_login', 1, [$page('login')], 'Handled by public/login.php POST.'),
        $port('logout', 1, [$page('logout')], 'Standalone; POST + CSRF only.'),

        /* ---------- Registration (form now, full flow in Phase 9) ----- */
        $port('register', 1, [$page('register')], 'Standalone page; the four registration modes land in Phase 9.'),
        $port('do_register', 1, [$page('register')], 'Handled by public/register.php POST.'),
        $port('validate_code', 9, [$route('POST', '/register/validate-code')], 'JSON endpoint.'),
        $port('check_username', 9, [$route('POST', '/register/check-username')], 'JSON endpoint.'),
        $port('check_upline', 9, [$route('POST', '/register/check-sponsor')], 'Re-specified as a sponsor check (no binary upline).'),
        $drop('auto_select_upline', 'Binary placement helper — no binary tree in V3.0.'),
        $port('get_packages', 9, [$route('GET', '/register/packages')], 'JSON package list for the registration form.'),

        /* ---------- Super-login --------------------------------------- */
        $port('slogin', 14, [$route('GET', '/slogin')], 'Superadmin console.'),
        $port('do_slogin', 14, [$route('POST', '/slogin')], 'Starts an impersonation session.'),

        /* ---------- Dashboard / profile --------------------------------
         * `/dashboard` stays a standalone page this phase and is re-routed
         * in Phase 9, when it gains wallet/commission data (plan §7 Phase 1). */
        $port('dashboard', 1, [$page('dashboard')], 'Standalone file until Phase 9, then GET /dashboard.'),
        $port('profile', 6, [$route('GET', '/profile')], 'Member profile + address book.'),
        $port('save_profile', 6, [$route('POST', '/profile')], 'Updates the member profile.'),

        /* ---------- Shop, cart, checkout, orders ---------------------- */
        $port('shop', 1, [$route('GET', '/shop')], 'Catalogue + search live in Phase 2; the product detail route is an extension.'),
        $port('cart', 4, [$route('GET', '/cart')], 'Persistent cart page.'),
        $port('add_to_cart', 4, [$route('POST', '/cart/add')], ''),
        $port('update_cart_item', 4, [$route('POST', '/cart/update')], ''),
        $port('remove_cart_item', 4, [$route('POST', '/cart/remove')], ''),
        $port('checkout', 6, [$route('GET', '/checkout')], ''),
        $port('place_order', 6, [$route('POST', '/checkout')], 'Idempotency key on the form.'),
        $port('shop_orders', 6, [$route('GET', '/orders')], ''),
        $port('shop_order', 6, [$route('GET', '/orders/{id}')], ''),
        $port('shop_invoice', 8, [$route('GET', '/orders/{id}/invoice')], 'Immutable invoice snapshot.'),
        $port('shop_submit_proof', 7, [$route('POST', '/orders/{id}/proof')], 'Manual payment evidence.'),
        $port('shop_cancel_order', 6, [$route('POST', '/orders/{id}/cancel')], 'Releases reserved stock.'),
        $port('shop_confirm_receipt', 8, [$route('POST', '/orders/{id}/confirm')], 'Delivered → completed.'),
        $port('shop_address_save', 6, [$route('POST', '/profile/address')], ''),
        $port('shop_address_delete', 6, [$route('POST', '/profile/address/{id}/delete')], ''),
        $port('shop_address_default', 6, [$route('POST', '/profile/address/{id}/default')], ''),

        /* ---------- Membership / earnings ----------------------------- */
        $port('activate', 9, [$route('GET', '/membership')], 'Membership page and activation form.'),
        $port('do_activate', 9, [$route('POST', '/membership/activate')], 'Wallet / code / manual funding.'),
        $port('earnings', 10, [$route('GET', '/earnings')], 'Commission statement.'),
        $port('genealogy', 9, [$route('GET', '/genealogy')], 'Sponsor chain only — no binary tree.'),

        /* ---------- E-wallet ------------------------------------------ */
        $port('ewallet_transfer', 12, [$route('GET', '/wallet/transfer')], "Reference guard was 'any' — the port guards it as member."),
        $port('do_ewallet_transfer', 12, [$route('POST', '/wallet/transfer')], "Reference guard was 'any' — the port guards it as member."),
        $port('payout', 13, [$route('GET', '/payouts')], ''),
        $port('request_payout', 13, [$route('POST', '/payouts/request')], 'Holds the amount at request time (D17).'),

        /* ---------- USDT gas fee routes (dropped) ---------------------- */
        $drop('update_usdt_gas', 'Member-writable money configuration — rates and gas fees are admin settings now (§2.3).'),
        $drop('update_usdt_bep20_gas', 'Member-writable money configuration — rates and gas fees are admin settings now (§2.3).'),

        /* ---------- Caps, DFI, reactivation (dropped) ------------------ */
        $drop('cap_status', 'Cap engine is out of scope.'),
        $drop('dfi_history', 'Daily fixed income is out of scope.'),
        $drop('reactivate', 'Reactivation is out of scope.'),
        $drop('do_reactivate', 'Reactivation is out of scope.'),
        $drop('api_cap_status', 'Cap engine is out of scope.'),
        $drop('api_dfi_status', 'Daily fixed income is out of scope.'),

        /* ---------- Package upgrades (deferred) ----------------------- */
        $defer('upgrade', 'Package upgrades are LATER (plan §1.3).'),
        $defer('do_upgrade', 'Package upgrades are LATER (plan §1.3).'),
        $defer('api_validate_upgrade_code', 'Package upgrades are LATER (plan §1.3).'),

        /* ---------- Binary tree / commission-deduct (dropped) --------- */
        $drop('api_binary_tree', 'No binary tree in V3.0.'),
        $drop('api_binary_uplines', 'No binary tree in V3.0.'),
        $drop('api_cd_status', 'Commission-deduct is out of scope.'),

        /* ---------- Admin: dashboard & members ------------------------- */
        $port('admin', 1, [$route('GET', '/admin')], 'Admin dashboard placeholder in Phase 1.'),
        $port('admin_users', 9, [$route('GET', '/admin/members')], ''),
        $port('admin_user_view', 9, [$route('GET', '/admin/members/{id}')], ''),
        $port('admin_toggle_user', 9, [$route('POST', '/admin/members/{id}/toggle')], ''),
        $port('admin_deactivate_user', 9, [$route('POST', '/admin/members/{id}/status')], ''),

        /* ---------- Admin: packages ----------------------------------- */
        $port('admin_packages', 3, [
            $route('GET', '/admin/packages'),
            $route('GET', '/admin/packages/new'),
            $route('GET', '/admin/packages/{id}/edit'),
            $route('POST', '/admin/packages/{id}/status'),
        ], 'Binary fields dropped; level matrix 2–10.'),
        $port('admin_save_package', 3, [$route('POST', '/admin/packages/save')], ''),

        /* ---------- Admin: registration codes ------------------------- */
        $port('admin_codes', 9, [$route('GET', '/admin/codes')], ''),
        $port('admin_gen_codes', 9, [$route('POST', '/admin/codes/generate')], 'Superadmin-gated volume (D21).'),
        $port('admin_export_codes', 9, [$route('GET', '/admin/codes/export')], 'CSV download.'),

        /* ---------- Admin: payouts ------------------------------------ */
        $port('admin_payouts', 13, [$route('GET', '/admin/payouts')], ''),
        $port('admin_payout_action', 13, [
            $route('POST', '/admin/payouts/{id}/approve'),
            $route('POST', '/admin/payouts/{id}/reject'),
            $route('POST', '/admin/payouts/{id}/complete'),
        ], 'Reference had one action endpoint; the port splits it per state.'),

        /* ---------- Admin: settings & password ------------------------ */
        $port('admin_settings', 7, [$route('GET', '/admin/settings')], 'Payment accounts + windows in Phase 7; the full settings page expands in Phase 15.'),
        $port('admin_save_settings', 7, [$route('POST', '/admin/settings')], 'Audited save (settings.update).'),
        $port('admin_change_password', 1, [
            $route('GET', '/profile/password'),
            $route('POST', '/profile/password'),
        ], 'Member changes their own password.'),

        /* ---------- Reference maintenance / caps / VIP (dropped) ----- */
        $drop('admin_manual_reset', 'Midnight reset is binary-only.'),
        $drop('admin_cap_monitor', 'Cap engine is out of scope.'),
        $drop('admin_dfi', 'Daily fixed income is out of scope.'),
        $drop('admin_reactivations', 'Reactivation is out of scope.'),
        $drop('admin_reactivation_action', 'Reactivation is out of scope.'),
        $drop('admin_toggle_vip', 'VIP bypass belongs to the cap engine.'),
        $drop('admin_toggle_daily_cap', 'Cap engine is out of scope.'),

        /* ---------- Commission-deduct (dropped) ----------------------- */
        $drop('admin_assign_cd', 'Commission-deduct is out of scope.'),
        $drop('admin_complete_cd', 'Commission-deduct is out of scope.'),
        $drop('admin_cancel_cd', 'Commission-deduct is out of scope.'),
        $drop('admin_edit_cd_target', 'Commission-deduct is out of scope.'),

        /* ---------- Admin: e-wallet ---------------------------------- */
        $port('admin_ewallet_topup', 5, [$route('GET', '/admin/wallets/{id}')], 'Top-up form for one member.'),
        $port('do_admin_ewallet_topup', 5, [$route('POST', '/admin/wallets/{id}/credit')], 'Audited admin credit.'),
        $port('admin_ewallet_monitor', 5, [$route('GET', '/admin/wallets')], ''),

        /* ---------- Admin: products & orders -------------------------- */
        $port('admin_shop', 2, [
            $route('GET', '/admin/products'),
            $route('GET', '/admin/products/new'),
            $route('GET', '/admin/products/{id}/edit'),
        ], ''),
        $port('admin_product_status', 2, [$route('POST', '/admin/products/{id}/status')], ''),
        $port('admin_save_product', 2, [$route('POST', '/admin/products/save')], ''),
        $port('admin_delete_product', 2, [$route('POST', '/admin/products/{id}/delete')], ''),
        $port('admin_shop_orders', 6, [$route('GET', '/admin/orders')], ''),
        $port('admin_shop_order', 6, [$route('GET', '/admin/orders/{id}')], ''),
        $port('admin_order_transition', 7, [$route('POST', '/admin/orders/{id}/transition')], ''),
        $port('admin_order_hold', 8, [$route('POST', '/admin/orders/{id}/hold')], 'Deferred to Phase 8: §4.4 has no on_hold status in this build (phase log).'),
        $port('admin_order_resume', 8, [$route('POST', '/admin/orders/{id}/resume')], 'Deferred to Phase 8 with hold.'),
        $port('admin_order_proof', 7, [$route('GET', '/admin/proofs/{id}')], 'Streams the proof file — never a public URL.'),
        $port('admin_order_shipment', 8, [
            $route('POST', '/admin/orders/{id}/ship'),
            $route('POST', '/admin/orders/{id}/deliver'),
        ], 'Courier + tracking; deliver adds the shipment delivery stamp.'),
        $port('admin_order_invoice', 8, [$route('GET', '/admin/orders/{id}/invoice')], ''),

        /* ---------- Returns / refunds (deferred) ---------------------- */
        $defer('admin_order_reship', 'Reshipment / RTS is LATER.'),
        $defer('admin_order_refund', 'Refunds and credit notes are LATER.'),
        $defer('admin_order_refund_receipt', 'Refunds and credit notes are LATER.'),

        /* ---------- Cron: shop expiry --------------------------------- */
        $port('admin_shop_expire', 7, [
            $cli('bin/shop_expire.php'),
            ['PAGE', 'GET', 'cron/shop_expire'],
        ], 'The Phase 7 cron job: CLI runner + the guarded web entry (local/URL testing).'),
    ],

    /* ------------------------------------------------------------------
     *  Routes the port adds; no reference page of their own.
     *  phase = the phase that registers them.
     * ---------------------------------------------------------------- */
    'extensions' => [
        $extension('GET', '/', 1, 'App entry redirect — the marketing front door is the repo-root index.html (D23).'),
        $extension('GET', '/shop/{id}', 2, 'Product detail page (the reference rendered it inside the shop page).'),
        $extension('GET', '/packages', 3, 'Member package catalogue (the reference showed packages during activation).'),
        $extension('GET', '/cart/count', 4, 'Cart badge count for the offcanvas.'),
        $extension('GET', '/wallet', 5, 'Member e-wallet statement (no standalone reference page).'),
        $extension('POST', '/profile/payment', 6, 'Member payout accounts on the profile (the reference kept them on its profile page too).'),
        $extension('GET', '/admin/activations', 9, 'Activation review queue (reference handled it from the member list).'),
        $extension('POST', '/admin/activations/{id}/approve', 9, 'Approves an activation and pays commissions.'),
        $extension('POST', '/admin/activations/{id}/reject', 9, 'Rejects an activation.'),
        $extension('GET', '/admin/commissions', 10, 'Commission ledger (reference: admin dashboard widgets).'),
        $extension('POST', '/slogin/exit', 14, 'Ends the impersonation session from the member layout banner.'),
        $extension('GET', '/admin/impersonations', 14, 'Super-login audit trail.'),
        $extension('GET', '/admin/reports', 15, 'Reconciliation report.'),
    ],
];
