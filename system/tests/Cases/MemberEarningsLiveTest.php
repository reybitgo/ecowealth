<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberEarningsLiveTest — the member's own commission statement
 *
 *  The statement is a per-member live surface (`member.earnings:{id}`)
 *  whose regions are the summary cards, the skipped-award notice, the
 *  card heading, the award rows and the pagination. These cases pin:
 *
 *    · a credited or skipped award bumps it (a replayed award does not)
 *      and another member's statement is untouched;
 *    · the feed renders every region, keeps the filter form out of the
 *      payload, escapes source names and clamps an out-of-range page;
 *    · a skipped award appears live and never claims money;
 *    · the page declares its live surface and regions.
 * ============================================================== */

$makeMember = static function (string $username = ''): array {
    return test_user($username !== '' ? $username : 'mel_' . bin2hex(random_bytes(4)));
};

$packageA = static function (): array {
    $package = Package::findBySlug('package-a');
    assert_not_null($package);

    return $package;
};

/** Give a test member the active membership + package an award needs. */
$qualify = static function (int $userId, array $package): void {
    db()->prepare("UPDATE users SET member_status = 'active', is_active = 1, package_id = ? WHERE id = ?")
        ->execute([(int) $package['id'], $userId]);
};

/** A new active member placed under the given sponsor. */
$sourceUnder = static function (int $sponsorId): array {
    $source = test_user('mel_src_' . bin2hex(random_bytes(4)));

    db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
        ->execute([$sponsorId, (int) $source['id']]);

    $source['sponsor_id'] = $sponsorId;

    return $source;
};

/** An approved activation row, ready for CommissionService::onActivation(). */
$approvedActivation = static function (int $userId, array $package): array {
    $id = Activation::create([
        'user_id'        => $userId,
        'package_id'     => (int) $package['id'],
        'amount'         => (float) $package['price'],
        'payment_method' => 'code',
        'status'         => 'approved',
    ]);

    $row = Activation::find($id);
    assert_not_null($row);

    return $row;
};

