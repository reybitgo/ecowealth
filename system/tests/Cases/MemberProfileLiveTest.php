<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberProfileLiveTest — the profile page and the checkout picker
 *
 *  Two surfaces: `member.profile:{userId}` for the profile page and
 *  `member.checkout:{userId}` for the saved-address picker. These cases
 *  pin:
 *
 *    · address-book writes bump both surfaces; payout saves bump the
 *      profile only, and another member is untouched;
 *    · the profile feed renders the name, payout and address regions with
 *      the forms held and the address list free to replace;
 *    · a stale `edit` id is dropped from the canonical URL;
 *    · the checkout feed renders the saved-address wrapper (hidden when
 *      empty), honours the selected address and drops a stale id.
 * ============================================================== */

$makeMember = static function (string $username = ''): array {
    return test_user($username !== '' ? $username : 'mpl_' . bin2hex(random_bytes(4)));
};

$validPayments = static function (): array {
    return [
        'gcash_number'       => '0917 123 4567',
        'maya_number'        => '+63 917 765 4321',
        'usdt_trc20_address' => 'T' . str_repeat('1', 33),
        'usdt_bep20_address' => '0x' . str_repeat('a', 40),
    ];
};

return [
    'address-book writes bump the profile and checkout surfaces' => function () use ($makeMember, $validPayments): void {
        $member  = $makeMember();
        $other   = $makeMember();
        $id      = (int) $member['id'];
        $otherId = (int) $other['id'];
        $book    = new AddressService();
        $pay     = new PaymentDetailsService();

        assert_same('member.profile:' . $id, Live::profileSurface($id));
        assert_same('member.checkout:' . $id, Live::checkoutSurface($id));

        $profileBefore  = Live::revision(Live::profileSurface($id));
        $checkoutBefore = Live::revision(Live::checkoutSurface($id));
        $otherBefore    = Live::revision(Live::profileSurface($otherId));

        $addressId = $book->save($id, [
            'label'          => 'Home',
            'recipient_name' => 'Profile Owner',
            'address_line'   => '12 Mabini Street',
            'city'           => 'Makati',
        ]);

        assert_same($profileBefore + 1, Live::revision(Live::profileSurface($id)), 'address save (profile)');
        assert_same($checkoutBefore + 1, Live::revision(Live::checkoutSurface($id)), 'address save (checkout)');
        assert_same($otherBefore, Live::revision(Live::profileSurface($otherId)), 'another member is untouched');

        $profileBefore  = Live::revision(Live::profileSurface($id));
        $checkoutBefore = Live::revision(Live::checkoutSurface($id));

        $book->setDefault($addressId, $id);

        assert_same($profileBefore + 1, Live::revision(Live::profileSurface($id)), 'default change (profile)');
        assert_same($checkoutBefore + 1, Live::revision(Live::checkoutSurface($id)), 'default change (checkout)');

        $profileBefore  = Live::revision(Live::profileSurface($id));
        $checkoutBefore = Live::revision(Live::checkoutSurface($id));

        $book->remove($addressId, $id);

        assert_same($profileBefore + 1, Live::revision(Live::profileSurface($id)), 'address delete (profile)');
        assert_same($checkoutBefore + 1, Live::revision(Live::checkoutSurface($id)), 'address delete (checkout)');

        /* Payout accounts only appear on the profile page. */
        $profileBefore  = Live::revision(Live::profileSurface($id));
        $checkoutBefore = Live::revision(Live::checkoutSurface($id));

        $pay->save($id, $validPayments());

        assert_same($profileBefore + 1, Live::revision(Live::profileSurface($id)), 'payout save (profile)');
        assert_same($checkoutBefore, Live::revision(Live::checkoutSurface($id)), 'payout save (checkout unchanged)');

        /* A failed write moves no money and bumps nothing. */
        $profileBefore = Live::revision(Live::profileSurface($id));

        try {
            $book->save($id, ['recipient_name' => '', 'address_line' => '']);
        } catch (InvalidArgumentException) {
            /* expected */
        }

        assert_same($profileBefore, Live::revision(Live::profileSurface($id)), 'a refused save does not bump');
    },

    'cart and wallet movements bump the checkout surface' => function () use ($makeMember): void {
        $member  = $makeMember();
        $id      = (int) $member['id'];
        $product = Product::save([
            'name'   => 'Checkout Bump Product',
            'sku'    => 'CBP-' . strtoupper(bin2hex(random_bytes(3))),
            'price'  => '150.00',
            'stock'  => '20',
            'status' => 'active',
        ]);

        $before = Live::revision(Live::checkoutSurface($id));

        (new CartService())->add($id, $product, 1);

        assert_same($before + 1, Live::revision(Live::checkoutSurface($id)), 'cart add');

        $before = Live::revision(Live::checkoutSurface($id));

        (new WalletService())->credit($id, '200.00', 'adjustment', 987101, 'Checkout funds');

        assert_same($before + 1, Live::revision(Live::checkoutSurface($id)), 'wallet credit');
    },

    'the profile feed renders the name, payout and address regions' => function () use ($makeMember, $validPayments): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        db()->prepare('UPDATE users SET full_name = ? WHERE id = ?')
            ->execute(['Profile Owner <script>alert(1)</script>', $id]);

        $book = new AddressService();
        $book->save($id, [
            'label'          => 'Home',
            'recipient_name' => 'Profile Owner',
            'phone'          => '0917 000 0000',
            'address_line'   => '12 Mabini Street',
            'city'           => 'Makati',
            'province'       => 'Metro Manila',
        ]);
        $addressId = $book->save($id, [
            'label'          => 'Office',
            'recipient_name' => 'Profile Owner',
            'address_line'   => '99 Ayala Avenue',
            'city'           => 'Makati',
        ]);

        (new PaymentDetailsService())->save($id, $validPayments());

        test_signed_in_as($member);

        $_GET = ['edit' => (string) $addressId];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/profile?edit=' . $addressId;

        $controller = new ProfileController();
        $payload    = $controller->feedPayload(
            7,
            '"member.profile:' . $id . ':7:abc"',
            $controller->profileContext(Member::find($id))
        );

        assert_same('member.profile:' . $id, $payload['surface']);
        assert_same(
            ['profile-name', 'profile-payments', 'profile-addresses', 'profile-address-form'],
            array_keys($payload['fragments'])
        );

        $name      = $payload['fragments']['profile-name'];
        $payments  = $payload['fragments']['profile-payments'];
        $addresses = $payload['fragments']['profile-addresses'];
        $form      = $payload['fragments']['profile-address-form'];

        assert_contains('data-live-region="profile-name"', $name);
        assert_contains('data-live-hold="dirty"', $name);
        assert_contains('Profile Owner &lt;script&gt;alert(1)&lt;/script&gt;', $name);
        assert_not_contains('<script>alert(1)</script>', $name);
        assert_contains('name="_csrf"', $name);
        assert_contains('Save profile', $name);

        assert_contains('data-live-region="profile-payments"', $payments);
        assert_contains('data-live-hold="dirty"', $payments);
        assert_contains('value="09171234567"', $payments);
        assert_contains('value="+639177654321"', $payments);
        assert_contains('Save payout details', $payments);

        assert_contains('data-live-region="profile-addresses"', $addresses);
        assert_contains('2 saved', $addresses);
        assert_contains('Home', $addresses);
        assert_contains('Office', $addresses);
        assert_contains('12 Mabini Street', $addresses);
        assert_contains('Default', $addresses);
        assert_contains('data-confirm-title="Delete this address?"', $addresses);
        assert_not_contains('data-live-hold', $addresses, 'the list has no text input to hold');

        assert_contains('data-live-region="profile-address-form"', $form);
        assert_contains('data-live-hold="dirty"', $form);
        assert_contains('Edit address', $form);
        assert_contains('name="address_id" value="' . $addressId . '"', $form);
        assert_contains('value="Office"', $form);
        assert_contains('value="99 Ayala Avenue"', $form);
        assert_contains('Update address', $form);

        assert_contains('profile?edit=' . $addressId, (string) $payload['meta']['canonical']);
    },

    'a stale edit id falls back to the add-address form' => function () use ($makeMember): void {
        $member = $makeMember();

        test_signed_in_as($member);

        $_GET = ['edit' => '999999'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/profile?edit=999999';

        $controller = new ProfileController();
        $payload    = $controller->feedPayload(
            2,
            '"member.profile:' . (int) $member['id'] . ':2:def"',
            $controller->profileContext(Member::find((int) $member['id']))
        );

        $form = $payload['fragments']['profile-address-form'];

        assert_contains('Add an address', $form);
        assert_contains('Save address', $form);
        assert_contains('name="address_id" value="0"', $form);
        assert_same(url('profile'), $payload['meta']['canonical'], 'the stale edit id is dropped');
    },

    'the profile page declares its live surface and regions' => function () use ($makeMember): void {
        $member = $makeMember();

        (new AddressService())->save((int) $member['id'], [
            'recipient_name' => 'Profile Owner',
            'address_line'   => '12 Mabini Street',
        ]);

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/profile';

        $controller = new ProfileController();
        $data       = $controller->profileContext(Member::find((int) $member['id']));

        ob_start();

        try {
            View::page('member', 'member/profile', $data + [
                'page_title'  => 'Profile',
                'current_nav' => 'profile',
                'page_css'    => ['assets/css/orders.css', 'assets/vendor/libs/sweetalert2/sweetalert2.css'],
                'page_js'     => [
                    'assets/vendor/libs/sweetalert2/sweetalert2.js',
                    'assets/js/app-confirm.js',
                    'assets/js/admin-live.js',
                ],
                'live'        => Live::meta(Live::profileSurface((int) $member['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.profile:' . (int) $member['id'] . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="profile-name"', $html);
        assert_contains('data-live-region="profile-payments"', $html);
        assert_contains('data-live-region="profile-addresses"', $html);
        assert_contains('data-live-region="profile-address-form"', $html);
        assert_contains('data-live-hold="dirty"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_contains('action="' . url('profile/address') . '"', $html);
        assert_contains('action="' . url('profile/payment') . '"', $html);
    },

    'the checkout feed renders, hides and selects the saved addresses' => function () use ($makeMember): void {
        $member  = $makeMember();
        $id      = (int) $member['id'];
        $book    = new AddressService();

        $product = Product::save([
            'name'   => 'Checkout Live Product',
            'sku'    => 'CPL-' . strtoupper(bin2hex(random_bytes(3))),
            'price'  => '300.00',
            'stock'  => '20',
            'status' => 'active',
        ]);
        (new CartService())->add($id, $product, 2);
        (new WalletService())->credit($id, '1000.00', 'adjustment', 987001, 'Checkout live funds');

        $first = $book->save($id, [
            'label'          => 'Home',
            'recipient_name' => 'Checkout Owner',
            'address_line'   => '12 Mabini Street',
            'city'           => 'Makati',
        ]);
        $book->save($id, [
            'label'          => 'Office',
            'recipient_name' => 'Checkout Owner',
            'address_line'   => '99 Ayala Avenue',
            'city'           => 'Makati',
        ]);

        test_signed_in_as($member);

        $_GET = ['address' => (string) $first];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/checkout?address=' . $first;

        $controller = new CheckoutController();
        $payload    = $controller->feedPayload(
            3,
            '"member.checkout:' . $id . ':3:abc"',
            $controller->checkoutContext(Member::find($id))
        );

        assert_same('member.checkout:' . $id, $payload['surface']);
        assert_same(
            ['checkout-addresses', 'checkout-summary', 'checkout-payment'],
            array_keys($payload['fragments'])
        );

        $addresses = $payload['fragments']['checkout-addresses'];
        $summary   = $payload['fragments']['checkout-summary'];
        $payment   = $payload['fragments']['checkout-payment'];

        assert_contains('data-live-region="checkout-addresses"', $addresses);
        assert_not_contains('checkout-addresses" hidden', $addresses);
        assert_contains('Saved addresses', $addresses);
        assert_contains('Checkout Owner', $addresses);
        assert_contains('12 Mabini Street', $addresses);
        assert_contains('99 Ayala Avenue', $addresses);
        assert_contains('Manage addresses', $addresses);
        assert_contains('checkout?address=' . $first, $addresses);
        assert_contains('Selected', $addresses);
        assert_contains('Default', $addresses);

        assert_contains('data-live-region="checkout-summary"', $summary);
        assert_contains('Checkout Live Product', $summary);
        assert_contains(money(600), $summary);
        assert_contains('E-wallet', $summary);
        assert_contains(money(1000), $summary, 'the live wallet note follows the balance');
        assert_contains('View wallet', $summary);
        assert_not_contains('data-live-hold', $summary, 'the summary has no input to hold');

        assert_contains('data-live-region="checkout-payment"', $payment);
        assert_contains('data-live-hold="dirty"', $payment);
        assert_contains('name="payment_method"', $payment);
        assert_contains('value="ewallet"', $payment);
        assert_contains('value="gcash"', $payment);
        assert_contains('Balance ' . money(1000), $payment);
        assert_contains('checked', $payment);

        assert_contains('checkout?address=' . $first, (string) $payload['meta']['canonical']);

        /* A stale address id falls back to the default and drops from the URL. */
        $_GET = ['address' => '999999'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/checkout?address=999999';

        $stale = $controller->feedPayload(
            4,
            '"member.checkout:' . $id . ':4:def"',
            $controller->checkoutContext(Member::find($id))
        );

        assert_same(url('checkout'), $stale['meta']['canonical']);
        assert_contains('Selected', $stale['fragments']['checkout-addresses']);

        /* An empty book renders the hidden wrapper and no card. */
        $empty   = $makeMember();
        $emptyId = (int) $empty['id'];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/checkout';

        $emptyPayload = $controller->feedPayload(
            1,
            '"member.checkout:' . $emptyId . ':1:x"',
            $controller->checkoutContext(Member::find($emptyId))
        );

        $emptyHtml = $emptyPayload['fragments']['checkout-addresses'];

        assert_contains('data-live-region="checkout-addresses" hidden', $emptyHtml);
        assert_not_contains('Saved addresses', $emptyHtml);
        assert_same(url('checkout'), $emptyPayload['meta']['canonical']);
    },

    'the checkout page declares its live surface and regions' => function () use ($makeMember): void {
        $member = $makeMember();

        (new AddressService())->save((int) $member['id'], [
            'recipient_name' => 'Checkout Owner',
            'address_line'   => '12 Mabini Street',
        ]);

        $product = Product::save([
            'name'   => 'Checkout Page Product',
            'sku'    => 'CPP-' . strtoupper(bin2hex(random_bytes(3))),
            'price'  => '250.00',
            'stock'  => '20',
            'status' => 'active',
        ]);
        (new CartService())->add((int) $member['id'], $product, 1);

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/checkout';

        $controller = new CheckoutController();
        $context    = $controller->checkoutContext(Member::find((int) $member['id']));

        ob_start();

        try {
            View::page('member', 'shop/checkout', $context + [
                'page_title'      => 'Checkout',
                'current_nav'     => 'cart',
                'page_css'        => ['assets/css/shop.css', 'assets/css/orders.css'],
                'page_js'         => ['assets/js/app-checkout.js', 'assets/js/admin-live.js'],
                'idempotency_key' => 'checkout-key',
                'shop_open'       => true,
                'live'            => Live::meta(Live::checkoutSurface((int) $member['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.checkout:' . (int) $member['id'] . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="checkout-addresses"', $html);
        assert_contains('data-live-region="checkout-summary"', $html);
        assert_contains('data-live-region="checkout-payment"', $html);
        assert_contains('data-live-hold="dirty"', $html);
        assert_contains('data-live-status', $html);
        assert_contains('data-live-stale', $html);
        assert_contains('admin-live.js', $html);
        assert_contains('Saved addresses', $html);
        assert_contains('Checkout Page Product', $html);
        assert_contains('name="shipping_name"', $html, 'the delivery form stays static');
        assert_contains('id="placeOrder"', $html, 'the summary footer stays static');
    },
];
