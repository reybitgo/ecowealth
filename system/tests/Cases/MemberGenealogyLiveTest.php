<?php
declare(strict_types=1);

/* ==============================================================
 *  MemberGenealogyLiveTest — the member's own genealogy page
 *
 *  The page is a per-member surface (`member.genealogy:{id}`) whose
 *  ETag also includes the shared `member.genealogy` team revision.
 *  These cases pin:
 *
 *    · a registration, an activation and a status change bump the
 *      shared revision, so the page ETag moves;
 *    · the feed renders the heading badges, team chain, direct-recruit
 *      count/rows/pagination and the team-by-level card;
 *    · an out-of-range recruits page is clamped and the empty team-chain /
 *      team states render inside their regions;
 *    · the page declares its live surface and regions.
 * ============================================================== */

$makeMember = static function (string $username = '', string $role = 'member'): array {
    return test_user(
        $username !== '' ? $username : 'mgl_' . bin2hex(random_bytes(4)),
        $role
    );
};

$packageA = static function (): array {
    $package = Package::findBySlug('package-a');
    assert_not_null($package);

    return $package;
};

return [
    'the genealogy surface is per user and team writes bump the shared revision' => function () use ($makeMember, $packageA): void {
        $sponsor = $makeMember();
        $sponsorId = (int) $sponsor['id'];

        assert_same('member.genealogy:' . $sponsorId, Live::genealogySurface($sponsorId));

        $before = Live::revision(Live::GENEALOGY_TEAM);

        $username = 'mgl_reg_' . bin2hex(random_bytes(4));

        $registration = (new RegistrationService())->register([
            'mode'             => 'pending',
            'username'         => $username,
            'email'            => $username . '@test.local',
            'full_name'        => 'Genealogy Recruit',
            'phone'            => '',
            'password'         => 'Member@1234',
            'password_confirm' => 'Member@1234',
            'sponsor'          => (string) $sponsor['username'],
            'package_id'       => '',
            'reg_code'         => '',
            'terms'            => true,
        ], null);

        assert_same($before + 1, Live::revision(Live::GENEALOGY_TEAM), 'registration');

        /* The page ETag embeds the shared revision, so the next poll is a
         * 200 for every open genealogy page. */
        $scopeBefore = Live::scope([], $sponsorId) . '|team:' . $before;
        $scopeAfter  = Live::scope([], $sponsorId) . '|team:' . Live::revision(Live::GENEALOGY_TEAM);
        $etagBefore  = Live::etag(Live::genealogySurface($sponsorId), 0, $scopeBefore);
        $etagAfter   = Live::etag(Live::genealogySurface($sponsorId), 0, $scopeAfter);

        assert_not_same($etagBefore, $etagAfter);

        /* A status change moves the badge on every ancestor's page. */
        $before = Live::revision(Live::GENEALOGY_TEAM);

        Member::setStatus((int) $registration['user_id'], 'suspended');

        assert_same($before + 1, Live::revision(Live::GENEALOGY_TEAM), 'status change');

        /* An activation sets the package/active status shown in the self
         * row and in the ancestors' team lists. */
        $package    = $packageA();
        $activating = $makeMember();
        $activationId = Activation::create([
            'user_id'        => (int) $activating['id'],
            'package_id'     => (int) $package['id'],
            'amount'         => (float) $package['price'],
            'payment_method' => 'code',
            'status'         => 'approved',
        ]);

        $before = Live::revision(Live::GENEALOGY_TEAM);

        (new ActivationService())->complete($activationId);

        assert_same($before + 1, Live::revision(Live::GENEALOGY_TEAM), 'activation');
    },

    'the genealogy feed renders the team chain, recruits and team levels' => function () use ($makeMember): void {
        $juan  = Member::findByUsername('juan');
        $maria = Member::findByUsername('maria');
        assert_not_null($juan);
        assert_not_null($maria);

        $juanId  = (int) $juan['id'];
        $mariaId = (int) $maria['id'];

        test_signed_in_as($juan);

        db()->prepare('UPDATE users SET username = ? WHERE id = ?')
            ->execute(['<script>alert(1)</script>', $mariaId]);

        try {
            $_GET = [];
            $_SERVER['REQUEST_URI'] = '/ecowealth/system/genealogy';

            $controller = new GenealogyController();
            $payload    = $controller->feedPayload(
                3,
                '"member.genealogy:' . $juanId . ':3:abc"',
                $controller->genealogyContext(Member::find($juanId))
            );

            assert_true($payload['ok']);
            assert_same('member.genealogy:' . $juanId, $payload['surface']);
            assert_same(3, $payload['revision']);
            assert_same(
                [
                    'genealogy-header',
                    'genealogy-team-chain',
                    'genealogy-recruits-count',
                    'genealogy-recruits-rows',
                    'genealogy-recruits-pagination',
                    'genealogy-team',
                ],
                array_keys($payload['fragments'])
            );

            $header     = $payload['fragments']['genealogy-header'];
            $teamChain  = $payload['fragments']['genealogy-team-chain'];
            $count      = $payload['fragments']['genealogy-recruits-count'];
            $rows       = $payload['fragments']['genealogy-recruits-rows'];
            $pagination = $payload['fragments']['genealogy-recruits-pagination'];
            $team       = $payload['fragments']['genealogy-team'];

            assert_contains('data-live-region="genealogy-header"', $header);
            assert_contains('4 in team', $header);
            assert_contains('2 levels', $header);

            assert_contains('data-live-region="genealogy-team-chain"', $teamChain);
            assert_contains('· you', $teamChain);
            assert_contains('Juan Dela Cruz', $teamChain, 'the self row shows the full name');
            assert_contains('L1', $teamChain);
            assert_contains('Direct recruits', $teamChain, 'level 1 is the direct leg');
            assert_contains('L2', $teamChain);
            assert_contains('peter', $teamChain);
            assert_contains('sarah', $teamChain);
            assert_not_contains('admin', $teamChain, 'the team chain never shows the member\'s uplines');

            /* The logged-in member is the first row, above the level rows. */
            $selfPos = strpos($teamChain, 'Juan Dela Cruz');
            $l1Pos   = strpos($teamChain, 'L1');
            assert_true(
                $selfPos !== false && $l1Pos !== false && $selfPos < $l1Pos,
                'the member own row sits above the team chain'
            );

            assert_contains('data-live-region="genealogy-recruits-count"', $count);
            assert_contains('>1<', $count);

            assert_contains('data-live-region="genealogy-recruits-rows"', $rows);
            assert_contains('data-live-key="' . $mariaId . '"', $rows);
            assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $rows);
            assert_not_contains('<script>alert(1)</script>', $rows);
            assert_contains('Maria Santos', $rows);
            assert_contains('Package A', $rows);
            assert_contains('Active', $rows);

            assert_contains('data-live-region="genealogy-recruits-pagination"', $pagination);
            assert_contains('Rows per page', $pagination);
            assert_contains('Recruits', $pagination);

            assert_contains('data-live-region="genealogy-team"', $team);
            assert_contains('Level 2', $team);
            assert_contains('peter', $team);
            assert_contains('sarah', $team);
            assert_contains('david', $team);
            assert_contains('Pending', $team);

            assert_same(url('genealogy'), $payload['meta']['canonical']);
        } finally {
            db()->prepare('UPDATE users SET username = ? WHERE id = ?')->execute(['maria', $mariaId]);
        }
    },

    'the genealogy feed clamps the recruits page and renders the empty states' => function () use ($makeMember): void {
        $viewer   = $makeMember();
        $viewerId = (int) $viewer['id'];

        for ($i = 1; $i <= 6; $i++) {
            $recruit = $makeMember('mgl_rec_' . $i . '_' . bin2hex(random_bytes(2)));

            db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
                ->execute([$viewerId, (int) $recruit['id']]);
        }

        test_signed_in_as($viewer);

        $_GET = ['page' => '99', 'per_page' => '5'];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/genealogy?page=99&per_page=5';

        $controller = new GenealogyController();
        $payload    = $controller->feedPayload(
            1,
            '"member.genealogy:' . $viewerId . ':1:def"',
            $controller->genealogyContext(Member::find($viewerId))
        );

        $header = $payload['fragments']['genealogy-header'];
        $teamChain = $payload['fragments']['genealogy-team-chain'];
        $count  = $payload['fragments']['genealogy-recruits-count'];
        $rows   = $payload['fragments']['genealogy-recruits-rows'];
        $team   = $payload['fragments']['genealogy-team'];

        assert_contains('6 in team', $header);
        assert_contains('1 level', $header);
        assert_contains('· you', $teamChain, 'the self row still renders at the top');
        assert_contains('Direct recruits', $teamChain);
        assert_contains('6 members', $teamChain);
        assert_contains('>6<', $count);
        assert_contains('data-live-key=', $rows);
        assert_not_contains('No direct recruits yet', $rows);
        assert_contains('No second-level members yet', $team);

        assert_same(2, $payload['meta']['page']);
        assert_same(2, $payload['meta']['pages']);
        assert_same(6, $payload['meta']['total']);
        assert_contains('page=2', (string) $payload['meta']['canonical']);
        assert_contains('per_page=5', (string) $payload['meta']['canonical']);
    },

    'the team chain shows an empty state for a member with no team' => function () use ($makeMember): void {
        $viewer   = $makeMember();
        $viewerId = (int) $viewer['id'];

        test_signed_in_as($viewer);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/genealogy';

        $controller = new GenealogyController();
        $payload    = $controller->feedPayload(
            2,
            '"member.genealogy:' . $viewerId . ':2:abc"',
            $controller->genealogyContext(Member::find($viewerId))
        );

        $teamChain = $payload['fragments']['genealogy-team-chain'];

        assert_contains('· you', $teamChain);
        assert_contains('No team yet', $teamChain);
        assert_not_contains('Direct recruits', $teamChain, 'no level rows when the team is empty');
    },

    'the genealogy page declares its live surface and regions' => function (): void {
        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        $juanId = (int) $juan['id'];

        test_signed_in_as($juan);

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ecowealth/system/genealogy';

        $controller = new GenealogyController();
        $data       = $controller->genealogyContext(Member::find($juanId));

        ob_start();

        try {
            View::page('member', 'member/genealogy', $data + [
                'page_title'  => 'Genealogy',
                'current_nav' => 'genealogy',
                'page_css'    => ['assets/css/membership.css'],
                'live'        => Live::meta(Live::genealogySurface($juanId), 'page'),
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        assert_contains('data-live-surface="member.genealogy:' . $juanId . '"', $html);
        assert_contains('data-live-enabled="1"', $html);
        assert_contains('data-live-interval="30000"', $html);
        assert_contains('data-live-region="genealogy-header"', $html);
        assert_contains('data-live-region="genealogy-team-chain"', $html);
        assert_contains('data-live-region="genealogy-recruits-count"', $html);
        assert_contains('data-live-region="genealogy-recruits-rows"', $html);
        assert_contains('data-live-region="genealogy-recruits-pagination"', $html);
        assert_contains('data-live-region="genealogy-team"', $html);
        assert_contains('data-live-status', $html);
        assert_not_contains('data-live-stale', $html, 'the page has no held form region');
        assert_not_contains('data-live-hold', $html);
        assert_contains('id="genealogyReferral"', $html);
        assert_contains('data-copy=', $html);
        assert_contains('Rows per page', $html);
    },
];
