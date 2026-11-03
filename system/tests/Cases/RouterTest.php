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

    'Live route table builds and contains the Phase 1 routes' => function (): void {
        $routes = require dirname(__DIR__, 2) . '/app/routes.php';
        $router = new Router($routes);

        $live = [];
        foreach ($router->routes() as $route) {
            $live[$route['method'] . ' ' . $route['path']] = $route['guard'];
        }

        assert_count(5, $live);
        assert_same('any', $live['GET /']);
        assert_same('member', $live['GET /shop']);
        assert_same('admin', $live['GET /admin']);
        assert_same('member', $live['GET /profile/password']);
        assert_same('member', $live['POST /profile/password']);
    },

    'Live route table resolves the Phase 1 URLs' => function (): void {
        $router = new Router(require dirname(__DIR__, 2) . '/app/routes.php');

        assert_same(HomeController::class, $router->match('GET', '/')['route']['handler'][0]);
        assert_same(ShopController::class, $router->match('GET', '/shop')['route']['handler'][0]);
        assert_same(
            AdminDashboardController::class,
            $router->match('GET', '/admin')['route']['handler'][0]
        );
        assert_same(
            ProfileController::class,
            $router->match('POST', '/profile/password')['route']['handler'][0]
        );
        assert_null($router->match('GET', '/no-such-page'));
    },
];
