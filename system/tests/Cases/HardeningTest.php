<?php
declare(strict_types=1);

/* ==============================================================
 *  HardeningTest — the Phase 15 security and operations baseline
 *  (plan §3.7, §9.6; P15-T2, P15-T7, P15-T8)
 *
 *  Response headers and Apache behaviour are asserted over HTTP by
 *  tests/smoke.ps1; this file covers what a CLI test can prove: the
 *  throttle maths, the guards' wiring, the uploads directory policy,
 *  the error page's production mode and the config drift check.
 * ============================================================== */

return [
    'P15 the transfer throttle allows the limit then refuses' => function (): void {
        Throttle::clear('probe:throttle');

        assert_true(Throttle::hit('probe:throttle', 3, 900));
        assert_true(Throttle::hit('probe:throttle', 3, 900));
        assert_true(Throttle::hit('probe:throttle', 3, 900));
        assert_false(Throttle::hit('probe:throttle', 3, 900), 'the fourth attempt in the window is refused');
        assert_same(0, Throttle::remaining('probe:throttle', 3, 900));

        /* The refused attempt is not recorded, so a later attempt after
         * the window slides in is allowed again. */
        $_SESSION['_throttle']['probe:throttle'] = [time() - 901];
        assert_true(Throttle::hit('probe:throttle', 3, 900), 'an expired window is forgotten');

        Throttle::clear('probe:throttle');
        assert_same(3, Throttle::remaining('probe:throttle', 3, 900));

        /* Buckets are independent: one action cannot spend another's. */
        assert_true(Throttle::hit('probe:a', 1, 900));
        assert_true(Throttle::hit('probe:b', 1, 900));
        assert_false(Throttle::hit('probe:a', 1, 900));
        assert_false(Throttle::hit('probe:b', 1, 900));
    },

    'P15 the money forms are wired to the throttle' => function (): void {
        $root = dirname(__DIR__, 2);

        $wiring = [
            'app/Controllers/CheckoutController.php' => "Throttle::hit('checkout:'",
            'app/Controllers/OrderController.php'    => "Throttle::hit('proof:'",
            'app/Controllers/TransferController.php' => "Throttle::hit('transfer:'",
            'app/Controllers/PayoutController.php'   => "Throttle::hit('payout:'",
        ];

        foreach ($wiring as $file => $needle) {
            $source = (string) file_get_contents($root . '/' . $file);
            assert_contains($needle, $source, $file . ' must rate-limit its money form');
        }
    },

    'P15 the uploads directory cannot execute code' => function (): void {
        $root = dirname(__DIR__, 2) . '/public/uploads/';

        assert_true(is_dir($root . 'products'), 'the products folder exists');
        assert_true(is_dir($root . 'packages'), 'the packages folder exists');
        assert_true(is_dir($root . 'proofs'), 'the proofs folder exists');
        assert_true(is_file($root . '.htaccess'), 'the uploads policy is committed');

        $policy = (string) file_get_contents($root . '.htaccess');

        assert_contains('php_flag engine off', $policy);
        assert_contains('Require all denied', $policy);
        assert_contains('X-Content-Type-Options', $policy);
    },

    'P15 the shared security headers are sent from the bootstrap' => function (): void {
        $root     = dirname(__DIR__, 2);
        $bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');

        assert_contains('SecurityHeaders::send()', $bootstrap);

        $headers = (string) file_get_contents($root . '/app/Core/SecurityHeaders.php');

        foreach ([
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Content-Security-Policy',
            'Referrer-Policy',
            'Permissions-Policy',
            'Cross-Origin-Opener-Policy',
            'Strict-Transport-Security',
        ] as $header) {
            assert_contains($header, $headers, $header . ' is part of the shared policy');
        }

        /* HSTS only over TLS. */
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        assert_false(SecurityHeaders::isHttps());

        $_SERVER['HTTPS'] = 'on';
        assert_true(SecurityHeaders::isHttps());

        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);
        assert_true(SecurityHeaders::isHttps(), 'a TLS-terminating proxy counts');

        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    },

    'P15 production hides PHP errors and the 500 detail' => function (): void {
        $root = dirname(__DIR__, 2);

        $config = (string) file_get_contents($root . '/app/config.php');
        assert_contains("define('APP_DEBUG', APP_ENV !== 'production')", $config);

        $bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');
        assert_contains("if (!APP_DEBUG)", $bootstrap);
        assert_contains("ini_set('display_errors', '0')", $bootstrap);

        /* The error view renders no trace when there is no detail. */
        $html = View::capture('errors/500', [
            'code'    => 500,
            'heading' => 'Something went wrong',
            'message' => 'The page could not be loaded. Please try again in a moment.',
            'detail'  => null,
        ]);

        assert_contains('Something went wrong', $html);
        assert_not_contains('Stack trace', $html);
        assert_not_contains('RuntimeException', $html);

        /* …and the development detail only when it is passed in. */
        $debug = View::capture('errors/500', [
            'code'    => 500,
            'heading' => 'Something went wrong',
            'message' => 'The page could not be loaded.',
            'detail'  => 'RuntimeException: probe',
        ]);

        assert_contains('RuntimeException: probe', $debug);
    },

    'P15 the repository ignores secrets and runtime artefacts' => function (): void {
        $root    = dirname(__DIR__, 3);
        $ignore  = (string) file_get_contents($root . '/.gitignore');

        foreach ([
            'config.local.php',
            'system/public/uploads/*',
            'backups/',
            'system/storage/logs/*',
            'system/storage/locks/*',
            '*.log',
            'backup_*.sql',
        ] as $rule) {
            assert_contains($rule, $ignore, $rule . ' must stay out of git');
        }

        /* The guards that must be committed are un-ignored. */
        assert_contains('!system/public/uploads/.htaccess', $ignore);
    },

    'P15 the front controller pins the base URL, the router and the CSRF check' => function (): void {
        $root = dirname(__DIR__, 2) . '/public/index.php';
        $source = (string) file_get_contents($root);

        assert_contains("define('APP_BASE_URL'", $source);
        assert_contains('$router->dispatch($match)', $source);
        assert_contains('View::error(404)', $source);
        assert_contains('View::error(500', $source);

        $router = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/Router.php');
        assert_contains('private function verifyCsrf(): void', $router);
        assert_contains('hash_equals', (string) file_get_contents(dirname(__DIR__, 2) . '/app/helpers.php'));
    },

    'P15 the build reports phase 17' => function (): void {
        assert_same(17, APP_PHASE);
        assert_true(defined('APP_NAME'));
        assert_true(defined('APP_DEBUG'));
        assert_true(defined('APP_TIMEZONE'));
        assert_same('Asia/Manila', APP_TIMEZONE);

        /* The config no longer defines APP_URL twice (Phase 15 cleanup). */
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/app/config.php');
        assert_same(1, substr_count($config, "define('APP_URL'"), 'APP_URL is declared once');
    },
];
