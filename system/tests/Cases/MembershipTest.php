<?php
declare(strict_types=1);

/* ==============================================================
 *  MembershipTest — registration, codes, activation and members
 *  (plan §7 Phase 9, tests P9-T1 … P9-T18)
 * ============================================================== */

/** A registration payload with unique credentials. */
$payload = static function (array $overrides = []): array {
    $username = $overrides['username'] ?? ('reg_' . bin2hex(random_bytes(4)));

    return $overrides + [
        'mode'             => 'standard',
        'username'         => $username,
        'email'            => $username . '@test.local',
        'full_name'        => 'Registration Test',
        'phone'            => '09171234567',
        'password'         => 'Member@1234',
        'password_confirm' => 'Member@1234',
        'sponsor'          => 'juan',
        'package_id'       => '',
        'reg_code'         => '',
        'terms'            => true,
    ];
};

$admin     = static fn (): array => test_user('ms_admin', 'admin');
$packageA  = static fn (): array => Package::findBySlug('package-a');
$codes     = static fn (): CodeService => new CodeService();
$members   = static fn (): RegistrationService => new RegistrationService();
$activation = static fn (): ActivationService => new ActivationService();
$wallet     = static fn (): WalletService => new WalletService();

/** Issue one unused code for Package A. */
$issueCode = static function (int $quantity = 1): array {
    return (new CodeService())->generate([
        'package_id' => (int) Package::findBySlug('package-a')['id'],
        'quantity'   => $quantity,
        'price'      => '4498.00',
        'expires_at' => date('Y-m-d', strtotime('+30 days')),
        'code_type'  => 'registration',
    ], test_user('ms_code_admin', 'admin'));
};

/** Create a pending member sponsored by juan. */
$pendingMember = static function (): array {
    $result = (new RegistrationService())->register([
        'mode'             => 'pending',
        'username'         => 'pending_' . bin2hex(random_bytes(4)),
        'email'            => 'pending_' . bin2hex(random_bytes(4)) . '@test.local',
        'full_name'        => 'Pending Member',
        'phone'            => '',
        'password'         => 'Member@1234',
        'password_confirm' => 'Member@1234',
        'sponsor'          => 'juan',
        'package_id'       => '',
        'reg_code'         => '',
        'terms'            => true,
    ], null);

    return Member::find((int) $result['user_id']) ?? [];
};

/** A real uploaded-file fixture that Upload::store can accept in tests. */
$proofFile = static function (): array {
    $tmp = (string) sys_get_temp_dir() . '/eco-activation-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($tmp, TEST_PNG_BYTES);

    return [
        'name'     => 'proof.png',
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen(TEST_PNG_BYTES),
    ];
};

