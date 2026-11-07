<?php
declare(strict_types=1);

/* ==============================================================
 *  SkeletonTest — the Phase 1 foundation (plan §7 Phase 1)
 *
 *  Covers the base-path maths (P1-T5), the formatting/validation
 *  helpers every later phase uses, and the small utilities the
 *  layout depends on.
 * ============================================================== */

return [
    /* ---------- Base path (P1-T5) ---------------------------------- */

    'P1-T5 detect_app_base_url: document root' => function (): void {
        assert_same('/', detect_app_base_url('/index.php'));
    },

    'P1-T5 detect_app_base_url: /ecowealth/system/ sub-folder' => function (): void {
        assert_same('/ecowealth/system/', detect_app_base_url('/ecowealth/system/public/index.php'));
    },

    'P1-T5 detect_app_base_url: normalises Windows separators' => function (): void {
        assert_same('/app/', detect_app_base_url('\\app\\public\\index.php'));
    },

    'P1-T5 url() prefixes the installation path' => function (): void {
        assert_same('/ecowealth/system/assets/css/demo.css', url('assets/css/demo.css'));
        assert_same('/ecowealth/system/', url('/'));
        assert_same('/ecowealth/system/admin/products/9/edit', url('/admin/products/9/edit'));
    },

    'P9-T18 absolute_url() adds the origin to copyable links' => function (): void {
        $saved = [
            'HTTP_HOST'             => $_SERVER['HTTP_HOST'] ?? null,
            'HTTPS'                 => $_SERVER['HTTPS'] ?? null,
            'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
        ];

        try {
            /* A configured APP_URL wins over the request host. */
            if (defined('APP_URL') && trim((string) APP_URL) !== '') {
                assert_same(
                    rtrim((string) APP_URL, '/') . '/ecowealth/system/register?ref=5MS9S37C',
                    absolute_url('register?ref=5MS9S37C')
                );

                return;
            }

            $_SERVER['HTTP_HOST'] = 'localhost';
            unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

            assert_same(
                'http://localhost/ecowealth/system/register?ref=5MS9S37C',
                absolute_url('register?ref=5MS9S37C')
            );
            assert_same('http://localhost/ecowealth/system/', absolute_url('/'));

            $_SERVER['HTTP_HOST'] = 'www.ecowealth.com';
            assert_same(
                'http://www.ecowealth.com/ecowealth/system/register?ref=5MS9S37C',
                absolute_url('register?ref=5MS9S37C')
            );

            $_SERVER['HTTPS'] = 'on';
            assert_same('https://www.ecowealth.com/ecowealth/system/register', absolute_url('register'));

            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
            assert_same('https://www.ecowealth.com/ecowealth/system/register', absolute_url('register'));

            assert_same(
                'https://example.com/join',
                absolute_url('https://example.com/join'),
                'an already absolute URL is left alone'
            );

            unset($_SERVER['HTTP_HOST']);
            assert_same(
                'http://localhost/ecowealth/system/register',
                absolute_url('register'),
                'CLI/no-host falls back to localhost'
            );
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    },

    'P1-T5 Request::pathFrom strips the install prefix from a deep URL' => function (): void {
        assert_same(
            '/admin/products/9/edit',
            Request::pathFrom('/ecowealth/system/admin/products/9/edit', '/ecowealth/system/')
        );
    },

    'P1-T5 Request::pathFrom handles root, trailing slash and query' => function (): void {
        assert_same('/', Request::pathFrom('/', '/'));
        assert_same('/', Request::pathFrom('/ecowealth/system/', '/ecowealth/system/'));
        assert_same('/', Request::pathFrom('/ecowealth/system', '/ecowealth/system/'));
        assert_same('/shop', Request::pathFrom('/shop/', '/'));
        assert_same('/shop', Request::pathFrom('/ecowealth/system/shop?page=2', '/ecowealth/system/'));
        assert_same('/shop', Request::pathFrom('/ecowealth/system/shop#top', '/ecowealth/system/'));
    },

    'P1-T5 Request::pathFrom decodes percent-escapes' => function (): void {
        assert_same('/admin/users x', Request::pathFrom('/admin/users%20x', '/'));
    },

    /* ---------- Money ------------------------------------------------- */

    'money() formats pesos' => function (): void {
        assert_same('₱1,234.50', money(1234.5));
        assert_same('₱0.00', money(0));
        assert_same('₱0.00', money(null));
        assert_same('₱1,000,000.00', money('1000000'));
    },

    'money() shows the sign only when asked (or negative)' => function (): void {
        assert_same('-₱5.00', money(-5));
        assert_same('+₱10.00', money(10, true));
        assert_same('-₱0.50', money(-0.5, true));
    },

    /* ---------- Status badges ------------------------------------------ */

    'status_badge() maps a known status' => function (): void {
        $html = status_badge('active');
        assert_contains('bg-label-success', $html);
        assert_contains('>Active<', $html);
    },

    'status_badge() humanises an unknown status' => function (): void {
        $html = status_badge('awaiting_review');
        assert_contains('Awaiting review', $html);
        assert_contains('bg-label-secondary', $html);
    },

    'status_badge() accepts per-page overrides' => function (): void {
        assert_contains('Settled', status_badge('paid', ['paid' => ['Settled', 'info']]));
    },

    /* ---------- Validator ---------------------------------------------- */

    'Validator flags required, length and numeric rules' => function (): void {
        $errors = Validator::check(
            ['name' => 'A', 'price' => '0', 'qty' => '2.5'],
            [
                'name'  => 'required|string|min:2|max:160',
                'price' => 'required|numeric|gt:0',
                'qty'   => 'required|integer',
            ],
            ['name' => 'Product name']
        );

        assert_count(3, $errors);
        assert_contains('Product name must be at least 2 characters', $errors['name']);
        assert_contains('Price must be greater than 0', $errors['price']);
        assert_contains('Qty must be a whole number', $errors['qty']);
    },

    'Validator checks decimals, email and confirmation' => function (): void {
        $errors = Validator::check(
            ['amount' => '10.999', 'email' => 'nope', 'password' => 'secret123', 'password_confirmation' => 'other'],
            [
                'amount'   => 'required|decimal:2',
                'email'    => 'required|email',
                'password' => 'required|string|min:8|confirmed',
            ]
        );

        assert_count(3, $errors);
        assert_contains('at most 2 decimal places', $errors['amount']);
        assert_contains('valid email', $errors['email']);
        assert_contains('confirmation does not match', $errors['password']);
    },

    'Validator passes valid input' => function (): void {
        $errors = Validator::check(
            ['name' => 'Coffee', 'price' => '300.00', 'stock' => '50', 'status' => 'active'],
            [
                'name'   => 'required|string|min:2|max:160',
                'price'  => 'required|numeric|gt:0|decimal:2',
                'stock'  => 'required|integer|min:0',
                'status' => 'required|in:active,inactive',
            ]
        );

        assert_same([], $errors);
    },

    'Validator honours nullable fields' => function (): void {
        assert_same([], Validator::check(['sku' => ''], ['sku' => 'nullable|string|min:3']));
    },

    'Validator unique rule reads the database' => function (): void {
        $errors = Validator::check(['username' => 'admin'], ['username' => 'required|unique:users,username']);
        assert_contains('already taken', $errors['username'] ?? '');

        $own = Validator::check(
            ['username' => 'admin'],
            ['username' => 'required|unique:users,username,1']
        );
        assert_same([], $own);
    },

    /* ---------- Paginator ---------------------------------------------- */

    'Paginator computes pages, offset and summary' => function (): void {
        $pager = new Paginator(57, 20, 2);

        assert_same(3, $pager->pages);
        assert_same(20, $pager->offset);
        assert_same(21, $pager->from);
        assert_same(40, $pager->to);
        assert_true($pager->hasPrev());
        assert_true($pager->hasNext());
        assert_contains('Showing 21–40 of 57', $pager->summary());
    },

    'Paginator clamps an out-of-range page' => function (): void {
        $pager = new Paginator(57, 20, 99);

        assert_same(3, $pager->page);
        assert_same(40, $pager->offset);
        assert_false($pager->hasNext());
    },

    'Paginator handles an empty result' => function (): void {
        $pager = new Paginator(0);

        assert_same(1, $pager->pages);
        assert_same(0, $pager->from);
        assert_same(0, $pager->to);
        assert_same('No records', $pager->summary());
        assert_same([], $pager->window());
    },

    'Paginator window surrounds the current page' => function (): void {
        assert_same([1, 2, 3], (new Paginator(100, 20, 2))->window(1));
        assert_same([3, 4, 5], (new Paginator(100, 20, 4))->window(1));
    },

    'Paginator defaults to ten rows and offers 5 … 100 in fives' => function (): void {
        assert_same(10, Paginator::DEFAULT_PER_PAGE);
        assert_same(10, (new Paginator(100))->perPage);

        $options = Paginator::perPageOptions();

        assert_same(5, $options[0]);
        assert_same(10, $options[1]);
        assert_same(15, $options[2]);
        assert_same(20, $options[3]);
        assert_same(100, $options[count($options) - 1]);

        foreach ($options as $option) {
            assert_same(0, $option % 5, 'every size is a multiple of five');
        }

        assert_count(20, $options);
    },

    'Paginator reads a valid per_page and falls back to the default' => function (): void {
        $_GET = ['per_page' => '15', 'page' => '2'];

        try {
            $pager = Paginator::fromTableRequest(57);

            assert_same(15, $pager->perPage);
            assert_same(2, $pager->page);
            assert_same(15, $pager->offset);
            assert_same(4, $pager->pages);

            $_GET = ['per_page' => '7'];
            assert_same(10, Paginator::perPageFromRequest(), 'a size outside the options falls back to 10');
            assert_same(10, Paginator::fromTableRequest(57)->perPage);

            $_GET = ['per_page' => '0'];
            assert_same(10, Paginator::perPageFromRequest());

            $_GET = ['per_page' => '500'];
            assert_same(10, Paginator::perPageFromRequest());

            $_GET = [];
            assert_same(10, Paginator::perPageFromRequest());
            assert_same(1, (new Paginator(0))->page);

            $_GET = ['per_page' => '5'];
            $first = Paginator::fromTableRequest(4);
            assert_same(5, $first->perPage);
            assert_same(1, $first->pages);
        } finally {
            $_GET = [];
        }
    },

    'Paginator page URLs keep the chosen page size' => function (): void {
        $_SERVER['REQUEST_URI'] = '/admin/members?per_page=25&q=ana';
        $_GET = ['per_page' => '25', 'q' => 'ana'];

        try {
            $pager = new Paginator(100, 25, 2);
            $url   = $pager->url(3);

            assert_contains('per_page=25', $url);
            assert_contains('q=ana', $url);
            assert_contains('page=3', $url);
        } finally {
            unset($_SERVER['REQUEST_URI']);
            $_GET = [];
        }
    },

    /* ---------- Flash / old input / form errors ------------------------ */

    'flash messages render once' => function (): void {
        flash_set('success', 'Saved successfully');

        $html = flash_render();
        assert_contains('alert-success', $html);
        assert_contains('Saved successfully', $html);
        assert_same('', flash_render());
    },

    'old() repopulates and clears' => function (): void {
        old_set(['name' => 'Coffee <b>']);
        assert_same('Coffee &lt;b&gt;', old('name'));
        assert_same('fallback', old('missing', 'fallback'));
        old_clear();
        assert_same('', old('name'));
    },

    'form errors survive one round trip' => function (): void {
        errors_set(['name' => 'Name is required.']);

        assert_true(has_error('name'));
        assert_same('Name is required.', error_for('name'));
        assert_false(has_error('price'));
        assert_same(['name' => 'Name is required.'], errors_all());
    },

    'invalid_class adds is-invalid only for a failing field' => function (): void {
        errors_set(['name' => 'Required.']);

        assert_same('is-invalid', invalid_class('name'));
        assert_same('mt-2', invalid_class('price', 'mt-2'));
    },
];
