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
    ['GET',  '/packages',                        [PackageController::class, 'index'],          'member'],    // packages (public cards)
    ['GET',  '/admin/packages',                  [AdminPackageController::class, 'index'],     'admin'],     // admin_packages
    ['GET',  '/admin/packages/new',              [AdminPackageController::class, 'create'],    'admin'],     // admin_packages
    ['GET',  '/admin/packages/{id}/edit',        [AdminPackageController::class, 'edit'],      'admin'],     // admin_packages
    ['POST', '/admin/packages/save',             [AdminPackageController::class, 'save'],      'admin'],     // admin_save_package
    ['POST', '/admin/packages/{id}/status',      [AdminPackageController::class, 'status'],    'admin'],     // admin_packages
    ----------------------------------------------------------------- */

    /* ---------- Phase 4 — cart ---------------------------------------
    ['GET',  '/cart',                            [CartController::class, 'index'],       'member'],          // cart
    ['GET',  '/cart/count',                      [CartController::class, 'count'],       'member'],          // cart
    ['POST', '/cart/add',                        [CartController::class, 'add'],         'member'],          // add_to_cart
    ['POST', '/cart/update',                     [CartController::class, 'update'],      'member'],          // update_cart_item
    ['POST', '/cart/remove',                     [CartController::class, 'remove'],      'member'],          // remove_cart_item
    ----------------------------------------------------------------- */

    /* ---------- Phase 5 — e-wallet core ------------------------------
    ['GET',  '/wallet',                          [WalletController::class, 'index'],          'member'],    // e-wallet statement
    ['GET',  '/admin/wallets',                   [AdminWalletController::class, 'index'],     'admin'],     // admin_ewallet_monitor
    ['GET',  '/admin/wallets/{id}',              [AdminWalletController::class, 'show'],      'admin'],     // admin_ewallet_topup
    ['POST', '/admin/wallets/{id}/credit',       [AdminWalletController::class, 'credit'],    'admin'],     // do_admin_ewallet_topup
    ----------------------------------------------------------------- */

    /* ---------- Phase 6 — checkout & orders --------------------------
    ['GET',  '/profile',                         [ProfileController::class, 'index'],         'member'],    // profile
    ['POST', '/profile',                         [ProfileController::class, 'save'],          'member'],    // save_profile
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
    ----------------------------------------------------------------- */

    /* ---------- Phase 7 — manual payments ---------------------------
    ['POST', '/orders/{id}/proof',               [OrderController::class, 'proof'],            'member'],   // shop_submit_proof
    ['GET',  '/admin/proofs/{id}',               [AdminOrderController::class, 'proof'],       'admin'],    // admin_order_proof
    ['POST', '/admin/orders/{id}/transition',    [AdminOrderController::class, 'transition'],  'admin'],    // admin_order_transition
    ['POST', '/admin/orders/{id}/hold',          [AdminOrderController::class, 'hold'],        'admin'],    // admin_order_hold
    ['POST', '/admin/orders/{id}/resume',        [AdminOrderController::class, 'resume'],      'admin'],    // admin_order_resume
    ----------------------------------------------------------------- */

    /* ---------- Phase 8 — fulfilment & invoices ---------------------
    ['GET',  '/orders/{id}/invoice',             [OrderController::class, 'invoice'],          'member'],   // shop_invoice
    ['POST', '/orders/{id}/confirm',             [OrderController::class, 'confirm'],          'member'],   // shop_confirm_receipt
    ['POST', '/admin/orders/{id}/ship',          [AdminOrderController::class, 'ship'],        'admin'],    // admin_order_shipment
    ['POST', '/admin/orders/{id}/deliver',       [AdminOrderController::class, 'deliver'],     'admin'],    // admin_order_shipment
    ['GET',  '/admin/orders/{id}/invoice',       [AdminOrderController::class, 'invoice'],     'admin'],    // admin_order_invoice
    ----------------------------------------------------------------- */

    /* ---------- Phase 9 — registration, codes, membership -----------
    ['POST', '/register/validate-code',          [RegistrationController::class, 'validateCode'],  'any'],  // validate_code
    ['POST', '/register/check-username',         [RegistrationController::class, 'checkUsername'], 'any'],  // check_username
    ['POST', '/register/check-sponsor',          [RegistrationController::class, 'checkSponsor'],  'any'],  // check_upline (re-specified)
    ['GET',  '/register/packages',               [RegistrationController::class, 'packages'],      'any'],  // get_packages
    ['GET',  '/membership',                      [MembershipController::class, 'index'],      'member'],    // activate
    ['POST', '/membership/activate',             [MembershipController::class, 'activate'],   'member'],    // do_activate
    ['GET',  '/genealogy',                       [GenealogyController::class, 'index'],       'member'],    // genealogy
    ['GET',  '/dashboard',                       [DashboardController::class, 'index'],       'member'],    // dashboard (was a standalone page)
    ['GET',  '/admin/members',                   [AdminMemberController::class, 'index'],     'admin'],     // admin_users
    ['GET',  '/admin/members/{id}',              [AdminMemberController::class, 'show'],      'admin'],     // admin_user_view
    ['POST', '/admin/members/{id}/status',       [AdminMemberController::class, 'status'],    'admin'],     // admin_deactivate_user
    ['POST', '/admin/members/{id}/toggle',       [AdminMemberController::class, 'toggle'],    'admin'],     // admin_toggle_user
    ['GET',  '/admin/codes',                     [AdminCodeController::class, 'index'],       'admin'],     // admin_codes
    ['POST', '/admin/codes/generate',            [AdminCodeController::class, 'generate'],    'admin'],     // admin_gen_codes
    ['GET',  '/admin/codes/export',              [AdminCodeController::class, 'export'],      'admin'],     // admin_export_codes
    ----------------------------------------------------------------- */

    /* ---------- Phase 10 — direct referral --------------------------
    ['GET',  '/earnings',                        [EarningsController::class, 'index'],        'member'],    // earnings
    ['GET',  '/admin/commissions',               [AdminCommissionController::class, 'index'], 'admin'],     // commissions
    ----------------------------------------------------------------- */

    /* ---------- Phase 12 — wallet transfers -------------------------
    ['GET',  '/wallet/transfer',                 [TransferController::class, 'index'],        'member'],    // ewallet_transfer (guard raised from 'any')
    ['POST', '/wallet/transfer',                 [TransferController::class, 'send'],         'member'],    // do_ewallet_transfer
    ----------------------------------------------------------------- */

    /* ---------- Phase 13 — payouts ----------------------------------
    ['GET',  '/payouts',                         [PayoutController::class, 'index'],          'member'],    // payout
    ['POST', '/payouts/request',                 [PayoutController::class, 'request'],        'member'],    // request_payout
    ['GET',  '/admin/payouts',                   [AdminPayoutController::class, 'index'],     'admin'],     // admin_payouts
    ['POST', '/admin/payouts/{id}/approve',      [AdminPayoutController::class, 'approve'],   'admin'],     // admin_payout_action
    ['POST', '/admin/payouts/{id}/reject',       [AdminPayoutController::class, 'reject'],    'admin'],     // admin_payout_action
    ['POST', '/admin/payouts/{id}/complete',     [AdminPayoutController::class, 'complete'],  'admin'],     // admin_payout_action
    ----------------------------------------------------------------- */

    /* ---------- Phase 14 — super-login ------------------------------
    ['GET',  '/slogin',                          [SLoginController::class, 'index'],          'superadmin'], // slogin
    ['POST', '/slogin',                          [SLoginController::class, 'start'],          'superadmin'], // do_slogin
    ['POST', '/slogin/exit',                     [SLoginController::class, 'stop'],           'member'],     // slogin exit
    ----------------------------------------------------------------- */

    /* ---------- Phase 15 — settings & reports -----------------------
    ['GET',  '/admin/settings',                  [AdminSettingsController::class, 'index'],   'admin'],     // admin_settings
    ['POST', '/admin/settings',                  [AdminSettingsController::class, 'save'],    'admin'],     // admin_save_settings
    ----------------------------------------------------------------- */
];