return [
    /* ---------- Seed / demo tree (P9-T6, P9-T17) ------------------- */

    'P9-T6 the demo tree seeds a sponsor chain without binary links' => function (): void {
        $juan  = Member::findByUsername('juan');
        $maria = Member::findByUsername('maria');
        $peter = Member::findByUsername('peter');
        $sarah = Member::findByUsername('sarah');
        $david = Member::findByUsername('david');

        assert_not_null($juan);
        assert_not_null($maria);
        assert_not_null($peter);
        assert_not_null($sarah);
        assert_not_null($david);

        assert_same('active', $juan['member_status']);
        assert_same('Package A', $juan['package_name']);

        assert_same('juan', $maria['sponsor_username']);
        assert_same('maria', $peter['sponsor_username']);
        assert_same('maria', $sarah['sponsor_username']);
        assert_same('maria', $david['sponsor_username']);
        assert_same('pending', $david['member_status']);
        assert_null($david['package_id']);

        /* Every account has a unique 8-character referral code. */
        foreach ([$juan, $maria, $peter, $sarah, $david] as $member) {
            assert_same(8, strlen((string) $member['referral_code']));
        }

        /* The demo wallets reconcile with their ledger rows. */
        $report = (new WalletService())->reconcile();
        assert_same([], $report['mismatches'], 'the demo wallets must reconcile: ' . json_encode($report['mismatches']));
        assert_same([], $report['violations']);

        assert_same(2000.0, (new WalletService())->balance((int) $juan['id']));
        assert_same(1500.0, (new WalletService())->withdrawable((int) $juan['id']));
    },

    'P9-T14 the demo codes cover unused and expired states' => function (): void {
        $unused  = RegCode::findByCode('DEMO-PKGA-2A7K');
        $expired = RegCode::findByCode('DEMO-EXPD-4C9M');

        assert_not_null($unused);
        assert_same('unused', $unused['status']);
        assert_same('Package A', $unused['package_name']);

        assert_not_null($expired);
        assert_same('expired', $expired['status']);

        $check = (new CodeService())->validate('demo-pkga-2a7k');
        assert_true($check['valid'], 'codes compare case-insensitively and without dashes');
    },

    /* ---------- P9-T1: standard registration ---------------------- */

    'P9-T1 standard registration creates an active member without a package' => function () use ($payload, $members): void {
        $result = $members()->register($payload(['mode' => 'standard']), null);

        $member = Member::find((int) $result['user_id']);

        assert_same('active', $member['member_status']);
        assert_null($member['package_id']);
        assert_same('standard', $member['reg_payment_method']);
        assert_same('active', $result['member_status']);
        assert_same(8, strlen((string) $member['referral_code']));
        assert_same((int) Member::findByUsername('juan')['id'], (int) $member['sponsor_id']);
        assert_null($result['activation_id']);
        assert_false($result['sponsor_defaulted']);
    },

    'P9-T1 a blank sponsor joins the company account (primary admin)' => function () use ($payload, $members): void {
        $admin = Member::primaryAdmin();
        assert_not_null($admin);

        $result = $members()->register($payload([
            'mode'    => 'standard',
            'sponsor' => '',
        ]), null);

        $member = Member::find((int) $result['user_id']);

        assert_same((int) $admin['id'], (int) $member['sponsor_id'], 'blank sponsor defaults to the primary admin');
        assert_same('admin', (string) $member['sponsor_username']);
        assert_true($result['sponsor_defaulted'], 'the summary says the sponsor was defaulted');
        assert_same('active', $member['member_status']);

        /* Every registration mode accepts the blank sponsor. */
        $pending = $members()->register($payload([
            'mode'    => 'pending',
            'sponsor' => '   ',
        ]), null);

        $pendingMember = Member::find((int) $pending['user_id']);
        assert_same('pending', $pendingMember['member_status']);
        assert_same((int) $admin['id'], (int) $pendingMember['sponsor_id']);

        /* The audit entry records that the default was used. */
        $audits   = AuditLog::forSubject('user', (int) $member['id'], 5);
        $register = $audits[0] ?? [];
        $meta     = json_decode((string) ($register['meta_json'] ?? ''), true);

        assert_same('member.register', (string) ($register['action'] ?? ''));
        assert_same(true, $meta['sponsor_defaulted'] ?? null, 'the audit trail records the defaulted sponsor');
    },

    'P9-T1 the default sponsor is the primary admin' => function (): void {
        $sponsors = new SponsorService();
        $admin    = Member::primaryAdmin();

        assert_not_null($admin);

        $default = $sponsors->defaultSponsor();

        assert_not_null($default);
        assert_same((int) $admin['id'], (int) $default['id']);
        assert_true($sponsors->isEligible($default));
    },

    'P9-T1 free registration can be closed by the setting' => function () use ($payload, $members): void {
        setting_set('free_registration_enabled', '0');

        try {
            $error = assert_throws(
                static fn () => $members()->register($payload(['mode' => 'standard']), null),
                RegistrationException::class
            );

            assert_same('validation', $error->reason);
            assert_true(isset($error->fieldErrors['mode']));
        } finally {
            setting_set('free_registration_enabled', '1');
            setting_cache_clear();
        }
    },

    /* ---------- P9-T2 / P9-T3: code registration ------------------- */

    'P9-T2 registering with a valid code activates the code package' => function () use ($payload, $members, $issueCode): void {
        $batch = $issueCode();
        $code  = $batch['codes'][0];

        $result = $members()->register($payload([
            'mode'     => 'code',
            'reg_code' => $code,
        ]), null);

        $member = Member::find((int) $result['user_id']);
        $row    = RegCode::findByCode($code);

        assert_same('active', $member['member_status']);
        assert_same('Package A', $member['package_name']);
        assert_same('code', $member['reg_payment_method']);
        assert_same($member['package_id'], $row['package_id']);

        assert_same('used', $row['status']);
        assert_same((int) $member['id'], (int) $row['used_by']);
        assert_not_null($row['used_at']);

        $activation = Activation::find((int) $result['activation_id']);
        assert_same('approved', $activation['status']);
        assert_same('code', $activation['payment_method']);
        assert_same((int) $member['package_id'], (int) $activation['package_id']);
        assert_not_null($activation['commission_processed_at']);
    },

    'P9-T3 an invalid, used or expired code is refused and no account is written' => function () use ($payload, $members, $issueCode): void {
        $probeUsernames = [];

        $attempt = static function (array $overrides) use ($payload, &$probeUsernames): array {
            $input = $payload($overrides);
            $probeUsernames[] = $input['username'];

            return $input;
        };

        /* Invalid format. */
        $invalid = assert_throws(
            static fn () => $members()->register($attempt(['mode' => 'code', 'reg_code' => 'NOPE']), null),
            RegistrationException::class
        );
        assert_true(isset($invalid->fieldErrors['reg_code']));

        /* Unknown but well-formed code. */
        $unknown = assert_throws(
            static fn () => $members()->register($attempt(['mode' => 'code', 'reg_code' => 'ZZZZ-ZZZZ-ZZZZ']), null),
            RegistrationException::class
        );
        assert_same('code', $unknown->reason);

        /* Used code. */
        $batch = $issueCode();
        $used  = $batch['codes'][0];

        $first = $members()->register($attempt(['mode' => 'code', 'reg_code' => $used]), null);
        assert_true((int) $first['user_id'] > 0);

        $reused = assert_throws(
            static fn () => $members()->register($attempt(['mode' => 'code', 'reg_code' => $used]), null),
            RegistrationException::class
        );
        assert_same('code', $reused->reason);

        /* Expired code: past the date but still marked unused in the row. */
        $expiredBatch = $issueCode();
        $expiredCode  = $expiredBatch['codes'][0];

        db()->prepare("UPDATE reg_codes SET expires_at = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE code = ?")
            ->execute([$expiredCode]);

        $expired = assert_throws(
            static fn () => $members()->register($attempt(['mode' => 'code', 'reg_code' => $expiredCode]), null),
            RegistrationException::class
        );
        assert_same('code', $expired->reason);

        $row = RegCode::findByCode($expiredCode);
        assert_same('expired', $row['status'], 'validation flips a past-dated unused code to expired');

        /* Exactly one of the attempted accounts exists: the successful one. */
        $marks = implode(', ', array_fill(0, count($probeUsernames), '?'));
        $stmt  = db()->prepare('SELECT COUNT(*) FROM users WHERE username IN (' . $marks . ')');
        $stmt->execute($probeUsernames);

        assert_same(1, (int) $stmt->fetchColumn(), 'only the valid registration may write an account');
    },

    /* ---------- P9-T4 / P9-T5: e-wallet registration -------------- */

    'P9-T4 an e-wallet registration debits the registrar once and credits the admin' => function () use ($payload, $members, $wallet, $packageA): void {
        $sponsor = test_user('ms_sponsor_wallet', 'member');
        $wallet()->credit((int) $sponsor['id'], '10000.00', 'commission', random_int(100000, 999999), 'Funding');

        $admin      = Member::primaryAdmin();
        $adminBefore = $wallet()->balance((int) $admin['id']);
        $payerBefore = $wallet()->balance((int) $sponsor['id']);
        $price       = (float) $packageA()['price'];

        $result = $members()->register($payload([
            'mode'       => 'ewallet',
            'sponsor'    => (string) $sponsor['username'],
            'package_id' => (string) $packageA()['id'],
        ]), $sponsor);

        $member = Member::find((int) $result['user_id']);

        assert_same('active', $member['member_status']);
        assert_same('Package A', $member['package_name']);
        assert_same('ewallet', $member['reg_payment_method']);
        assert_same((int) $sponsor['id'], (int) $member['reg_paid_by']);

        assert_same($payerBefore - $price, $wallet()->balance((int) $sponsor['id']), 'the payer is debited once');
        assert_same($adminBefore + $price, $wallet()->balance((int) $admin['id']), 'the admin is credited as revenue');

        /* Exactly one debit and one revenue credit, both under 'registration'. */
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM ewallet_ledger
              WHERE ref_type = 'registration' AND ref_id = ?"
        );
        $stmt->execute([(int) $result['activation_id']]);
        assert_same(2, (int) $stmt->fetchColumn());

        /* The registrar's session is untouched: the new user is not signed in. */
        assert_same(null, $_SESSION['user_id'] ?? null, 'a registrar stays the signed-in user');
    },

    'P9-T5 a guest cannot register someone from an e-wallet' => function () use ($payload, $members, $packageA): void {
        $before = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $error = assert_throws(
            static fn () => $members()->register($payload([
                'mode'       => 'ewallet',
                'package_id' => (string) $packageA()['id'],
            ]), null),
            RegistrationException::class
        );

        assert_true(in_array($error->reason, ['validation', 'login_required'], true));
        assert_same($before, (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        assert_same(0, (int) db()->query("SELECT COUNT(*) FROM ewallet_ledger WHERE ref_type = 'registration'")->fetchColumn());
    },

    /* ---------- P9-T6 / P9-T7 / P9-T8: pending + validation -------- */

    'P9-T6 a referral signup resolves the sponsor by code and stays pending' => function () use ($payload, $members): void {
        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        $result = $members()->register($payload([
            'mode'    => 'pending',
            'sponsor' => (string) $juan['referral_code'],
        ]), null);

        $member = Member::find((int) $result['user_id']);

        assert_same('pending', $member['member_status']);
        assert_null($member['package_id']);
        assert_same('pending', $member['reg_payment_method']);
        assert_same((int) $juan['id'], (int) $member['sponsor_id']);
        assert_same(8, strlen((string) $member['referral_code']));
    },

    'P9-T7 an unknown or self sponsor is refused without an account' => function () use ($payload, $members): void {
        /* Helpers first: they add rows of their own. */
        $admin     = test_user('ms_admin', 'admin');
        $suspended = test_user('ms_suspended_sponsor', 'member');

        Member::setStatus((int) $suspended['id'], 'suspended', $admin, 'Test');

        $before = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $unknown = assert_throws(
            static fn () => $members()->register($payload(['sponsor' => 'nobody_here']), null),
            RegistrationException::class
        );
        assert_true(isset($unknown->fieldErrors['sponsor']));

        /* The new username is its own sponsor. */
        $self = assert_throws(
            static fn () => $members()->register($payload([
                'mode'     => 'standard',
                'username' => 'self_sponsor_probe',
                'sponsor'  => 'self_sponsor_probe',
            ]), null),
            RegistrationException::class
        );
        assert_true(isset($self->fieldErrors['sponsor']));

        /* A suspended sponsor cannot recruit. */
        $blocked = assert_throws(
            static fn () => $members()->register($payload(['sponsor' => 'ms_suspended_sponsor']), null),
            RegistrationException::class
        );
        assert_same('sponsor', $blocked->reason);

        assert_same($before, (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    },

    'P9-T8 duplicate usernames and emails are field errors' => function () use ($payload, $members): void {
        $existing = test_user('ms_duplicate', 'member');
        $before   = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $username = assert_throws(
            static fn () => $members()->register($payload(['username' => (string) $existing['username']]), null),
            RegistrationException::class
        );
        assert_true(isset($username->fieldErrors['username']));

        $email = assert_throws(
            static fn () => $members()->register($payload(['email' => (string) $existing['email']]), null),
            RegistrationException::class
        );
        assert_true(isset($email->fieldErrors['email']));

        $weak = assert_throws(
            static fn () => $members()->register($payload(['password' => 'short', 'password_confirm' => 'short']), null),
            RegistrationException::class
        );
        assert_true(isset($weak->fieldErrors['password']));

        assert_same($before, (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    },

    /* ---------- P9-T9: activation by wallet ----------------------- */

    'P9-T9 activation by e-wallet debits once and invokes the hook once' => function () use ($pendingMember, $wallet, $activation, $packageA): void {
        $member = $pendingMember();
        $id     = (int) $member['id'];

        $wallet()->credit($id, '5000.00', 'topup', random_int(100000, 999999), 'Activation funds', false);

        $adminBefore = $wallet()->balance((int) Member::primaryAdmin()['id']);
        $before      = $wallet()->balance($id);
        $invocations = count(CommissionService::invocations());

        $result = $activation()->request($id, [
            'method'     => 'ewallet',
            'package_id' => (string) $packageA()['id'],
        ]);

        $fresh   = Member::find($id);
        $charged = $before - $wallet()->balance($id);

        assert_same('approved', $result['status']);
        assert_same('active', $fresh['member_status']);
        assert_same('Package A', $fresh['package_name']);
        assert_not_null($fresh['activated_at']);
        assert_same((float) $packageA()['price'], $charged, 'the wallet is debited exactly once');

        assert_same(
            $adminBefore + (float) $packageA()['price'],
            $wallet()->balance((int) Member::primaryAdmin()['id']),
            'the admin receives the package revenue'
        );

        $activationRow = Activation::find((int) $result['activation_id']);
        assert_not_null($activationRow['commission_processed_at']);
        assert_same($invocations + 1, count(CommissionService::invocations()));
        assert_true(CommissionService::wasInvokedFor((int) $result['activation_id']));
    },

    /* ---------- P9-T10 / P9-T11: manual proof review -------------- */

    'P9-T10 a manual proof waits for review and approval activates the member' => function () use ($pendingMember, $activation, $proofFile, $packageA): void {
        $member = $pendingMember();
        $id     = (int) $member['id'];

        $result = $activation()->request($id, [
            'method'       => 'manual',
            'package_id'   => (string) $packageA()['id'],
            'channel'      => 'gcash',
            'reference_no' => 'GC-99887766',
        ], $proofFile());

        assert_same('pending_review', $result['status']);

        $pending = Activation::find((int) $result['activation_id']);
        assert_same('pending_review', $pending['status']);
        assert_same('gcash', $pending['payment_method']);
        assert_same('GC-99887766', $pending['reference_no']);
        assert_not_null($pending['proof_path']);
        assert_same('pending', Member::find($id)['member_status'], 'the member is still pending');

        $admin = test_user('ms_admin', 'admin');

        $approved = $activation()->approve((int) $result['activation_id'], $admin);

        $fresh = Member::find($id);
        assert_same('approved', $approved['status']);
        assert_same('active', $fresh['member_status']);
        assert_same('Package A', $fresh['package_name']);
        assert_same((int) $admin['id'], (int) Activation::find((int) $result['activation_id'])['reviewed_by']);
        assert_true(CommissionService::wasInvokedFor((int) $result['activation_id']));
    },

    'P9-T11 rejection returns the member to pending with a reason and no debit' => function () use ($pendingMember, $activation, $wallet, $proofFile, $packageA): void {
        $member = $pendingMember();
        $id     = (int) $member['id'];

        $result = $activation()->request($id, [
            'method'       => 'manual',
            'package_id'   => (string) $packageA()['id'],
            'channel'      => 'maya',
            'reference_no' => 'MY-11223344',
        ], $proofFile());

        $admin  = test_user('ms_admin', 'admin');
        $before = $wallet()->balance($id);

        /* A reason is mandatory. */
        $missing = assert_throws(
            static fn () => $activation()->reject((int) $result['activation_id'], $admin, '  '),
            ActivationException::class
        );
        assert_true(isset($missing->fieldErrors['reject_reason']));

        $rejected = $activation()->reject((int) $result['activation_id'], $admin, 'The reference does not match our records.');

        $row = Activation::find((int) $result['activation_id']);

        assert_same('rejected', $rejected['status']);
        assert_same('rejected', $row['status']);
        assert_contains('does not match', (string) $row['reject_reason']);
        assert_same('pending', Member::find($id)['member_status']);
        assert_same($before, $wallet()->balance($id), 'a rejected manual activation never touches the wallet');

        /* A rejected request may be retried. */
        $retry = $activation()->request($id, [
            'method'       => 'manual',
            'package_id'   => (string) $packageA()['id'],
            'channel'      => 'maya',
            'reference_no' => 'MY-11223345',
        ], $proofFile());

        assert_same('pending_review', $retry['status']);
    },

    /* ---------- P9-T12: double activation / approval -------------- */

    'P9-T12 double activation and double approval are refused' => function () use ($pendingMember, $activation, $wallet, $proofFile, $packageA): void {
        $member = $pendingMember();
        $id     = (int) $member['id'];

        $result = $activation()->request($id, [
            'method'       => 'manual',
            'package_id'   => (string) $packageA()['id'],
            'channel'      => 'bank',
            'reference_no' => 'BN-55667788',
        ], $proofFile());

        /* A second open request is refused. */
        $open = assert_throws(
            static fn () => $activation()->request($id, [
                'method'       => 'manual',
                'package_id'   => (string) $packageA()['id'],
                'channel'      => 'bank',
                'reference_no' => 'BN-55667789',
            ], $proofFile()),
            ActivationException::class
        );
        assert_same('open', $open->reason);

        $admin = test_user('ms_admin', 'admin');
        $activation()->approve((int) $result['activation_id'], $admin);

        $count   = count(CommissionService::invocations());
        $balance = $wallet()->balance($id);

        /* Approving again changes nothing and never runs the hook twice. */
        $again = assert_throws(
            static fn () => $activation()->approve((int) $result['activation_id'], $admin),
            ActivationException::class
        );
        assert_true(in_array($again->reason, ['status', 'processed'], true));
        assert_same($count, count(CommissionService::invocations()));
        assert_same($balance, $wallet()->balance($id));

        /* An active member cannot activate again. */
        $second = assert_throws(
            static fn () => $activation()->request($id, [
                'method'     => 'ewallet',
                'package_id' => (string) $packageA()['id'],
            ]),
            ActivationException::class
        );
        assert_same('already_active', $second->reason);
        assert_same(1, (int) db()->query(
            'SELECT COUNT(*) FROM activations WHERE user_id = ' . $id . " AND status = 'approved'"
        )->fetchColumn());
    },

    'P9-T12 a standard member cannot self-activate (plan §7 rule)' => function () use ($pendingMember, $activation, $packageA): void {
        $member = $pendingMember();

        /* A standard member is active without a package. */
        db()->prepare("UPDATE users SET member_status = 'active' WHERE id = ?")->execute([(int) $member['id']]);

        $error = assert_throws(
            static fn () => $activation()->request((int) $member['id'], [
                'method'     => 'ewallet',
                'package_id' => (string) $packageA()['id'],
            ]),
            ActivationException::class
        );

        assert_same('standard', $error->reason);
    },

    /* ---------- P9-T13: code redemption race ---------------------- */

    'P9-T13 two parallel redemptions use a code exactly once' => function () use ($payload, $issueCode): void {
        /* A row lock can only be observed across connections: commit the
         * harness transaction and clean up in finally (the cart/order
         * concurrency cases use the same pattern). */
        $main = db();

        if ($main->inTransaction()) {
            $main->commit();
        }

        $batch = $issueCode();
        $code  = $batch['codes'][0];

        $first  = 'race_a_' . bin2hex(random_bytes(3));
        $second = 'race_b_' . bin2hex(random_bytes(3));

        $pdoA = test_pdo(['db' => DB_NAME]);
        $pdoB = test_pdo(['db' => DB_NAME]);
        $pdoA->exec('SET SESSION innodb_lock_wait_timeout = 2');
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $base = $payload(['mode' => 'code', 'reg_code' => $code]);

            /* A holds the code row lock without committing (the service
             * adopts an existing transaction with a savepoint). */
            $pdoA->beginTransaction();
            $serviceA = new RegistrationService($pdoA);
            $resultA  = $serviceA->register(array_merge($base, [
                'username' => $first,
                'email'    => $first . '@test.local',
            ]), null);

            assert_true((int) $resultA['user_id'] > 0, 'connection A registers');

            /* B blocks on the same code and times out (1205), so its
             * signup rolls back and no second account appears. */
            $serviceB = new RegistrationService($pdoB);
            $blocked  = assert_throws(
                static fn () => $serviceB->register(array_merge($base, [
                    'username' => $second,
                    'email'    => $second . '@test.local',
                ]), null),
                PDOException::class
            );
            assert_contains('1205', (string) $blocked->getCode() . ' ' . $blocked->getMessage());

            /* A commits: exactly one redemption now exists. */
            $pdoA->commit();

            $refused = assert_throws(
                static fn () => $serviceB->register(array_merge($base, [
                    'username' => $second,
                    'email'    => $second . '@test.local',
                ]), null),
                RegistrationException::class
            );

            assert_same('code', $refused->reason);
            assert_same(1, (int) $pdoB->query(
                "SELECT COUNT(*) FROM reg_codes WHERE code = '" . $code . "' AND status = 'used'"
            )->fetchColumn());
            assert_same(0, (int) $pdoB->query(
                "SELECT COUNT(*) FROM users WHERE username = '" . $second . "'"
            )->fetchColumn());
        } finally {
            try {
                if ($pdoA->inTransaction()) {
                    $pdoA->rollBack();
                }
            } catch (Throwable) {
                /* Already committed / rolled back. */
            }

            $pdoA->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute([$first, $second]);
            $pdoA->prepare('DELETE FROM reg_codes WHERE code = ?')->execute([$code]);
        }
    },

    /* ---------- P9-T14: code generation, expiry, export ----------- */

    'P9-T14 generation is transactional and stats follow the states' => function () use ($issueCode): void {
        $statsBefore = RegCode::stats();

        $batch = $issueCode(10);

        assert_count(10, $batch['codes']);
        assert_same(10, $batch['batch']);
        assert_same('4498.00', $batch['price']);

        /* Every code is unique and in the printed format. */
        assert_count(10, array_unique($batch['codes']));

        foreach ($batch['codes'] as $code) {
            assert_same(1, preg_match(RegCode::PATTERN, $code), 'code format: ' . $code);
        }

        $statsAfter = RegCode::stats();
        assert_same($statsBefore['total'] + 10, $statsAfter['total']);
        assert_same($statsBefore['unused'] + 10, $statsAfter['unused']);

        /* Expire one through the sweep and filter the export. */
        db()->prepare("UPDATE reg_codes SET expires_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE code = ?")
            ->execute([$batch['codes'][0]]);

        assert_true((new CodeService())->expireSweep() >= 1);

        $expired = RegCode::findByCode($batch['codes'][0]);
        assert_same('expired', $expired['status']);

        $filtered = RegCode::exportRows(RegCode::normaliseFilters(['status' => 'expired', 'q' => $batch['codes'][0]]));
        assert_count(1, $filtered);
        assert_same((string) $expired['code'], (string) $filtered[0]['code']);
    },

    'P9-T14 invalid batches are refused and write nothing' => function (): void {
        $before = RegCode::stats();
        $admin  = test_user('ms_code_admin', 'admin');

        foreach ([
            ['quantity' => 0],
            ['quantity' => 501],
            ['price' => '-1'],
            ['price' => '1.234'],
            ['expires_at' => '2020-01-01'],
            ['code_type' => 'upgrade'],
            ['package_id' => 999999],
        ] as $override) {
            $input = $override + [
                'package_id' => (string) Package::findBySlug('package-a')['id'],
                'quantity'   => '5',
                'price'      => '4498.00',
                'expires_at' => '',
                'code_type'  => 'registration',
            ];

            assert_throws(
                static fn () => (new CodeService())->generate($input, $admin),
                CodeException::class,
                'invalid batch must be refused: ' . json_encode($override)
            );
        }

        assert_same($before, RegCode::stats(), 'no invalid batch may write a row');
    },

    /* ---------- P9-T15: access control ---------------------------- */

    'P9-T15 the code and member routes are admin-guarded and the binary routes are gone' => function (): void {
        $routes = require dirname(__DIR__, 2) . '/app/routes.php';
        $guards = [];

        foreach ($routes as [$method, $path, , $guard]) {
            $guards[$method . ' ' . $path] = $guard;
        }

        foreach ([
            'GET /admin/codes',
            'POST /admin/codes/generate',
            'GET /admin/codes/export',
            'GET /admin/members',
            'GET /admin/members/{id}',
            'POST /admin/members/{id}/status',
            'GET /admin/activations',
            'POST /admin/activations/{id}/approve',
            'POST /admin/activations/{id}/reject',
            'GET /admin/activations/{id}/proof',
        ] as $route) {
            assert_same('admin', $guards[$route] ?? null, $route . ' must be admin-guarded');
        }

        /* Every public validation endpoint is `any`, and only those. */
        foreach ([
            'POST /register/validate-code',
            'POST /register/check-username',
            'POST /register/check-sponsor',
            'GET /register/packages',
        ] as $route) {
            assert_same('any', $guards[$route] ?? null, $route . ' must be public');
        }

        /* No binary-upline routing survives. */
        foreach (['POST /register/check-upline', 'POST /register/auto-select-upline', 'GET /api/binary-tree'] as $route) {
            assert_false(array_key_exists($route, $guards), $route . ' must not exist');
        }
    },

    /* ---------- P9-T17: genealogy --------------------------------- */

    'P9-T17 the upline chain walks sponsor_id to the top' => function (): void {
        $maria = Member::findByUsername('maria');
        assert_not_null($maria);

        $chain = (new SponsorService())->chain((int) $maria['id']);

        assert_count(2, $chain);
        assert_same('juan', $chain[0]['username']);
        assert_same(1, $chain[0]['level']);
        assert_same('admin', $chain[1]['username']);
        assert_same(2, $chain[1]['level']);
    },

    'P9-T17 the downline is grouped by level with no duplicates' => function (): void {
        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        $team = (new SponsorService())->downline((int) $juan['id']);

        $usernames = [];
        $levels    = [];

        foreach ($team as $row) {
            $usernames[] = (string) $row['username'];
            $levels[(string) $row['username']] = (int) $row['level'];
        }

        assert_same(count($usernames), count(array_unique($usernames)), 'no member may appear twice');
        assert_same(1, $levels['maria'] ?? 0);
        assert_same(2, $levels['peter'] ?? 0);
        assert_same(2, $levels['sarah'] ?? 0);
        assert_same(2, $levels['david'] ?? 0);

        $stats = (new SponsorService())->teamStats((int) $juan['id']);
        assert_same(1, $stats['direct']);
        assert_same(4, $stats['team']);
        assert_same(2, $stats['levels']);
    },

    'P9-T17 a corrupted cycle cannot loop the chain or the downline' => function (): void {
        $juan  = Member::findByUsername('juan');
        $admin = Member::findByUsername('admin');
        assert_not_null($juan);
        assert_not_null($admin);

        $sponsors = new SponsorService();

        /* admin sponsors juan today; make juan sponsor admin → a cycle. */
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')->execute([(int) $juan['id'], (int) $admin['id']]);

        try {
            $chain = $sponsors->chain((int) $admin['id'], 10);
            assert_true(count($chain) <= 10, 'the chain is bounded');

            $team = $sponsors->downline((int) $juan['id']);
            assert_true(count($team) <= 40, 'the downline walk is bounded');

            assert_true($sponsors->createsCycle((int) $juan['id'], (int) $admin['id']));
            assert_true($sponsors->createsCycle((int) $juan['id'], (int) $juan['id']));
        } finally {
            /* admin is the company root: no sponsor again. */
            db()->prepare('UPDATE users SET sponsor_id = NULL WHERE id = ?')->execute([(int) $admin['id']]);
        }
    },

    'P9-T17 no binary column is referenced anywhere in the app' => function (): void {
        $root     = dirname(__DIR__, 2) . '/app/';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $offenders = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/\b(binary_parent_id|binary_position|binary_upline|binary_tree|pairs_paid|left_count|right_count)\b/', $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        assert_same([], $offenders, 'no binary column may be referenced');
    },

    'P9-T17 the schema carries no binary columns' => function (): void {
        $columns = array_column(db()->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');

        foreach ($columns as $column) {
            assert_not_contains('binary_', (string) $column);
            assert_not_contains('pairs_', (string) $column);
        }

        assert_false(in_array('left_count', $columns, true));
        assert_false(in_array('right_count', $columns, true));
    },

    /* ---------- P9-T16: member status changes --------------------- */

    'P9-T16 suspend blocks sign-in and restore returns the right status' => function (): void {
        $admin  = test_user('ms_admin', 'admin');
        $member = test_user('ms_toggle_member', 'member');

        $suspended = Member::setStatus((int) $member['id'], 'suspended', $admin, 'Policy review');

        assert_same('suspended', $suspended['member_status']);
        assert_same(0, (int) $suspended['is_active']);

        /* auth_login refuses the suspended account even before is_active. */
        db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([(int) $member['id']]);
        assert_false(auth_login((string) $member['username'], 'Member@123', false));

        $restored = Member::toggleStatus((int) $member['id'], $admin);
        assert_same('active', $restored['member_status']);
        assert_same(1, (int) $restored['is_active']);

        /* A pending member restores to pending, not active. */
        $pending = Member::findByUsername('david');
        assert_not_null($pending);

        Member::setStatus((int) $pending['id'], 'suspended', $admin, 'Test');
        $back = Member::setStatus((int) $pending['id'], 'active', $admin, 'Restore');

        assert_same('pending', $back['member_status']);

        /* The change is audited. */
        $audits = AuditLog::forSubject('user', (int) $member['id'], 20);
        $actions = array_column($audits, 'action');
        assert_true(in_array('member.status', $actions, true));
    },

    'P9-T16 staff accounts are not member rows' => function (): void {
        $admin = test_user('ms_admin', 'admin');

        assert_throws(
            static fn () => Member::setStatus((int) $admin['id'], 'suspended', $admin, 'Test'),
            InvalidArgumentException::class
        );

        $stats = Member::stats();
        $list  = Member::adminList(Member::normaliseFilters(['q' => 'ms_admin']), new Paginator(20, 20, 1));

        assert_same([], $list, 'the member directory lists members only');
        assert_true($stats['total'] >= 1);
    },

    /* ---------- P9-T18: sponsor helpers --------------------------- */

    'P9-T18 referral codes are unique eight-character values' => function (): void {
        $sponsors = new SponsorService();
        $seen     = [];

        for ($i = 0; $i < 25; $i++) {
            $code = $sponsors->generateReferralCode();

            assert_same(8, strlen($code));
            assert_same(1, preg_match('/^[A-Z0-9]{8}$/', $code), 'code format: ' . $code);
            assert_false(isset($seen[$code]), 'codes must be unique within a run');
            $seen[$code] = true;
            assert_false(Member::referralCodeExists($code));

            /* Claim it so the next call cannot return it again. */
            db()->prepare("INSERT INTO users (username, email, password_hash, referral_code)
                           VALUES (?, ?, 'x', ?)")
                ->execute(['code_' . strtolower($code), strtolower($code) . '@test.local', $code]);
        }

        /* resolve() finds a sponsor by username or by code. */
        $first = Member::findByUsername('juan');

        assert_same(
            (int) $first['id'],
            (int) (new SponsorService())->resolve((string) $first['referral_code'])['id']
        );
        assert_same(
            (int) $first['id'],
            (int) (new SponsorService())->resolve((string) $first['username'])['id']
        );
        assert_null((new SponsorService())->resolve('no-such-sponsor'));
    },

    'P9-T16 activation history and recruits page with the shared pager' => function (): void {
        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        $historyMember = test_user('ms_history_member', 'member');

        for ($i = 1; $i <= 3; $i++) {
            Activation::create([
                'user_id'        => (int) $historyMember['id'],
                'package_id'     => (int) Package::findBySlug('package-a')['id'],
                'amount'         => 4498.00,
                'payment_method' => 'gcash',
                'status'         => 'pending_review',
                'reference_no'   => 'HIST-' . $i,
            ]);
        }

        assert_same(3, Activation::countForUser((int) $historyMember['id']));

        $firstPage = Activation::pageForUser((int) $historyMember['id'], new Paginator(3, 2, 1));
        $lastPage  = Activation::pageForUser((int) $historyMember['id'], new Paginator(3, 2, 2));

        assert_count(2, $firstPage);
        assert_count(1, $lastPage);
        assert_same('HIST-3', (string) $firstPage[0]['reference_no'], 'newest first');
        assert_same('HIST-1', (string) $lastPage[0]['reference_no']);

        /* Direct recruits share the same pager contract. */
        $sponsors = new SponsorService();
        $total    = $sponsors->countDirectRecruits((int) $juan['id']);
        $all      = $sponsors->directRecruits((int) $juan['id']);

        assert_same(count($all), $total);
        assert_true($total >= 1);

        $one = $sponsors->directRecruits((int) $juan['id'], null, new Paginator($total, 1, 1));
        assert_count(1, $one);
        assert_same((string) $all[0]['username'], (string) $one[0]['username']);
    },
];
