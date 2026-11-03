<?php

/**
 * @file   index.php
 * @brief  Front Controller for MLM Binary System (v2)
 * All HTTP requests route through here.
 */
?>
<?php

/**
 * MLM BINARY SYSTEM â€” Front Controller (v2)
 * All HTTP requests route through here.
 */

require_once 'config/db.php';
require_once 'core/helpers.php';
require_once 'core/Auth.php';
require_once 'core/Commission.php';

// NEW v2: Load cap engine and DFI services
require_once 'core/CapEngine.php';
require_once 'core/DailyFixedIncome.php';
require_once 'core/Reactivation.php';
require_once 'core/StockConflictException.php';

// Auto-load models and controllers
spl_autoload_register(function (string $class): void {
    foreach (['models/', 'controllers/'] as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Super-Login: an impersonated member tab carries a URL-token (?imp=<nonce>)
// that IS the PHP session id. No cookie session is used, so the superadmin's
// own cookie session (mlm_sess) is never touched. Sessions must be configured
// BEFORE session_start().
$impNonce = preg_match('/^[a-f0-9]{64}$/', $_GET['imp'] ?? '') ? $_GET['imp'] : '';
if ($impNonce !== '') {
    Auth::startImpSession($impNonce);
}
session_start();
if ($impNonce !== '') {
    Auth::validateImpSession();
}

// Maintenance mode
if (setting('maintenance_mode') === '1' && !Auth::isAdmin()) {
    $name = setting('site_name', APP_NAME);
    $base = rtrim(APP_URL, '/');
    $frontend = $base . '/frontend';
    http_response_code(503);
    header('Retry-After: 3600');
    die("<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>We'll Be Right Back â€” {$name}</title>
  <meta name='robots' content='noindex, nofollow'>
  <link rel='preconnect' href='https://fonts.googleapis.com'>
  <link href='https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
  <style>
    :root {
      --green-deep: #1a3a1e;
      --green-mid: #2d6a35;
      --gold: #d4a017;
      --gold-light: #f9e9b5;
      --cream: #faf7f0;
      --charcoal: #1c1c1c;
      --muted: #8a9a8c;
      --serif: 'Playfair Display', Georgia, serif;
      --sans: 'DM Sans', system-ui, sans-serif;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: var(--sans);
      color: #fff;
      background: var(--green-deep);
      -webkit-font-smoothing: antialiased;
    }
    .maintenance-hero {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 40px 20px;
      position: relative;
      overflow: hidden;
      background:
        linear-gradient(to bottom, rgba(26,58,30,0.88) 0%, rgba(26,58,30,0.75) 50%, rgba(26,58,30,0.92) 100%),
        url('{$frontend}/hero-bg.jpg') center/cover no-repeat;
    }
    .maintenance-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url(\"data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E\");
      opacity: 0.4;
      pointer-events: none;
    }
    .maintenance-inner {
      position: relative;
      z-index: 2;
      max-width: 640px;
      animation: fadeUp 0.9s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
    }
    .logo-mark {
      margin-bottom: 2rem;
    }
    .logo-mark img {
      height: 44px;
      width: auto;
      filter: brightness(0) invert(1);
      opacity: 0.95;
    }
    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: rgba(212, 160, 23, 0.18);
      border: 1px solid rgba(212, 160, 23, 0.35);
      color: var(--gold-light);
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 1.8px;
      text-transform: uppercase;
      padding: 0.45rem 1.1rem;
      border-radius: 999px;
      margin-bottom: 1.75rem;
    }
    .eyebrow .pulse {
      width: 7px;
      height: 7px;
      background: var(--gold);
      border-radius: 50%;
      display: inline-block;
      animation: pulse 2s infinite ease-in-out;
    }
    h1 {
      font-family: var(--serif);
      font-size: clamp(2rem, 5vw, 3.4rem);
      font-weight: 700;
      line-height: 1.15;
      color: #fff;
      margin-bottom: 1.25rem;
      letter-spacing: -0.5px;
    }
    h1 span { color: var(--gold); }
    .lead {
      font-size: 1.05rem;
      color: rgba(255,255,255,0.82);
      line-height: 1.75;
      max-width: 520px;
      margin: 0 auto 2.5rem;
    }
    .features {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 0.75rem;
      margin-bottom: 2.5rem;
    }
    .feature-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.12);
      color: rgba(255,255,255,0.85);
      font-size: 0.78rem;
      font-weight: 500;
      padding: 0.5rem 1rem;
      border-radius: 999px;
      backdrop-filter: blur(4px);
    }
    .contact-box {
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 16px;
      padding: 1.5rem 2rem;
      backdrop-filter: blur(10px);
      max-width: 420px;
      margin: 0 auto;
    }
    .contact-box p {
      font-size: 0.82rem;
      color: rgba(255,255,255,0.6);
      line-height: 1.6;
      margin-bottom: 0.75rem;
    }
    .contact-box a {
      color: var(--gold);
      text-decoration: none;
      font-weight: 600;
    }
    .contact-box a:hover { text-decoration: underline; }
    .eta {
      margin-top: 1rem;
      font-size: 0.75rem;
      color: rgba(255,255,255,0.45);
      letter-spacing: 0.5px;
    }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(35px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes pulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.5; transform: scale(0.85); }
    }
    @media (max-width: 480px) {
      .contact-box { padding: 1.25rem 1.25rem; }
      .features { gap: 0.5rem; }
      .feature-chip { font-size: 0.72rem; padding: 0.4rem 0.8rem; }
    }
  </style>
</head>
<body>
  <section class='maintenance-hero'>
    <div class='maintenance-inner'>
      <div class='logo-mark'>
        <img src='{$frontend}/logo.png' alt='{$name} logo' onerror=\"this.style.display='none'\">
      </div>
      <div class='eyebrow'><span class='pulse'></span> System Upgrade in Progress</div>
      <h1>Something Better Is<br><span>On the Way</span></h1>
      <p class='lead'>
        We are rolling out improvements behind the scenes to make your {$name} experience faster, more secure, and more rewarding. The platform will be back online shortly. Thank you for your patience â€” great things are worth the wait.
      </p>
      <div class='features'>
        <span class='feature-chip'>ðŸŒ± Enhanced Dashboard</span>
        <span class='feature-chip'>âš¡ Faster Payouts</span>
        <span class='feature-chip'>ðŸ”’ Stronger Security</span>
        <span class='feature-chip'>ðŸ“Š Real-Time Tracking</span>
      </div>
      <div class='contact-box'>
        <p>If you have an urgent concern about your account, commissions, or withdrawals, our support team is still available:</p>
        <a href='mailto:support@altasfarm.com'>support@altasfarm.com</a>
        <div class='eta'>Expected to return shortly. Please check back in a few minutes.</div>
      </div>
    </div>
  </section>
</body>
</html>");
}

