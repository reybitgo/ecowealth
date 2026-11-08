<?php
declare(strict_types=1);

/* ==============================================================
 *  RouterTest — matching, parameters, guards and the live table
 *  (plan §3.3, §3.4). Supports P1-T2/T4/T10/T12.
 * ============================================================== */

return [
    'Router matches a static route with its guard' => function (): void {
        $router = new Router([
            ['GET', '/shop', [ShopController::class, 'index'], 'member'],
        ]);

        $match = $router->match('GET', '/shop');

        assert_not_null($match);
        assert_same([ShopController::class, 'index'], $match['route']['handler']);
        assert_same('member', $match['route']['guard']);
        assert_same([], $match['params']);
    },

    'Router extracts numeric parameters' => function (): void {
        $router = new Router([
            ['GET',  '/orders/{id}',        ['OrderController', 'show'],   'member'],
            ['POST', '/orders/{id}/cancel', ['OrderController', 'cancel'], 'member'],
        ]);

        $match = $router->match('GET', '/orders/42');
        assert_not_null($match);
        assert_same(['id' => '42'], $match['params']);

        $cancel = $router->match('POST', '/orders/7/cancel');
        assert_not_null($cancel);
        assert_same(['id' => '7'], $cancel['params']);
    },

    'Router rejects non-numeric parameters' => function (): void {
        $router = new Router([['GET', '/orders/{id}', ['OrderController', 'show'], 'member']]);

        assert_null($router->match('GET', '/orders/abc'));
        assert_null($router->match('GET', '/orders/42/extra'));
        assert_null($router->match('GET', '/orders/'));
    },

    'Router requires the exact method' => function (): void {
        $router = new Router([['POST', '/cart/add', ['CartController', 'add'], 'member']]);

        assert_null($router->match('GET', '/cart/add'));
        assert_not_null($router->match('POST', '/cart/add'));
    },

    'Router treats HEAD as GET and tolerates a trailing slash' => function (): void {
        $router = new Router([['GET', '/shop', ['ShopController', 'index'], 'member']]);

        assert_not_null($router->match('HEAD', '/shop'));
        assert_not_null($router->match('GET', '/shop/'));
    },

    'Router keeps "/" distinct from every other path' => function (): void {
        $router = new Router([['GET', '/', ['HomeController', 'index'], 'any']]);

        assert_not_null($router->match('GET', '/'));
        assert_null($router->match('GET', '/shop'));
    },

    'Router refuses duplicate routes' => function (): void {
        assert_throws(
            static fn () => new Router([
                ['GET', '/shop', ['ShopController', 'index'], 'member'],
                ['GET', '/shop', ['ShopController', 'index'], 'member'],
            ]),
            InvalidArgumentException::class,
            'duplicate definitions must not compile'
        );
    },

    'Router refuses an unknown guard' => function (): void {
        assert_throws(
            static fn () => new Router([['GET', '/x', ['XController', 'index'], 'root']]),
            InvalidArgumentException::class
        );
    },

    'Router refuses a malformed row' => function (): void {
        assert_throws(
            static fn () => new Router([['GET', '/x']]),
            InvalidArgumentException::class
        );
    },

    'Live route table builds and contains the Phase 1–10 routes' => function (): void {
        $routes = require dirname(__DIR__, 2) . '/app/routes.php';
        $router = new Router($routes);

        $live = [];
        foreach ($router->routes() as $route) {
            $live[$route['method'] . ' ' . $route['path']] = $route['guard'];
        }

        assert_count(73, $live);
        assert_same('any', $live['GET /']);
        assert_same('member', $live['GET /shop']);
        assert_same('member', $live['GET /shop/{id}']);
        assert_same('admin', $live['GET /admin']);
        assert_same('admin', $live['GET /admin/products']);
        assert_same('admin', $live['GET /admin/products/new']);
        assert_same('admin', $live['GET /admin/products/{id}/edit']);
        assert_same('admin', $live['POST /admin/products/save']);
        assert_same('admin', $live['POST /admin/products/{id}/status']);
        assert_same('admin', $live['POST /admin/products/{id}/delete']);
        assert_same('member', $live['GET /packages']);
        assert_same('admin', $live['GET /admin/packages']);
        assert_same('admin', $live['GET /admin/packages/new']);
        assert_same('admin', $live['GET /admin/packages/{id}/edit']);
        assert_same('admin', $live['POST /admin/packages/save']);
        assert_same('admin', $live['POST /admin/packages/{id}/status']);
        assert_same('member', $live['GET /profile/password']);
        assert_same('member', $live['POST /profile/password']);
        assert_same('member', $live['GET /cart']);
        assert_same('member', $live['GET /cart/count']);
        assert_same('member', $live['POST /cart/add']);
        assert_same('member', $live['POST /cart/update']);
        assert_same('member', $live['POST /cart/remove']);
        assert_same('member', $live['GET /wallet']);
        assert_same('admin', $live['GET /admin/wallets']);
        assert_same('admin', $live['GET /admin/wallets/{id}']);
        assert_same('admin', $live['POST /admin/wallets/{id}/credit']);
        assert_same('member', $live['GET /profile']);
        assert_same('member', $live['POST /profile']);
        assert_same('member', $live['POST /profile/payment']);
        assert_same('member', $live['POST /profile/address']);
        assert_same('member', $live['POST /profile/address/{id}/delete']);
        assert_same('member', $live['POST /profile/address/{id}/default']);
        assert_same('member', $live['GET /checkout']);
        assert_same('member', $live['POST /checkout']);
        assert_same('member', $live['GET /orders']);
        assert_same('member', $live['GET /orders/{id}']);
        assert_same('member', $live['POST /orders/{id}/cancel']);
        assert_same('admin', $live['GET /admin/orders']);
        assert_same('admin', $live['GET /admin/orders/{id}']);
        assert_same('member', $live['POST /orders/{id}/proof']);
        assert_same('admin', $live['GET /admin/proofs/{id}']);
        assert_same('admin', $live['POST /admin/orders/{id}/transition']);
        assert_same('admin', $live['GET /admin/settings']);
        assert_same('admin', $live['POST /admin/settings']);
        assert_same('member', $live['GET /orders/{id}/invoice']);
        assert_same('member', $live['POST /orders/{id}/confirm']);
        assert_same('admin', $live['POST /admin/orders/{id}/ship']);
        assert_same('admin', $live['POST /admin/orders/{id}/deliver']);
        assert_same('admin', $live['GET /admin/orders/{id}/invoice']);

        /* Phase 9 — registration, membership, codes and activations. */
        assert_same('any', $live['POST /register/validate-code']);
        assert_same('any', $live['POST /register/check-username']);
        assert_same('any', $live['POST /register/check-sponsor']);
        assert_same('any', $live['GET /register/packages']);
        assert_same('member', $live['GET /dashboard']);
        assert_same('member', $live['GET /membership']);
        assert_same('member', $live['POST /membership/activate']);
        assert_same('member', $live['GET /genealogy']);
        assert_same('admin', $live['GET /admin/members']);
        assert_same('admin', $live['GET /admin/members/{id}']);
        assert_same('admin', $live['POST /admin/members/{id}/status']);
        assert_same('admin', $live['POST /admin/members/{id}/toggle']);
        assert_same('admin', $live['GET /admin/codes']);
        assert_same('admin', $live['POST /admin/codes/generate']);
        assert_same('admin', $live['GET /admin/codes/export']);
        assert_same('admin', $live['GET /admin/activations']);
        assert_same('admin', $live['POST /admin/activations/{id}/approve']);
        assert_same('admin', $live['POST /admin/activations/{id}/reject']);
        assert_same('admin', $live['GET /admin/activations/{id}/proof']);

        /* Phase 10 — the member earnings statement and the admin ledger. */
        assert_same('member', $live['GET /earnings']);
        assert_same('admin', $live['GET /admin/commissions']);

        /* Phase 11 — the two CSV exports. */
        assert_same('member', $live['GET /earnings/export']);
        assert_same('admin', $live['GET /admin/commissions/export']);
    },

    'Live route table resolves the Phase 1–10 URLs' => function (): void {
        $router = new Router(require dirname(__DIR__, 2) . '/app/routes.php');

        assert_same(HomeController::class, $router->match('GET', '/')['route']['handler'][0]);
        assert_same(ShopController::class, $router->match('GET', '/shop')['route']['handler'][0]);
        assert_same(ShopController::class, $router->match('GET', '/shop/3')['route']['handler'][0]);
        assert_same(PackageController::class, $router->match('GET', '/packages')['route']['handler'][0]);
        assert_same(CartController::class, $router->match('GET', '/cart')['route']['handler'][0]);
        assert_same(CartController::class, $router->match('GET', '/cart/count')['route']['handler'][0]);
        assert_same(CartController::class, $router->match('POST', '/cart/add')['route']['handler'][0]);
        assert_same(CartController::class, $router->match('POST', '/cart/update')['route']['handler'][0]);
        assert_same(CartController::class, $router->match('POST', '/cart/remove')['route']['handler'][0]);
        assert_same(WalletController::class, $router->match('GET', '/wallet')['route']['handler'][0]);
        assert_same(AdminWalletController::class, $router->match('GET', '/admin/wallets')['route']['handler'][0]);
        assert_same(AdminWalletController::class, $router->match('GET', '/admin/wallets/12')['route']['handler'][0]);
        assert_same(AdminWalletController::class, $router->match('POST', '/admin/wallets/12/credit')['route']['handler'][0]);
        assert_same(ProfileController::class, $router->match('GET', '/profile')['route']['handler'][0]);
        assert_same(ProfileController::class, $router->match('POST', '/profile/payment')['route']['handler'][0]);
        assert_same(ProfileController::class, $router->match('POST', '/profile/address')['route']['handler'][0]);
        assert_same(ProfileController::class, $router->match('POST', '/profile/address/4/default')['route']['handler'][0]);
        assert_same(CheckoutController::class, $router->match('GET', '/checkout')['route']['handler'][0]);
        assert_same(CheckoutController::class, $router->match('POST', '/checkout')['route']['handler'][0]);
        assert_same(OrderController::class, $router->match('GET', '/orders')['route']['handler'][0]);
        assert_same(OrderController::class, $router->match('GET', '/orders/8')['route']['handler'][0]);
        assert_same(OrderController::class, $router->match('POST', '/orders/8/cancel')['route']['handler'][0]);
        assert_same(AdminOrderController::class, $router->match('GET', '/admin/orders')['route']['handler'][0]);
        assert_same(AdminOrderController::class, $router->match('GET', '/admin/orders/8')['route']['handler'][0]);
        assert_same(
            AdminDashboardController::class,
            $router->match('GET', '/admin')['route']['handler'][0]
        );
        assert_same(
            AdminProductController::class,
            $router->match('GET', '/admin/products')['route']['handler'][0]
        );
        assert_same(
            AdminProductController::class,
            $router->match('GET', '/admin/products/9/edit')['route']['handler'][0]
        );
        assert_same(
            AdminProductController::class,
            $router->match('POST', '/admin/products/9/status')['route']['handler'][0]
        );
        assert_same(
            AdminPackageController::class,
            $router->match('GET', '/admin/packages')['route']['handler'][0]
        );
        assert_same(
            AdminPackageController::class,
            $router->match('GET', '/admin/packages/4/edit')['route']['handler'][0]
        );
        assert_same(
            AdminPackageController::class,
            $router->match('POST', '/admin/packages/4/status')['route']['handler'][0]
        );
        assert_same(
            ProfileController::class,
            $router->match('POST', '/profile/password')['route']['handler'][0]
        );
        assert_same(
            OrderController::class,
            $router->match('POST', '/orders/8/proof')['route']['handler'][0]
        );
        assert_same(
            AdminOrderController::class,
            $router->match('GET', '/admin/proofs/3')['route']['handler'][0]
        );
        assert_same(
            AdminOrderController::class,
            $router->match('POST', '/admin/orders/8/transition')['route']['handler'][0]
        );
        assert_same(
            AdminSettingsController::class,
            $router->match('GET', '/admin/settings')['route']['handler'][0]
        );
        assert_same(
            AdminSettingsController::class,
            $router->match('POST', '/admin/settings')['route']['handler'][0]
        );
        assert_same(
            OrderController::class,
            $router->match('POST', '/orders/8/confirm')['route']['handler'][0]
        );
        assert_same(
            OrderController::class,
            $router->match('GET', '/orders/8/invoice')['route']['handler'][0]
        );
        assert_same(
            AdminOrderController::class,
            $router->match('POST', '/admin/orders/8/ship')['route']['handler'][0]
        );
        assert_same(
            AdminOrderController::class,
            $router->match('POST', '/admin/orders/8/deliver')['route']['handler'][0]
        );
        assert_same(
            AdminOrderController::class,
            $router->match('GET', '/admin/orders/8/invoice')['route']['handler'][0]
        );

        /* Phase 9 handlers. */
        assert_same(
            RegistrationController::class,
            $router->match('POST', '/register/validate-code')['route']['handler'][0]
        );
        assert_same(
            RegistrationController::class,
            $router->match('POST', '/register/check-sponsor')['route']['handler'][0]
        );
        assert_same(
            RegistrationController::class,
            $router->match('GET', '/register/packages')['route']['handler'][0]
        );
        assert_same(
            DashboardController::class,
            $router->match('GET', '/dashboard')['route']['handler'][0]
        );
        assert_same(
            MembershipController::class,
            $router->match('GET', '/membership')['route']['handler'][0]
        );
        assert_same(
            MembershipController::class,
            $router->match('POST', '/membership/activate')['route']['handler'][0]
        );
        assert_same(
            GenealogyController::class,
            $router->match('GET', '/genealogy')['route']['handler'][0]
        );
        assert_same(
            AdminMemberController::class,
            $router->match('GET', '/admin/members')['route']['handler'][0]
        );
        assert_same(
            AdminMemberController::class,
            $router->match('GET', '/admin/members/42')['route']['handler'][0]
        );
        assert_same(
            AdminMemberController::class,
            $router->match('POST', '/admin/members/42/status')['route']['handler'][0]
        );
        assert_same(
            AdminCodeController::class,
            $router->match('GET', '/admin/codes')['route']['handler'][0]
        );
        assert_same(
            AdminCodeController::class,
            $router->match('POST', '/admin/codes/generate')['route']['handler'][0]
        );
        assert_same(
            AdminCodeController::class,
            $router->match('GET', '/admin/codes/export')['route']['handler'][0]
        );
        assert_same(
            AdminActivationController::class,
            $router->match('GET', '/admin/activations')['route']['handler'][0]
        );
        assert_same(
            AdminActivationController::class,
            $router->match('POST', '/admin/activations/7/approve')['route']['handler'][0]
        );
        assert_same(
            AdminActivationController::class,
            $router->match('GET', '/admin/activations/7/proof')['route']['handler'][0]
        );

        /* Phase 10 handlers. */
        assert_same(
            EarningsController::class,
            $router->match('GET', '/earnings')['route']['handler'][0]
        );
        assert_same(
            AdminCommissionController::class,
            $router->match('GET', '/admin/commissions')['route']['handler'][0]
        );

        /* Phase 11 handlers. */
        assert_same(
            EarningsController::class,
            $router->match('GET', '/earnings/export')['route']['handler'][0]
        );
        assert_same(
            AdminCommissionController::class,
            $router->match('GET', '/admin/commissions/export')['route']['handler'][0]
        );

        assert_null($router->match('GET', '/no-such-page'));
        assert_null($router->match('GET', '/admin/products/abc/edit'));
        assert_null($router->match('GET', '/admin/packages/abc/edit'));
        assert_null($router->match('GET', '/admin/orders/8/hold'), 'hold is deferred (no on_hold status)');
        assert_null($router->match('GET', '/admin/orders/8/resume'), 'resume is deferred with hold');
        assert_null($router->match('GET', '/admin/proofs/abc'));
        assert_null($router->match('GET', '/orders/8/invoice/extra'));

        /* Not due yet, and the binary-upline endpoints are gone for good. */
        assert_null($router->match('GET', '/payouts'));
        assert_null($router->match('POST', '/payouts/request'));
        assert_null($router->match('GET', '/slogin'));
        assert_null($router->match('POST', '/register/check-upline'));
        assert_null($router->match('POST', '/register/auto-select-upline'));
    },
];
