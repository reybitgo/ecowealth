<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageViewTest — admin and member package pages
 *  (plan §7 Phase 3, tests P3-T5 / P3-T8 / P3-T7 rendering side)
 * ============================================================== */

/**
 * Render a page through View::page() and return the HTML.
 *
 * @param array<string, mixed> $data
 */
$render = static function (string $layout, string $template, array $data = []): string {
    ob_start();

    try {
        View::page($layout, $template, $data);
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
};

return [
    'P3-T8 the packages page lists both packages with prices and bonuses' => function () use ($render): void {
        test_signed_in_as(test_user('packages_member'));

        $html = $render('member', 'shop/packages', [
            'packages' => PackageService::publicCards(),
        ]);

        assert_contains('Associate packages', $html);
        assert_contains('Package A', $html);
        assert_contains('Package B', $html);
        assert_contains(money(4498), $html);
        assert_contains(money(6998), $html);
        assert_contains(money(500), $html);
        assert_contains('Direct referral (level 1)', $html);
        assert_contains('10 of 10 levels pay', $html);
        assert_contains('Colonics Session', $html);
        assert_contains('Activation opens in Phase 9', $html);
    },

    'P3-T8 the packages page shows the full ladder on demand' => function () use ($render): void {
        test_signed_in_as(test_user('packages_member'));

        $html = $render('member', 'shop/packages', ['packages' => PackageService::publicCards()]);

        assert_contains('Level 2', $html);
        assert_contains('Level 10', $html);
        assert_contains('stored on the package', $html);
        assert_contains(money(100), $html);
    },

    'P3-T8 an empty catalogue shows the empty state' => function () use ($render): void {
        test_signed_in_as(test_user('packages_member'));

        $html = $render('member', 'shop/packages', ['packages' => []]);

        assert_contains('No packages are available yet', $html);
        assert_not_contains('Package A', $html);
    },

    'P3-T8 package text is escaped' => function () use ($render): void {
        test_signed_in_as(test_user('packages_member'));

        $card = [
            'id'                    => 1,
            'name'                  => '<script>alert(1)</script>',
            'description'           => '<b>bold</b>',
            'perks'                 => '<img src=x onerror=alert(2)>',
            'price'                 => '10.00',
            'product_value'         => '12.00',
            'direct_referral_bonus' => '1.00',
            'ladder'                => PackageService::ladder(['direct_referral_bonus' => '1.00'], []),
            'ladder_total'          => 1.0,
            'funded_levels'         => 1,
            'contents'              => [],
            'status'                => 'active',
        ];

        $html = $render('member', 'shop/packages', ['packages' => [$card]]);

        assert_not_contains('<script>alert(1)</script>', $html);
        assert_not_contains('<img src=x onerror=alert(2)>', $html);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    },

    'P3-T1 the admin list renders packages with their ladder summary' => function () use ($render): void {
        test_signed_in_as(test_user('packages_admin', 'admin'));

        $package = Package::findBySlug('package-a');
        $id      = (int) $package['id'];

        $html = $render('admin', 'admin/packages/index', [
            'packages' => [$package],
            'pager'    => new Paginator(1, 20, 1),
            'filters'  => ['q' => '', 'status' => ''],
            'stats'    => ['total' => 1, 'active' => 1, 'inactive' => 0],
            'levels'   => [$id => Package::levels($id)],
            'contents' => [$id => Package::contents($id)],
        ]);

        assert_contains('Associate packages', $html);
        assert_contains('Package A', $html);
        assert_contains(money(4498), $html);
        assert_contains(money(500), $html);
        assert_contains('9 paying levels', $html);
        assert_contains(url('admin/packages/' . $id . '/edit'), $html);
        assert_contains(url('admin/packages/' . $id . '/status'), $html);
    },

    'P3-T5 the admin form renders 9 editable levels and a read-only level 1' => function () use ($render): void {
        test_signed_in_as(test_user('packages_admin', 'admin'));

        $html = $render('admin', 'admin/packages/form', [
            'package'         => null,
            'current'         => [
                'name' => '', 'price' => '4498.00', 'product_value' => '4750.00',
                'direct_referral_bonus' => '500.00', 'description' => '', 'perks' => '',
                'status' => 'active', 'sort_order' => '0', 'indirect_enabled' => '1', 'image' => null,
            ],
            'levels'          => [2 => '100.00', 3 => '', 4 => '', 5 => '', 6 => '', 7 => '', 8 => '', 9 => '', 10 => ''],
            'contents'        => [['product_id' => 0, 'quantity' => 1]],
            'content_options' => PackageService::contentOptions(),
        ]);

        assert_contains('Referral ladder', $html);
        assert_contains('Level 1 — Direct referral', $html);
        assert_not_contains('name="levels[1]"', $html, 'level 1 is not an editable input');
        assert_same(9, substr_count($html, 'name="levels['), 'levels 2–10 each get an input');

        assert_contains('name="levels[2]"', $html);
        assert_contains('value="100.00"', $html);
        assert_contains('name="direct_referral_bonus"', $html);
        assert_contains('Package contents', $html);
        assert_contains('name="content_product_id[]"', $html);
        assert_contains('name="content_quantity[]"', $html);
        assert_contains('Add product', $html);
        assert_contains('Pay the 2–10 unilevel ladder', $html);
    },

    'P3-T2 the admin form shows validation errors and posted values' => function () use ($render): void {
        test_signed_in_as(test_user('packages_admin', 'admin'));

        errors_set([
            'name'      => 'Package name is required.',
            'level_3'   => 'Level 3 must be at least 0.',
            'contents'  => 'Only active products can be added.',
        ]);
        old_payload_set([
            'input'    => ['name' => '', 'price' => '10.00', 'product_value' => '', 'direct_referral_bonus' => '1.00', 'sort_order' => '0', 'status' => 'active', 'indirect_enabled' => '0'],
            'levels'   => [2 => '', 3 => '-5', 4 => '', 5 => '', 6 => '', 7 => '', 8 => '', 9 => '', 10 => ''],
            'contents' => [],
        ]);

        $payload = old_payload();

        $html = $render('admin', 'admin/packages/form', [
            'package'         => null,
            'current'         => $payload['input'],
            'levels'          => $payload['levels'],
            'contents'        => $payload['contents'],
            'content_options' => PackageService::contentOptions(),
        ]);

        assert_contains('Please fix the highlighted field', $html);
        assert_contains('Package name is required.', $html);
        assert_contains('Level 3 must be at least 0.', $html);
        assert_contains('Only active products can be added.', $html);
        assert_contains('value="-5"', $html, 'the posted level value is kept');
        assert_contains('value="10.00"', $html, 'the posted price is kept');
    },
];