// Seat limit â€” block registration routes entirely when full
$page = $_GET['page'] ?? '';
if (in_array($page, ['register', 'do_register', 'validate_code'], true) && isSeatLimitReached()) {
    http_response_code(403);
    $name  = setting('site_name', APP_NAME);
    $limit = (int) setting('seat_limit', '1000');
    die("<!doctype html><html><head><meta charset='UTF-8'><title>Registration Closed â€” {$name}</title>
    <style>body{font-family:'DM Sans',system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f6fb;}
    .box{text-align:center;padding:48px 40px;max-width:420px;background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.06);}
    .emoji{font-size:3.5rem;margin-bottom:.5rem;}h1{font-size:1.5rem;margin-bottom:.5rem;color:#1a1a2e;}p{color:#6b7a99;line-height:1.6;margin-bottom:1.75rem;}
    .btn{display:inline-block;padding:.7rem 1.6rem;border-radius:10px;text-decoration:none;font-weight:600;font-size:.9rem;background:#3b6ff0;color:#fff;}
    </style></head><body><div class='box'><div class='emoji'>ðŸ”’</div><h1>Registration Closed</h1>
    <p>The member seat limit of <strong>" . number_format($limit) . "</strong> has been reached. No new accounts can be created at this time.</p>
    <a href='/?page=login' class='btn'>Sign In â†’</a></div></body></html>");
}

// Route table: page => [ControllerClass, method, role]
// role: 'guest' | 'member' | 'admin' | 'any'
$routes = [
    // â”€â”€ Auth â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    'login'              => ['AuthController',   'showLogin',       'guest'],
    'do_login'           => ['AuthController',   'doLogin',         'guest'],
    'register'           => ['AuthController',   'showRegister',    'any'],
    'do_register'        => ['AuthController',   'doRegister',      'any'],
    'validate_code'      => ['AuthController',   'ajaxValidateCode', 'any'],
    'get_packages'       => ['AuthController',   'ajaxGetPackages', 'member'],
    'check_username'     => ['AuthController',   'ajaxCheckUser',   'any'],
    'check_upline'       => ['AuthController',   'ajaxCheckUpline', 'any'],
    'auto_select_upline' => ['AuthController',   'ajaxAutoSelectUpline', 'any'],
    'logout'             => ['AuthController',   'logout',          'any'],

    // â”€â”€ Super-Login (superadmin â†’ any member) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    'slogin'             => ['AuthController',   'showSlogin',      'super'],
    'do_slogin'          => ['AuthController',   'doSlogin',        'super'],

    // â”€â”€ Member â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    'dashboard'          => ['MemberController', 'dashboard',       'member'],
    'profile'            => ['MemberController', 'profile',         'member'],
    'save_profile'       => ['MemberController', 'saveProfile',     'member'],
    'earnings'           => ['MemberController', 'earnings',        'member'],
    'genealogy'          => ['MemberController', 'genealogy',       'member'],
    'api_binary_tree'    => ['MemberController', 'apiBinaryTree',   'member'],
    'payout'             => ['MemberController', 'payout',          'member'],
    'request_payout'     => ['MemberController', 'requestPayout',   'member'],
    'update_usdt_gas'       => ['AdminController',  'updateUsdtGas',      'member'],
    'update_usdt_bep20_gas' => ['AdminController',  'updateUsdtBep20Gas', 'member'],

    // NEW v2: Member cap + DFI + reactivation pages
    'cap_status'         => ['MemberController', 'capStatus',       'member'],
    'dfi_history'        => ['MemberController', 'dfiHistory',        'member'],
    'reactivate'         => ['MemberController', 'reactivate',        'member'],
    'do_reactivate'      => ['MemberController', 'doReactivate',      'member'],
    'activate'           => ['MemberController', 'activate',          'member'],
    'do_activate'        => ['MemberController', 'doActivate',        'member'],
    'api_cap_status'     => ['MemberController', 'apiCapStatus',      'member'],
    'api_dfi_status'     => ['MemberController', 'apiDfiStatus',      'member'],

    // Package upgrade
    'upgrade'            => ['MemberController', 'showUpgrade',       'member'],
    'do_upgrade'         => ['MemberController', 'doUpgrade',         'member'],
    'api_validate_upgrade_code' => ['MemberController', 'ajaxValidateUpgradeCode', 'member'],
    'api_binary_uplines' => ['MemberController', 'ajaxBinaryUplines', 'member'],

    // â”€â”€ Admin â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    'admin'              => ['AdminController',  'dashboard',       'admin'],
    'admin_users'        => ['AdminController',  'users',           'admin'],
    'admin_user_view'    => ['AdminController',  'viewUser',        'admin'],
    'admin_toggle_user'     => ['AdminController',  'toggleUser',      'admin'],
    'admin_deactivate_user' => ['AdminController',  'deactivateUser',  'admin'],
    'admin_packages'     => ['AdminController',  'packages',        'admin'],
    'admin_save_package' => ['AdminController',  'savePackage',     'admin'],
    'admin_codes'        => ['AdminController',  'codes',           'admin'],
    'admin_gen_codes'    => ['AdminController',  'generateCodes',   'admin'],
    'admin_export_codes' => ['AdminController',  'exportCodes',     'admin'],
    'admin_payouts'      => ['AdminController',  'payouts',         'admin'],
    'admin_payout_action' => ['AdminController',  'payoutAction',    'admin'],
    'admin_settings'     => ['AdminController',  'settings',        'admin'],
    'admin_save_settings' => ['AdminController',  'saveSettings',    'admin'],
    'admin_manual_reset' => ['AdminController',  'manualReset',     'admin'],

    // NEW v2: Admin monitoring pages
    'admin_cap_monitor'  => ['AdminController',  'capMonitor',      'admin'],
    'admin_dfi'          => ['AdminController',  'dfiAdmin',        'admin'],
    'admin_reactivations'  => ['AdminController',  'reactivations',       'admin'],
    'admin_reactivation_action' => ['AdminController',  'reactivationAction',  'admin'],

    // VIP bypass toggles
    'admin_change_password'  => ['AdminController',  'changePassword',       'admin'],
    'admin_toggle_vip'       => ['AdminController',  'toggleVipBypass',      'admin'],
    'admin_toggle_daily_cap' => ['AdminController',  'toggleDailyCapBypass', 'admin'],

    // Commission-Deduct (CD) admin actions
    'admin_assign_cd'       => ['AdminController',  'assignCd',      'admin'],
    'admin_complete_cd'     => ['AdminController',  'completeCd',    'admin'],
    'admin_cancel_cd'       => ['AdminController',  'cancelCd',      'admin'],
    'admin_edit_cd_target'  => ['AdminController',  'editCdTarget',  'admin'],
    'api_cd_status'      => ['MemberController', 'apiCdStatus', 'member'],

    // E-Wallet Transfer & Top-Up
    'ewallet_transfer'       => ['MemberController', 'ewalletTransfer',     'any'],
    'do_ewallet_transfer'    => ['MemberController', 'doEwalletTransfer',   'any'],
    'admin_ewallet_topup'    => ['AdminController',  'ewalletTopUp',        'admin'],
    'do_admin_ewallet_topup' => ['AdminController',  'doEwalletTopUp',      'admin'],
    'admin_ewallet_monitor'  => ['AdminController',  'ewalletMonitor',      'admin'],
    // â”€â”€ Shop (physical storefront) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    'shop'                        => ['MemberController', 'shop',              'member'],
    'shop_orders'                 => ['MemberController', 'shopOrders',        'member'],
    'shop_order'                  => ['MemberController', 'shopOrder',         'member'],
    'shop_invoice'                => ['MemberController', 'shopInvoice',       'member'],
    'cart'                        => ['MemberController', 'cart',              'member'],
    'add_to_cart'                 => ['MemberController', 'addToCart',         'member'],
    'update_cart_item'            => ['MemberController', 'updateCartItem',    'member'],
    'remove_cart_item'            => ['MemberController', 'removeCartItem',    'member'],
    'checkout'                    => ['MemberController', 'checkout',          'member'],
    'place_order'                 => ['MemberController', 'placeOrder',        'member'],
    'shop_submit_proof'           => ['MemberController', 'submitShopProof',   'member'],
    'shop_cancel_order'           => ['MemberController', 'cancelShopOrder',   'member'],
    'shop_confirm_receipt'        => ['MemberController', 'confirmShopReceipt','member'],
    'shop_address_save'           => ['MemberController', 'saveAddress',       'member'],
    'shop_address_delete'         => ['MemberController', 'deleteAddress',     'member'],
    'shop_address_default'        => ['MemberController', 'setDefaultAddress', 'member'],
    // â”€â”€ Admin Shop â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    'admin_shop'                  => ['AdminController',  'shopProducts',      'admin'],
    'admin_product_status'         => ['AdminController',  'productStatus',     'admin'],
    'admin_save_product'          => ['AdminController',  'saveShopProduct',   'admin'],
    'admin_delete_product'        => ['AdminController',  'deleteShopProduct', 'admin'],
    'admin_shop_orders'           => ['AdminController',  'shopOrders',        'admin'],
    'admin_shop_order'            => ['AdminController',  'shopOrder',         'admin'],
    'admin_order_transition'      => ['AdminController',  'orderTransition',   'admin'],
    'admin_order_hold'            => ['AdminController',  'orderHold',         'admin'],
    'admin_order_resume'          => ['AdminController',  'orderResume',       'admin'],
    'admin_order_proof'           => ['AdminController',  'orderProofView',    'admin'],
    'admin_order_shipment'        => ['AdminController',  'orderShipment',     'admin'],
    'admin_order_reship'          => ['AdminController',  'orderReship',       'admin'],
    'admin_order_refund'          => ['AdminController',  'orderRefund',       'admin'],
    'admin_order_invoice'         => ['AdminController',  'orderInvoice',      'admin'],
    'admin_order_refund_receipt'  => ['AdminController',  'orderRefundReceipt','admin'],
    'admin_shop_expire'           => ['AdminController',  'shopExpire',        'admin'],
];

$page = $_GET['page'] ?? 'login';

// Fall back to login for unknown pages
$route = $routes[$page] ?? null;
if (!$route) {
    if (Auth::check()) {
        redirect(Auth::isAdmin() ? '/?page=admin' : '/?page=dashboard');
    }
    $route = $routes['login'];
}

[$ctrlClass, $method, $role] = $route;

// Auth guards
if ($role === 'guest' && Auth::check() && !in_array($page, ['register', 'do_register'])) {
    redirect(Auth::isAdmin() ? '/?page=admin' : '/?page=dashboard');
}
if ($role === 'member' && !Auth::check()) {
    flash('error', 'Please log in to continue.');
    redirect('/?page=login');
}
if ($role === 'admin' && !Auth::isAdmin()) {
    flash('error', 'Access denied.');
    redirect('/?page=login');
}
if ($role === 'super' && !Auth::isSuperadmin()) {
    flash('error', 'Access denied.');
    redirect('/?page=login');
}

// CSRF token seed
csrf_token();

// Dispatch
(new $ctrlClass())->$method();
