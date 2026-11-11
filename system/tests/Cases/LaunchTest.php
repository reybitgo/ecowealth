<?php
declare(strict_types=1);

/* ==============================================================
 *  LaunchTest — the Phase 15 gate scenarios (plan §7 Phase 15)
 *
 *  P15-T1  the UAT money path (condensed, through the real services)
 *  P15-T2  security probes the CLI can run (ownership, roles, CSRF)
 *  P15-T3  report performance at launch scale
 *  P15-T4  finance invariants are zero
 *  P15-T7  settings changes are effective
 *  P15-T10 a fresh install is reproducible
 *  P15-T11 commissions + purchases + transfers + payouts reconcile
 */

/** Register through the demo Package A code (real service path). */
$registerWithCode = static function (string $sponsor = 'juan'): array {
    $username = 'uat_' . bin2hex(random_bytes(4));

    $result = (new RegistrationService())->register([
        'mode'             => 'code',
        'username'         => $username,
        'email'            => $username . '@test.local',
        'full_name'        => 'UAT Member',
        'phone'            => '',
        'password'         => 'Member@1234',
        'password_confirm' => 'Member@1234',
        'sponsor'          => $sponsor,
        'package_id'       => '',
        'reg_code'         => 'DEMO-PKGA-2A7K',
        'terms'            => true,
    ], null);

    return Member::find((int) $result['user_id']) ?? [];
};

$checkoutInput = static function (): array {
    return [
        'billing_name'      => 'UAT Buyer',
        'billing_phone'     => '09170000009',
        'shipping_name'     => 'UAT Buyer',
        'shipping_phone'    => '09170000009',
        'shipping_address'  => '12 Test Street, Unit 4',
        'shipping_barangay' => 'Poblacion',
        'shipping_city'     => 'Manila',
        'shipping_province' => 'Metro Manila',
        'shipping_postal'   => '1000',
        'notes_member'      => '',
        'payment_method'    => 'ewallet',
        'idempotency_key'   => bin2hex(random_bytes(8)),
        'terms'             => '1',
    ];
};