return [
    'the earnings surface is per user and bumps on award and skip' => function () use (
        $makeMember,
        $packageA,
        $qualify,
        $sourceUnder,
        $approvedActivation
    ): void {
        $package   = $packageA();
        $recipient = $makeMember();
        $other     = $makeMember();
        $id        = (int) $recipient['id'];
        $otherId   = (int) $other['id'];

        $qualify($id, $package);

        $source        = $sourceUnder($id);
        $activation    = $approvedActivation((int) $source['id'], $package);
        $commissions   = new CommissionService();

        assert_same('member.earnings:' . $id, Live::earningsSurface($id));

        $before      = Live::revision(Live::earningsSurface($id));
        $otherBefore = Live::revision(Live::earningsSurface($otherId));

        $award = $commissions->onActivation((int) $activation['id']);

        assert_same(1, count($award['awards']), 'one direct award');
        assert_same(500.0, (float) $award['credited']);
        assert_same($before + 1, Live::revision(Live::earningsSurface($id)), 'award');
        assert_same($otherBefore, Live::revision(Live::earningsSurface($otherId)), 'another member is untouched');

        /* A replayed activation pays nothing and must not bump. */
        $before = Live::revision(Live::earningsSurface($id));
        $replay = $commissions->onActivation((int) $activation['id']);

        assert_same(0.0, (float) $replay['credited']);
        assert_same($before, Live::revision(Live::earningsSurface($id)));

        /* A skipped award still writes an audit row on the member's page. */
        $skipRecipient = $makeMember();
        $skipSource    = $sourceUnder((int) $skipRecipient['id']);
        $skipActivation = $approvedActivation((int) $skipSource['id'], $package);

        $skipBefore      = Live::revision(Live::earningsSurface((int) $skipRecipient['id']));
        $recipientBefore = Live::revision(Live::earningsSurface($id));

        $skip = $commissions->onActivation((int) $skipActivation['id']);

        assert_same(1, $skip['skipped']);
        assert_same($skipBefore + 1, Live::revision(Live::earningsSurface((int) $skipRecipient['id'])), 'skip');
        assert_same($recipientBefore, Live::revision(Live::earningsSurface($id)), 'the credited member is untouched');

        $skipRow = Commission::find((int) $skip['awards'][0]['id']);
        assert_not_null($skipRow);
        assert_same('skipped', $skipRow['status']);
    },

    'the earnings feed renders the stats, rows and pagination' => function () use (
        $makeMember,
        $packageA,
        $qualify,
        $sourceUnder,
        $approvedActivation
    ): void {
        $package   = $packageA();
        $recipient = $makeMember();
        $id        = (int) $recipient['id'];

        $qualify($id, $package);

        $commissions = new CommissionService();
        $sources     = [];

        /* Six awards make a second page at the smallest offered size. */
        for ($i = 1; $i <= 6; $i++) {
            $source    = $sourceUnder($id);
            $sources[] = $source;

            $commissions->onActivation((int) $approvedActivation((int) $source['id'], $package)['id']);
        }

        /* The row joins the source live; a hostile name must be escaped. */
        db()->prepare('UPDATE users SET username = ? WHERE id = ?')
            ->execute(['<b>Hostile Source</b>', (int) $sources[5]['id']]);

        test_signed_in_as($recipient);

        $_GET = ['per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?per_page=5';

        $controller = new EarningsController();
        $payload    = $controller->feedPayload(
            4,
            '"member.earnings:' . $id . ':4:abc"',
            $controller->earningsContext($id)
        );

        assert_true($payload['ok']);
        assert_same('member.earnings:' . $id, $payload['surface']);
        assert_same(4, $payload['revision']);
        assert_same(
            [
                'earnings-header',
                'earnings-stats',
                'earnings-skipped',
                'earnings-rows',
                'earnings-pagination',
            ],
            array_keys($payload['fragments'])
        );

        $header     = $payload['fragments']['earnings-header'];
        $stats      = $payload['fragments']['earnings-stats'];
        $skipped    = $payload['fragments']['earnings-skipped'];
        $rows       = $payload['fragments']['earnings-rows'];
        $pagination = $payload['fragments']['earnings-pagination'];

        assert_contains('data-live-region="earnings-header"', $header);
        assert_contains('6 awards', $header);

        assert_contains('data-live-region="earnings-stats"', $stats);
        assert_contains('Total credited', $stats);
        assert_contains(money(3000), $stats, 'six ₱500 awards');
        assert_contains('This month', $stats);
        assert_contains('Direct referral', $stats);
        assert_contains('Indirect referral', $stats);

        assert_contains('data-live-region="earnings-skipped"', $skipped);
        assert_contains('earnings-skipped" hidden', $skipped);
        assert_not_contains('was skipped', $skipped);

        assert_contains('data-live-region="earnings-rows"', $rows);
        assert_contains('data-live-key=', $rows);
        assert_contains('data-live-focus="details"', $rows);
        assert_contains('&lt;b&gt;Hostile Source&lt;/b&gt;', $rows);
        assert_not_contains('<b>Hostile Source</b>', $rows);
        assert_contains('Package A', $rows);
        assert_contains('Credited', $rows);
        assert_contains(money(500, true), $rows);
        assert_contains('data-commission="{', $rows);
        assert_not_contains('name="type"', $rows, 'the filter form never travels in a row fragment');

        assert_contains('data-live-region="earnings-pagination"', $pagination);
        assert_contains('earnings?per_page=5&amp;page=2', $pagination);
        assert_not_contains('/feed', $pagination);

        assert_contains('earnings?per_page=5', $payload['meta']['canonical']);

        /* An out-of-range page is clamped to the last real page. */
        $_GET = ['page' => '99', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings?page=99&per_page=5';

        $clamped = $controller->feedPayload(
            5,
            '"member.earnings:' . $id . ':5:def"',
            $controller->earningsContext($id)
        );

        assert_same(2, $clamped['meta']['page']);
        assert_contains('page=2', (string) $clamped['meta']['canonical']);
    },

    'the earnings feed surfaces a skipped award without claiming money' => function () use (
        $makeMember,
        $packageA,
        $sourceUnder,
        $approvedActivation
    ): void {
        $package   = $packageA();
        $recipient = $makeMember();
        $id        = (int) $recipient['id'];

        /* Active but no package: the award is due but never paid. */
        $source     = $sourceUnder($id);
        $activation = $approvedActivation((int) $source['id'], $package);

        (new CommissionService())->onActivation((int) $activation['id']);

        test_signed_in_as($recipient);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings';

        $controller = new EarningsController();
        $payload    = $controller->feedPayload(
            1,
            '"member.earnings:' . $id . ':1:abc"',
            $controller->earningsContext($id)
        );

        $stats   = $payload['fragments']['earnings-stats'];
        $skipped = $payload['fragments']['earnings-skipped'];
        $rows    = $payload['fragments']['earnings-rows'];

        assert_contains('data-live-region="earnings-skipped"', $skipped);
        assert_not_contains('earnings-skipped" hidden', $skipped);
        assert_contains('1 award was skipped', $skipped);
        assert_contains('never paid later', $skipped);

        assert_contains('data-live-key=', $rows);
        assert_contains('Skipped', $rows);
        assert_contains('not paid', $rows);
        assert_contains('recipient has no package', $rows);
        assert_contains(money(0), $stats, 'a skipped award is not money');
    },

    'the earnings page declares its live surface and regions' => function () use ($makeMember): void {
        $member = $makeMember();

        test_signed_in_as($member);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/earnings';

        $controller = new EarningsController();
        $data       = $controller->earningsContext((int) $member['id']);

        $payload = $controller->feedPayload(
            2,
            '"member.earnings:' . (int) $member['id'] . ':2:abc"',
            $data
        );

        /* An empty statement still carries every region, so the first
         * award can appear live without a full reload. */
        $rows = $payload['fragments']['earnings-rows'];

        assert_contains('data-live-region="earnings-rows"', $rows);
        assert_contains('No commissions yet', $rows);
        assert_contains('direct referral lands here', $rows);
        assert_contains('href="' . url('wallet') . '"', $rows);
        assert_contains('earnings-skipped" hidden', $payload['fragments']['earnings-skipped']);
        assert_contains('data-live-region="earnings-header"', $payload['fragments']['earnings-header']);
        assert_same(url('earnings'), $payload['meta']['canonical']);

        ob_start();

        try {
            View::page('member', 'member/earnings', $data + [
                'page_title'  => 'Earnings',
                'current_nav' => 'earnings',
                'page_css'    => ['assets/css/commissions.css'],
                'page_js'     => ['assets/js/app-commissions.js'],
                'live'        => Live::meta(Live::earningsSurface((int) $member['id']), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.earnings:' . (int) $member['id'] . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="earnings-header"', $html);
        assert_contains('data-live-region="earnings-stats"', $html);
        assert_contains('data-live-region="earnings-skipped"', $html);
        assert_contains('data-live-region="earnings-rows"', $html);
        assert_contains('data-live-region="earnings-pagination"', $html);
        assert_contains('data-live-status', $html);
        assert_not_contains('data-live-stale', $html, 'the filter form is static');
        assert_contains('name="type"', $html);
        assert_contains('id="commissionDetail"', $html);
        assert_contains('app-commissions.js', $html);
        assert_contains('No commissions yet', $html);
    },
];
