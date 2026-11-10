<?php
declare(strict_types=1);

/* ==============================================================
 *  EcoWealth — route table (plan §3.3, Appendix A)
 *
 *  One row per URL:
 *
 *      [METHOD, '/path/{id}', [Controller::class, 'method'], guard]
 *
 *  guard: any | guest | member | admin | superadmin
 *  `{id}` compiles to ([0-9]+) and is passed to the controller as
 *  an associative array of parameters.
 *
 *  Only routes whose feature has shipped belong here. Every row is
 *  mirrored in app/routes_inventory.php, which maps all 98 pages of
 *  the reference system (dmp/cart-system/index.php) to a target or
 *  an explicit drop/defer. tests/Cases/ParityTest.php fails when a
 *  page marked *Port* has no matching route (P1-T12).
 *
 *  Auth pages stay standalone files (public/login.php, register.php,
 *  logout.php) and do not appear in this table — see the inventory.
 * ============================================================== */

return [
    /* ---------- Phase 1 — core skeleton ------------------------------ */

    /* GET / is a redirect only: dashboard when signed in, login when not (D23). */
    ['GET',  '/',                 [HomeController::class, 'index'],            'any'],

    /* Members-only storefront (reference: shop). The catalogue and the
     * product detail page land in Phase 2; cart/checkout follow. */
    ['GET',  '/shop',             [ShopController::class, 'index'],            'member'],
    ['GET',  '/shop/{id}',        [ShopController::class, 'show'],             'member'],

    /* Back-office placeholder (reference: admin). */
    ['GET',  '/admin',            [AdminDashboardController::class, 'index'],  'admin'],

    /* Reference: admin_change_password → the member's own password. */
    ['GET',  '/profile/password', [ProfileController::class, 'password'],      'member'],
    ['POST', '/profile/password', [ProfileController::class, 'savePassword'],  'member'],

    /* ---------- Phase 2 — products & catalogue ----------------------- */

    /* Reference: admin_shop (list + form pages), admin_save_product,
     * admin_product_status, admin_delete_product. */
    ['GET',  '/admin/products',                  [AdminProductController::class, 'index'],  'admin'],
    ['GET',  '/admin/products/new',              [AdminProductController::class, 'create'], 'admin'],
    ['GET',  '/admin/products/{id}/edit',        [AdminProductController::class, 'edit'],   'admin'],
    ['POST', '/admin/products/save',             [AdminProductController::class, 'save'],   'admin'],
    ['POST', '/admin/products/{id}/status',      [AdminProductController::class, 'status'], 'admin'],
    ['POST', '/admin/products/{id}/delete',      [AdminProductController::class, 'delete'], 'admin'],

    /* ---------- Phase 3 — packages -----------------------------------
     * The member catalogue is a new page; the admin pages are the
     * reference's admin_packages / admin_save_package. */
    ['GET',  '/packages',                        [PackageController::class, 'index'],           'member'],
    ['GET',  '/admin/packages',                  [AdminPackageController::class, 'index'],      'admin'],
    ['GET',  '/admin/packages/new',              [AdminPackageController::class, 'create'],     'admin'],
    ['GET',  '/admin/packages/{id}/edit',        [AdminPackageController::class, 'edit'],       'admin'],
    ['POST', '/admin/packages/save',             [AdminPackageController::class, 'save'],       'admin'],
    ['POST', '/admin/packages/{id}/status',      [AdminPackageController::class, 'status'],     'admin'],

    /* ---------- Phase 4 — cart --------------------------------------- */
    /* Reference: cart, add_to_cart, update_cart_item, remove_cart_item.
     * `/cart/count` is the port's JSON badge/drawer endpoint (Appendix A). */
    ['GET',  '/cart',                            [CartController::class, 'index'],       'member'],
    ['GET',  '/cart/count',                      [CartController::class, 'count'],       'member'],
    ['POST', '/cart/add',                        [CartController::class, 'add'],         'member'],
    ['POST', '/cart/update',                     [CartController::class, 'update'],      'member'],
    ['POST', '/cart/remove',                     [CartController::class, 'remove'],      'member'],

    /* ---------- Phase 5 — e-wallet core ------------------------------
     * Reference: admin_ewallet_monitor (list), admin_ewallet_topup (the
     * per-member page + form), do_admin_ewallet_topup (the credit).
     * `/wallet` is the port's member statement (no standalone reference
     * page — the reference rendered it inside `dashboard`). */
    ['GET',  '/wallet',                          [WalletController::class, 'index'],          'member'],    // e-wallet statement
    ['GET',  '/admin/wallets',                   [AdminWalletController::class, 'index'],     'admin'],     // admin_ewallet_monitor
    ['GET',  '/admin/wallets/{id}',              [AdminWalletController::class, 'show'],      'admin'],     // admin_ewallet_topup
    ['POST', '/admin/wallets/{id}/credit',       [AdminWalletController::class, 'credit'],    'admin'],     // do_admin_ewallet_topup

    /* ---------- Phase 6 — checkout & orders --------------------------
     * Reference: checkout, place_order, shop_orders, shop_order,
     * shop_cancel_order, profile, save_profile, shop_address_save,
     * shop_address_delete, shop_address_default, admin_shop_orders,
     * admin_shop_order. */
    ['GET',  '/profile',                         [ProfileController::class, 'index'],         'member'],    // profile
    ['POST', '/profile',                         [ProfileController::class, 'save'],          'member'],    // save_profile
    ['POST', '/profile/payment',                 [ProfileController::class, 'savePayment'],   'member'],    // payout accounts
    ['POST', '/profile/address',                 [ProfileController::class, 'saveAddress'],   'member'],    // shop_address_save
    ['POST', '/profile/address/{id}/delete',     [ProfileController::class, 'deleteAddress'], 'member'],    // shop_address_delete
    ['POST', '/profile/address/{id}/default',    [ProfileController::class, 'defaultAddress'],'member'],    // shop_address_default
    ['GET',  '/checkout',                        [CheckoutController::class, 'index'],        'member'],    // checkout
    ['POST', '/checkout',                        [CheckoutController::class, 'place'],        'member'],    // place_order
    ['GET',  '/orders',                          [OrderController::class, 'index'],           'member'],    // shop_orders
    ['GET',  '/orders/{id}',                     [OrderController::class, 'show'],            'member'],    // shop_order
    ['POST', '/orders/{id}/cancel',              [OrderController::class, 'cancel'],          'member'],    // shop_cancel_order
    ['GET',  '/admin/orders',                    [AdminOrderController::class, 'index'],      'admin'],     // admin_shop_orders
    ['GET',  '/admin/orders/{id}',               [AdminOrderController::class, 'show'],       'admin'],     // admin_shop_order

    /* ---------- Phase 7 — manual payments and the order lifecycle ----
     * Reference: shop_submit_proof, admin_order_proof (the guarded
     * stream), admin_order_transition, admin_settings/admin_save_settings.
     *
     * `hold` / `resume` stay out of the build: the 10-status order
     * machine in plan §4.4 has no `on_hold` state, so the two reference
     * pages are inventoried as deferred (see the phase log).
     */
    ['POST', '/orders/{id}/proof',               [OrderController::class, 'proof'],            'member'],   // shop_submit_proof
    ['GET',  '/admin/proofs/{id}',               [AdminOrderController::class, 'proof'],       'admin'],    // admin_order_proof
    ['POST', '/admin/orders/{id}/transition',    [AdminOrderController::class, 'transition'],  'admin'],    // admin_order_transition
    ['GET',  '/admin/settings',                  [AdminSettingsController::class, 'index'],    'admin'],    // admin_settings
    ['POST', '/admin/settings',                  [AdminSettingsController::class, 'save'],     'admin'],    // admin_save_settings

    /* ---------- Phase 8 — fulfilment & invoices ---------------------
     * Reference: shop_confirm_receipt, shop_invoice, admin_order_shipment,
     * admin_order_invoice. Pack / ready-to-ship reuse the Phase 7
     * transition endpoint (`to=packing` / `to=ready_to_ship`), so the
     * order desk keeps one guarded action route. */
    ['GET',  '/orders/{id}/invoice',             [OrderController::class, 'invoice'],          'member'],   // shop_invoice
    ['POST', '/orders/{id}/confirm',             [OrderController::class, 'confirm'],          'member'],   // shop_confirm_receipt
    ['POST', '/admin/orders/{id}/ship',          [AdminOrderController::class, 'ship'],        'admin'],    // admin_order_shipment
    ['POST', '/admin/orders/{id}/deliver',       [AdminOrderController::class, 'deliver'],     'admin'],    // admin_order_shipment
    ['GET',  '/admin/orders/{id}/invoice',       [AdminOrderController::class, 'invoice'],     'admin'],    // admin_order_invoice

    /* ---------- Phase 9 — registration, codes, membership -----------
     * Reference: register/do_register stay standalone files; the live
     * validation endpoints below are the port's `validate_code`,
     * `check_username`, `check_upline` (re-specified as a sponsor
     * check), `get_packages`, `activate`, `do_activate`, `genealogy`,
     * `dashboard`, `admin_users`, `admin_user_view`,
     * `admin_toggle_user`, `admin_deactivate_user`, `admin_codes`,
     * `admin_gen_codes`, `admin_export_codes`.
     *
     * `/admin/activations` (+approve/reject/proof) is the port's
     * review queue — the reference handled activation from the member
     * list, so Appendix A lists it as an extension. */
    ['POST', '/register/validate-code',          [RegistrationController::class, 'validateCode'],   'any'],   // validate_code
    ['POST', '/register/check-username',         [RegistrationController::class, 'checkUsername'],  'any'],   // check_username
    ['POST', '/register/check-sponsor',          [RegistrationController::class, 'checkSponsor'],   'any'],   // check_upline (re-specified)
    ['GET',  '/register/packages',               [RegistrationController::class, 'packages'],       'any'],   // get_packages
    ['GET',  '/dashboard',                       [DashboardController::class, 'index'],             'member'], // dashboard (was standalone)
    ['GET',  '/membership',                      [MembershipController::class, 'index'],            'member'], // activate
    ['POST', '/membership/activate',             [MembershipController::class, 'activate'],         'member'], // do_activate
    ['GET',  '/genealogy',                       [GenealogyController::class, 'index'],             'member'], // genealogy
    ['GET',  '/admin/members',                   [AdminMemberController::class, 'index'],           'admin'],  // admin_users
    ['GET',  '/admin/members/{id}',              [AdminMemberController::class, 'show'],            'admin'],  // admin_user_view
    ['POST', '/admin/members/{id}/status',       [AdminMemberController::class, 'status'],          'admin'],  // admin_deactivate_user
    ['POST', '/admin/members/{id}/toggle',       [AdminMemberController::class, 'toggle'],          'admin'],  // admin_toggle_user
    ['GET',  '/admin/codes',                     [AdminCodeController::class, 'index'],             'admin'],  // admin_codes
    ['POST', '/admin/codes/generate',            [AdminCodeController::class, 'generate'],          'admin'],  // admin_gen_codes
    ['GET',  '/admin/codes/export',              [AdminCodeController::class, 'export'],            'admin'],  // admin_export_codes
    ['GET',  '/admin/activations',               [AdminActivationController::class, 'index'],       'admin'],  // extension
    ['POST', '/admin/activations/{id}/approve',  [AdminActivationController::class, 'approve'],     'admin'],  // extension
    ['POST', '/admin/activations/{id}/reject',   [AdminActivationController::class, 'reject'],      'admin'],  // extension
    ['GET',  '/admin/activations/{id}/proof',    [AdminActivationController::class, 'proof'],       'admin'],  // extension

    /* ---------- Phase 10 — direct referral commissions --------------
     * Reference: `earnings` (the member's commission statement). The
     * admin ledger is a port extension — the reference exposed
     * commissions only as dashboard widgets. */
    ['GET',  '/earnings',                        [EarningsController::class, 'index'],        'member'],   // earnings
    ['GET',  '/admin/commissions',               [AdminCommissionController::class, 'index'], 'admin'],    // extension

    /* ---------- Phase 11 — indirect referral commissions ------------
     * The level breakdown is rendered on `/earnings` and the admin
     * ledger already filters by type/level; the two CSV routes are the
     * phase's "Unilevel statement" export. */
    ['GET',  '/earnings/export',                 [EarningsController::class, 'export'],         'member'],   // extension: unilevel CSV
    ['GET',  '/admin/commissions/export',        [AdminCommissionController::class, 'export'],  'admin'],    // extension: ledger CSV

    /* ---------- Phase 12 — wallet transfers -------------------------
     * Reference: ewallet_transfer, do_ewallet_transfer. Both pages were
     * unguarded in the reference; here the `member` guard admits members
     * and staff (plan §3.4), and the transfer itself needs the sender's
     * password. The admin monitor tabs live on the existing
     * GET /admin/wallets (transfers / top-ups / ledger). */
    ['GET',  '/wallet/transfer',                 [TransferController::class, 'index'],        'member'],    // ewallet_transfer
    ['POST', '/wallet/transfer',                 [TransferController::class, 'send'],         'member'],    // do_ewallet_transfer

    /* ---------- Phase 13 — payouts ----------------------------------
     * Reference: payout, request_payout, admin_payouts, admin_payout_action
     * (the reference's one action page is three explicit routes here, so
     * each decision has its own guard-able endpoint).
     *
     * The funds are held when the member asks (D17), so `/payouts/request`
     * is a money mutation: the router's `member` guard plus the central
     * CSRF check apply, and PayoutService re-checks every rule under the
     * account lock. */
    ['GET',  '/payouts',                         [PayoutController::class, 'index'],          'member'],    // payout
    ['POST', '/payouts/request',                 [PayoutController::class, 'request'],        'member'],    // request_payout
    ['GET',  '/admin/payouts',                   [AdminPayoutController::class, 'index'],     'admin'],     // admin_payouts
    ['POST', '/admin/payouts/{id}/approve',      [AdminPayoutController::class, 'approve'],   'admin'],     // admin_payout_action
    ['POST', '/admin/payouts/{id}/reject',       [AdminPayoutController::class, 'reject'],    'admin'],     // admin_payout_action
    ['POST', '/admin/payouts/{id}/complete',     [AdminPayoutController::class, 'complete'],  'admin'],     // admin_payout_action

    /* ---------- Phase 14 — super-login ------------------------------
     * Reference: slogin, do_slogin (logout/exit handling lived in the
     * reference's Auth::endImpSession). The member session is a URL-token
     * session, so `POST /slogin/exit` is a `member` route: while
     * impersonating, the caller is the member. The audit list is the
     * plan's Appendix A extension. */
    ['GET',  '/slogin',                          [SLoginController::class, 'index'],          'superadmin'], // slogin
    ['POST', '/slogin',                          [SLoginController::class, 'start'],          'superadmin'], // do_slogin
    ['POST', '/slogin/exit',                     [SLoginController::class, 'stop'],           'member'],     // reference: endImpSession
    ['GET',  '/admin/impersonations',            [SLoginController::class, 'audit'],          'admin'],      // extension: audit list

    /* ---------- Phase 15 — settings & reports -----------------------
    ['GET',  '/admin/settings',                  [AdminSettingsController::class, 'index'],   'admin'],     // admin_settings
    ['POST', '/admin/settings',                  [AdminSettingsController::class, 'save'],    'admin'],     // admin_save_settings
    ----------------------------------------------------------------- */
];
