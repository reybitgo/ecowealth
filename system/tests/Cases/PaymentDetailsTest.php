<?php
declare(strict_types=1);

/* ==============================================================
 *  PaymentDetailsTest — member payout accounts
 *  (profile section; reference member profile + plan §4/§5.9)
 *
 *  Covers validation (PH mobile, TRC20, BEP20), normalisation,
 *  clearing, ownership scoping and the profile rendering.
 * ============================================================== */

$makeMember = static function (): array {
    return test_user('payout_' . bin2hex(random_bytes(4)));
};

$service = static fn (): PaymentDetailsService => new PaymentDetailsService();

$valid = static fn (array $overrides = []): array => $overrides + [
    'gcash_number'       => '0917 123 4567',
    'maya_number'        => '+63 917 765 4321',
    'usdt_trc20_address' => 'T' . str_repeat('1', 33),
    'usdt_bep20_address' => '0x' . str_repeat('a', 40),
];

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
    'payout accounts save with normalised phone numbers' => function () use ($makeMember, $service, $valid): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $stored = $service()->save($id, $valid());

        assert_same('09171234567', $stored['gcash_number']);
        assert_same('+639177654321', $stored['maya_number']);
        assert_same('T' . str_repeat('1', 33), $stored['usdt_trc20_address']);
        assert_same('0x' . str_repeat('a', 40), $stored['usdt_bep20_address']);

        $saved = $service()->forUser($id);
        assert_same('09171234567', $saved['gcash_number']);
        assert_same('+639177654321', $saved['maya_number']);
        assert_same('T' . str_repeat('1', 33), $saved['usdt_trc20_address']);
        assert_same('0x' . str_repeat('a', 40), $saved['usdt_bep20_address']);
    },

    'payout accounts accept 09 and +63 numbers and reject nonsense' => function () use ($makeMember, $service, $valid): void {
        $member = $makeMember();
        $id     = (int) $member['id'];

        $ok = $service()->validate($valid([
            'gcash_number' => '0917-123-4567',
            'maya_number'  => '+63 (917) 765 4321',
        ]));
        assert_same([], $ok);

        $bad = $service()->validate([
            'gcash_number'       => '12345',
            'maya_number'        => '0917 123 456',          // 10 digits
            'usdt_trc20_address' => 'T123',
            'usdt_bep20_address' => '0x123',
        ]);

        foreach (['gcash_number', 'maya_number', 'usdt_trc20_address', 'usdt_bep20_address'] as $field) {
            assert_true(isset($bad[$field]), 'expected an error for ' . $field);
        }

        assert_throws(
            static fn () => $service()->save($id, ['gcash_number' => 'nope'] + $valid()),
            InvalidArgumentException::class
        );
        assert_same('', $service()->forUser($id)['gcash_number'], 'the invalid save wrote nothing');
    },

    'payout accounts can be cleared one by one and only touch the owner' => function () use ($makeMember, $service, $valid): void {
        $alice = $makeMember();
        $bob   = $makeMember();
        $a     = (int) $alice['id'];
        $b     = (int) $bob['id'];

        $service()->save($a, $valid());
        $service()->save($b, $valid(['gcash_number' => '0917 000 1111']));

        $service()->save($a, [
            'gcash_number'       => '',
            'maya_number'        => '',
            'usdt_trc20_address' => '',
            'usdt_bep20_address' => '',
        ]);

        $aliceSaved = $service()->forUser($a);
        assert_same('', $aliceSaved['gcash_number']);
        assert_same('', $aliceSaved['maya_number']);
        assert_same('', $aliceSaved['usdt_trc20_address']);
        assert_same('', $aliceSaved['usdt_bep20_address']);

        assert_same('09170001111', $service()->forUser($b)['gcash_number'], 'the other member is untouched');
    },

    'the profile renders the payout accounts card with the saved values' => function () use ($render, $makeMember, $service, $valid): void {
        $member = $makeMember();
        $id     = (int) $member['id'];
        $service()->save($id, $valid());
        test_signed_in_as($member);

        $html = $render('member', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => 'profile',
            'user'        => $member,
            'addresses'   => [],
            'editing'     => null,
            'payments'    => $service()->forUser($id),
            'posted'      => [],
        ]);

        assert_contains('Payout accounts', $html);
        assert_contains("company's receiving accounts", $html);
        assert_contains('action="' . url('profile/payment') . '"', $html);
        assert_contains('name="gcash_number"', $html);
        assert_contains('value="09171234567"', $html);
        assert_contains('name="maya_number"', $html);
        assert_contains('value="+639177654321"', $html);
        assert_contains('name="usdt_trc20_address"', $html);
        assert_contains('name="usdt_bep20_address"', $html);
        assert_contains('Save payout details', $html);
        assert_contains('Leave a field empty to remove it.', $html);
    },

    'a failed payout save keeps the typed values and shows the field error' => function () use ($render, $makeMember, $service): void {
        $member = $makeMember();
        test_signed_in_as($member);

        errors_set(['gcash_number' => 'Enter a GCash number like 0917 123 4567 (or +63 917 123 4567).']);
        old_payload_set(['gcash_number' => 'bogus', 'maya_number' => '0917 111 2222']);

        $html = $render('member', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => 'profile',
            'user'        => $member,
            'addresses'   => [],
            'editing'     => null,
            'payments'    => $service()->forUser((int) $member['id']),
            'posted'      => old_payload(),
        ]);

        assert_contains('value="bogus"', $html);
        assert_contains('value="0917 111 2222"', $html);
        assert_contains('Enter a GCash number', $html);
        assert_contains('is-invalid', $html);
    },

    'an admin profile keeps the payout card in the back-office layout' => function () use ($render, $service): void {
        $admin = test_user('payout_admin', 'admin');
        test_signed_in_as($admin);

        $html = $render('admin', 'member/profile', [
            'page_title'  => 'Profile',
            'current_nav' => 'admin.profile',
            'user'        => $admin,
            'addresses'   => [],
            'editing'     => null,
            'payments'    => $service()->forUser((int) $admin['id']),
            'posted'      => [],
        ]);

        assert_contains('id="layout-menu"', $html);
        assert_contains('My profile', $html);
        assert_contains('Payout accounts', $html);
        assert_contains('action="' . url('profile/payment') . '"', $html);
    },
];