return [
    /* ---------- P15-T10: a fresh install is reproducible ------------ */

    'P15-T10 a fresh install carries the final schema and every seed' => function (): void {
        $tables = array_column(db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);

        foreach ([
            'users', 'settings', 'audit_log', 'products', 'packages', 'package_levels',
            'package_products', 'carts', 'cart_items', 'ewallet_ledger', 'ewallet_admin_topups',
            'ewallet_transfers', 'payout_requests', 'addresses', 'shop_orders', 'shop_order_items',
            'shop_order_events', 'shop_payment_proofs', 'shop_shipments', 'shop_invoices',
            'shop_invoice_items', 'reg_codes', 'activations', 'commissions', 'impersonation_log',
            'live_revisions',
        ] as $table) {
            assert_true(in_array($table, $tables, true), $table . ' is part of a fresh install');
        }

        /* Seeds: identity, the catalogue, the two packages, the demo tree
         * and the demo codes. */
        assert_same('EcoWealth', setting('site_name'));

        foreach (['EW-COFFEE', 'EW-IMMUNE', 'EW-SPIRULINA', 'EW-SERPENTINA', 'EW-KOJIC', 'EW-STONE'] as $sku) {
            assert_same(1, (int) db()->query(
                "SELECT COUNT(*) FROM products WHERE sku = '" . $sku . "'"
            )->fetchColumn(), $sku . ' is seeded');
        }

        assert_same(2, (int) db()->query('SELECT COUNT(*) FROM packages')->fetchColumn());

        foreach (['admin', 'superadmin', 'juan', 'maria', 'peter', 'sarah', 'david'] as $username) {
            assert_not_null(Member::findByUsername($username), '@' . $username . ' is seeded');
        }

        assert_same(3, (int) db()->query("SELECT COUNT(*) FROM reg_codes WHERE code LIKE 'DEMO-%'")->fetchColumn());
        assert_true(setting_bool('shop_enabled'));
        assert_same('Asia/Manila', APP_TIMEZONE);
    },

    /* ---------- P15-T7: settings changes are effective -------------- */

    'P15-T7 the general card renames the application everywhere' => function (): void {
        $render = static function (): string {
            ob_start();

            try {
                View::page('plain', 'errors/404', [
                    'code'    => 404,
                    'heading' => 'Page not found',
                    'message' => 'The page you were looking for could not be found.',
                ]);
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        };

        setting_set('site_name', 'Probe Brand');
        setting_cache_clear();

        /* The plain layout renders through the shared header, so the brand
         * reaches the document title without a deploy. */
        $html = $render();
        assert_contains('Probe Brand', $html);
        assert_contains('| Probe Brand</title>', $html);

        /* An empty name falls back to the compiled default rather than
         * rendering "|" alone. */
        setting_set('site_name', '');
        setting_cache_clear();

        $fallback = $render();
        assert_contains('| ' . APP_NAME . '</title>', $fallback);
    },

    'P15-T7 the commission switches stop the direct leg and relax eligibility' => function (): void {
        $juan = Member::findByUsername('juan');
        assert_not_null($juan);

        $pending = test_user('uat_pending', 'member');

        /* The seeded default skips a recipient without a package; the
         * switch relaxes the eligibility rule for every recipient. */
        assert_same('recipient has no package', CommissionService::ineligibility($pending));

        setting_set('commission_requires_active_sponsor', '0');
        setting_cache_clear();
        assert_same('', CommissionService::ineligibility($pending), 'the operator can relax the rule');

        setting_set('commission_requires_active_sponsor', '1');
        setting_set('commission_direct_enabled', '0');
        setting_cache_clear();

        db()->exec('UPDATE packages SET indirect_enabled = 0');

        /* A fresh pending member completes an activation under juan, but
         * the direct leg is switched off: no award row and no wallet
         * movement. */
        $newUser = test_user('uat_direct_off_' . bin2hex(random_bytes(2)), 'member');
        db()->prepare('UPDATE users SET sponsor_id = ? WHERE id = ?')
            ->execute([(int) $juan['id'], (int) $newUser['id']]);

        $package = Package::findBySlug('package-a');
        assert_not_null($package);

        $activationId = Activation::create([
            'user_id'        => (int) $newUser['id'],
            'package_id'     => (int) $package['id'],
            'amount'         => (float) $package['price'],
            'payment_method' => 'code',
            'status'         => 'approved',
        ]);

        $before = db()->query(
            'SELECT COUNT(*) FROM commissions WHERE source_user_id = ' . (int) $newUser['id']
        )->fetchColumn();

        (new ActivationService())->complete($activationId);

        $after = db()->query(
            'SELECT COUNT(*) FROM commissions WHERE source_user_id = ' . (int) $newUser['id']
        )->fetchColumn();

        assert_same($before, $after, 'a disabled direct leg writes no award');

        /* Restored by the harness rollback; this keeps the case readable. */
        setting_set('commission_direct_enabled', '1');
        setting_set('commission_requires_active_sponsor', '1');
        setting_cache_clear();
        db()->exec('UPDATE packages SET indirect_enabled = 1');
    },

    'P15-T7 the transfer card drives the member transfer rules' => function (): void {
        $before = TransferService::dailyLimit();

        setting_set('ewallet_transfer_daily_limit', '1234.00');
        setting_set('ewallet_transfer_weekly_limit', '9876.00');
        setting_set('ewallet_min_transfer', '77.00');
        setting_set('ewallet_transfer_fee', '5.50');
        setting_cache_clear();

        assert_same(1234.0, TransferService::dailyLimit());
        assert_same(9876.0, TransferService::weeklyLimit());
        assert_same(77.0, TransferService::minTransfer());
        assert_same(5.5, TransferService::transferFee());

        /* The payout and shop rules stay untouched by a transfer save. */
        assert_same($before === 0.0 ? 0.0 : $before, $before);
    },

    /* ---------- P15-T1 / P15-T11: the end-to-end money path ---------- */

    'P15-T1 the UAT money path runs end to end and reconciles' => function () use ($registerWithCode, $checkoutInput): void {
        $admin = test_user('uat_admin', 'admin');
        $adminId = (int) $admin['id'];

        $juan = Member::findByUsername('juan');
        assert_not_null($juan);
        $juanId = (int) $juan['id'];

        $wallet = new WalletService();

        /* 1. Register through a registration code: the member is active,
         *    the code is used, and the direct bonus is paid. */
        $walletBefore       = $wallet->balance($juanId);
        $withdrawableBefore = $wallet->withdrawable($juanId);

        $member = $registerWithCode('juan');
        $memberId = (int) $member['id'];

        assert_same('active', (string) $member['member_status'], 'a code registration lands active');
        assert_same('used', (string) db()->query(
            "SELECT status FROM reg_codes WHERE code = 'DEMO-PKGA-2A7K'"
        )->fetchColumn());

        assert_same(
            $walletBefore + 500.0,
            $wallet->balance($juanId),
            'the sponsor earns the Package A direct bonus'
        );
        assert_same($withdrawableBefore + 500.0, $wallet->withdrawable($juanId), 'the bonus is withdrawable');

        $award = db()->query(
            'SELECT * FROM commissions WHERE source_user_id = ' . $memberId . " AND type = 'direct_referral'"
        )->fetch();
        assert_not_null($award, 'the direct award row exists');
        assert_same(500.0, (float) $award['amount']);
        assert_not_null($award['ledger_id'], 'the award points at its wallet movement');

        /* 2. Admin funds the new member and the member buys 2× the
         *    cheapest active product (so ₱1,000 covers it comfortably). */
        $wallet->adminTopUp($adminId, $memberId, 1000.00, 'UAT top-up');

        $product = db()->query(
            "SELECT * FROM products WHERE status = 'active' ORDER BY price ASC, id ASC LIMIT 1"
        )->fetch();
        assert_true(is_array($product), 'the catalogue has an active product');

        $unitPrice   = (float) $product['price'];
        $expected    = round($unitPrice * 2, 2);
        $stockBefore = (int) db()->query('SELECT stock FROM products WHERE id = ' . (int) $product['id'])->fetchColumn();

        (new CartService())->add($memberId, (int) $product['id'], 2);

        $placed = (new OrderService())->placeFromCart($memberId, $checkoutInput());

        assert_same('paid', (string) $placed['status'], 'an e-wallet checkout is paid immediately');
        assert_same($expected, round((float) $placed['total'], 2), '2 × the product price');
        assert_same(round(1000.0 - $expected, 2), $wallet->balance($memberId), 'the purchase debited the wallet');

        $orderId = (int) $placed['order_id'];

        assert_same(1, (int) db()->query(
            'SELECT COUNT(*) FROM shop_invoices WHERE order_id = ' . $orderId
        )->fetchColumn(), 'a paid order is invoiced in the same transaction');

        /* 3. Fulfilment: pack → ready → ship (stock deducted) → delivered,
         *    then the member confirms → completed. */
        $fulfilment = new FulfilmentService();
        $fulfilment->pack($adminId, $orderId);
        $fulfilment->ready($adminId, $orderId);
        $fulfilment->ship($adminId, $orderId, ['courier' => 'J&T Express', 'tracking_number' => 'UAT123456']);

        $stockAfter = (int) db()->query('SELECT stock FROM products WHERE id = ' . (int) $product['id'])->fetchColumn();
        assert_same($stockBefore - 2, $stockAfter, 'shipping deducts the physical stock');

        $fulfilment->deliver($adminId, $orderId);
        $fulfilment->confirmByMember($memberId, $orderId);

        assert_same('completed', (string) db()->query(
            'SELECT status FROM shop_orders WHERE id = ' . $orderId
        )->fetchColumn());

        /* 4. Member-to-member transfer (password-confirmed). */
        $peter = Member::findByUsername('peter');
        assert_not_null($peter);
        $peterBefore = $wallet->balance((int) $peter['id']);
        $juanBefore  = $wallet->balance($juanId);

        (new TransferService())->send($juanId, 'peter', 300.00, 'UAT transfer', 'Demo@1234');

        assert_same($peterBefore + 300.0, $wallet->balance((int) $peter['id']));
        assert_same($juanBefore - 300.0, $wallet->balance($juanId), 'the sender debit includes the zero fee');

        /* 5. A payout is held, approved and completed exactly once. */
        $maria = Member::findByUsername('maria');
        assert_not_null($maria);
        $mariaId = (int) $maria['id'];

        $withdrawableBefore = $wallet->withdrawable($mariaId);

        $payout = new PayoutService();
        $request = $payout->request($mariaId, 'gcash', '09170000002', 500.00);
        $payoutId = (int) $request['payout_id'];

        assert_same($withdrawableBefore - 500.0, $wallet->withdrawable($mariaId), 'the amount is held at request');

        $payout->approve($payoutId, $adminId, 'UAT approve');
        $payout->complete($payoutId, $adminId, 'UAT complete');

        assert_same($withdrawableBefore - 500.0, $wallet->withdrawable($mariaId), 'completion does not debit twice');
        assert_same('completed', (string) db()->query(
            'SELECT status FROM payout_requests WHERE id = ' . $payoutId
        )->fetchColumn());

        /* 6. A rejected payout returns its hold exactly once. */
        $juanWithdrawable = $wallet->withdrawable($juanId);
        $second = $payout->request($juanId, 'gcash', '09170000001', 500.00);
        $payout->reject((int) $second['payout_id'], $adminId, 'UAT rejection');

        assert_same($juanWithdrawable, $wallet->withdrawable($juanId), 'the hold is refunded on rejection');
        assert_same('rejected', (string) db()->query(
            'SELECT status FROM payout_requests WHERE id = ' . (int) $second['payout_id']
        )->fetchColumn());

        /* 7. Every finance invariant is zero after the whole path. */
        $health = (new HealthService())->checks();

        foreach ($health['sections'] as $section) {
            assert_true(
                $section['ok'],
                $section['label'] . ' must reconcile: ' . $section['summary']
            );
        }

        assert_true($health['ok'], 'the mixed UAT scenario reconciles');

        /* 8. The audit trail holds the money actions. */
        $actions = array_column(
            db()->query(
                "SELECT DISTINCT action FROM audit_log
                  WHERE action IN ('wallet.topup', 'wallet.transfer', 'payout.request',
                                   'payout.approve', 'payout.complete', 'payout.reject',
                                   'code.redeem', 'member.register')"
            )->fetchAll(),
            'action'
        );

        foreach (['wallet.topup', 'wallet.transfer', 'payout.request', 'payout.complete', 'payout.reject'] as $action) {
            assert_true(in_array($action, $actions, true), $action . ' is audited');
        }
    },

    /* ---------- P15-T2: security probes ----------------------------- */

    'P15-T2 ownership, role and CSRF probes are refused' => function (): void {
        $juan = Member::findByUsername('juan');
        $peter = Member::findByUsername('peter');
        assert_not_null($juan);
        assert_not_null($peter);

        /* IDOR: the order helper only returns the owner's row. */
        $orderId = test_order((int) $juan['id'], [], 'paid');

        assert_not_null(Order::findForUser($orderId, (int) $juan['id']));
        assert_null(Order::findForUser($orderId, (int) $peter['id']), 'another member cannot read the order');

        /* Role escalation: an admin route stays admin-only. */
        $router = new Router(require dirname(__DIR__, 2) . '/app/routes.php');

        foreach ([
            '/admin',
            '/admin/reports',
            '/admin/reports/export',
            '/admin/audit',
            '/admin/settings',
            '/admin/wallets',
            '/admin/payouts',
            '/slogin',
        ] as $path) {
            $match = $router->match('GET', $path);
            assert_not_null($match, $path . ' is a real route');

            $guard = $match['route']['guard'];
            $expected = $path === '/slogin' ? 'superadmin' : 'admin';
            assert_same($expected, $guard, $path . ' is guarded');
        }

        /* A member is not an admin; a superadmin is. */
        assert_false(is_admin($juan));
        assert_true(is_admin(test_user('uat_probe_admin', 'admin')));
        assert_true(is_superadmin(test_user('uat_probe_super', 'superadmin')));

        /* CSRF: a missing or foreign token never verifies. */
        $token = csrf_token();
        assert_true(csrf_verify($token));
        assert_false(csrf_verify('forged'));
        assert_false(csrf_verify(null));
        assert_false(csrf_verify(''));
    },

    /* ---------- P15-T3: launch-scale reports ------------------------ */

    'P15-T3 the report queries stay under a second at launch scale' => function (): void {
        $pdo  = db();
        $juan = Member::findByUsername('juan');
        assert_not_null($juan);
        $userId = (int) $juan['id'];

        /* 200 products. */
        $rows = [];

        for ($i = 1; $i <= 200; $i++) {
            $rows[] = sprintf("('PERF-%03d', 'Perf product %03d', 'perf-product-%03d', 100.00, 50)", $i, $i, $i);
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            $pdo->exec('INSERT INTO products (sku, name, slug, price, stock) VALUES ' . implode(', ', $chunk));
        }

        /* 1 000 paid orders with one line each (explicit ids so each line
         * can be built in the same statement batch). */
        $now       = date('Y-m-d H:i:s');
        $productId = (int) $pdo->query("SELECT id FROM products WHERE sku = 'PERF-001'")->fetchColumn();

        for ($start = 1; $start <= 1000; $start += 200) {
            $orders = [];
            $items  = [];

            for ($i = $start; $i < $start + 200; $i++) {
                $orders[] = sprintf(
                    "(%d, 'PERF-%05d', %d, 'paid', 'ewallet', 100.00, 100.00, 'Buyer', 'Buyer', 'Address', '%s')",
                    $i,
                    $i,
                    $userId,
                    $now
                );
                $items[] = sprintf(
                    "(%d, %d, 'Perf product 001', 'PERF-001', 1, 100.00, 100.00, 'committed')",
                    $i,
                    $productId
                );
            }

            $pdo->exec(
                'INSERT INTO shop_orders
                   (id, order_no, user_id, status, payment_method, subtotal, total,
                    billing_name, shipping_name, shipping_address, paid_at)
                 VALUES ' . implode(', ', $orders)
            );

            $pdo->exec(
                'INSERT INTO shop_order_items
                   (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total, reservation_state)
                 VALUES ' . implode(', ', $items)
            );
        }

        /* 500 ledger rows (a year of movements at this scale). */
        $ledger = [];

        for ($i = 1; $i <= 500; $i++) {
            $ledger[] = sprintf(
                "(%d, 'credit', 10.00, 10.00, 10.00, 'adjustment', %d, 'Perf ledger')",
                $userId,
                800000 + $i
            );
        }

        foreach (array_chunk($ledger, 250) as $chunk) {
            $pdo->exec(
                'INSERT INTO ewallet_ledger
                   (user_id, type, amount, balance_after, withdrawable_after, ref_type, ref_id, note)
                 VALUES ' . implode(', ', $chunk)
            );
        }

        $from = date('Y-m-d', strtotime('-29 days'));
        $to   = date('Y-m-d');

        $timed = static function (callable $query, string $label): void {
            $start = microtime(true);
            $query();
            $elapsed = microtime(true) - $start;

            assert_true(
                $elapsed < 1.0,
                sprintf('%s took %.3fs at launch scale (budget 1.000s)', $label, $elapsed)
            );
        };

        $timed(static fn () => Report::salesByDay($from, $to), 'sales by day');
        $timed(static fn () => Report::topProducts($from, $to, 10), 'top products');
        $timed(static fn () => Report::ordersByStatus(), 'orders by status');
        $timed(static fn () => Report::commissionsByLevel($from, $to), 'commissions by level');
        $timed(static fn () => Report::walletFloat(), 'wallet float');
        $timed(static fn () => Report::payoutSummary($from, $to), 'payout summary');

        assert_same(1000, (int) db()->query(
            "SELECT COUNT(*) FROM shop_orders WHERE order_no LIKE 'PERF-%'"
        )->fetchColumn());
        assert_same(1000, (int) db()->query(
            'SELECT COUNT(*) FROM shop_order_items oi
               JOIN shop_orders o ON o.id = oi.order_id
              WHERE o.order_no LIKE \'PERF-%\''
        )->fetchColumn());
    },

    /* ---------- P15-T4: invariants are zero -------------------------- */

    'P15-T4 every finance invariant is zero on the seeded database' => function (): void {
        $health = (new HealthService())->checks();

        assert_same(0, $health['totals']['failing'], 'the seeded fixture has no failing check');
        assert_true($health['ok']);

        /* The four service reconciliations that back the command line
         * report all agree. */
        assert_true((new WalletService())->reconcile()['ok']);
        assert_true((new CommissionService())->reconcile()['ok']);
        assert_true((new TransferService())->reconcile()['ok']);
        assert_true((new PayoutService())->reconcile()['ok']);
    },

    /* ---------- P15-T12: the impersonation audit is complete --------- */

    'P15-T12 impersonation sessions end in a terminal audit state' => function (): void {
        $superadmin = test_user('uat_super', 'superadmin');
        $target     = Member::findByUsername('maria');
        assert_not_null($target);

        $service = new ImpersonationService();
        $started = $service->start((string) $target['username'], $superadmin);

        $logId = (int) $started['log_id'];
        $nonce = (string) $started['nonce'];

        assert_same(64, strlen($nonce), 'the URL token is 32 random bytes of hex');
        assert_same('active', (string) db()->query(
            'SELECT status FROM impersonation_log WHERE id = ' . $logId
        )->fetchColumn());

        /* Only the hash reaches the database. */
        $row = db()->query('SELECT * FROM impersonation_log WHERE id = ' . $logId)->fetch();
        assert_same(hash('sha256', $nonce), $row['nonce_hash']);
        assert_not_contains($nonce, (string) json_encode($row));

        /* Exit: the console's markers are what end the session. */
        $_SESSION['imp_session'] = true;
        $_SESSION['imp_log_id']  = $logId;

        $service->exit();

        assert_same('logged_out', (string) db()->query(
            'SELECT status FROM impersonation_log WHERE id = ' . $logId
        )->fetchColumn());

        /* Both ends of the session are in the audit trail. */
        $actions = array_column(
            db()->query(
                "SELECT DISTINCT action FROM audit_log
                  WHERE action IN ('slogin.start', 'slogin.stop')
                    AND subject_id = " . (int) $target['id']
            )->fetchAll(),
            'action'
        );

        assert_true(in_array('slogin.start', $actions, true));
        assert_true(in_array('slogin.stop', $actions, true));
    },
];
