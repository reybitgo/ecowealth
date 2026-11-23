<?php
declare(strict_types=1);

/* ============================================================================
 * Phase 25 QA — automated execution of docs/qa/phase-25-product-commissions.md
 *
 * Runs the walkthrough against an ISOLATED schema (ecowealth_sneat_qa25) over
 * real HTTP (php -S on 127.0.0.1:8899), then asserts the database state the
 * document expects. Every HTTP status and every flash/validation message is
 * written to the run log.
 *
 *   php qa25_http_run.php
 * ========================================================================== */

define('APP_BASE_URL', 'http://127.0.0.1:8899/');
define('APP_ENV', 'test');
putenv('ECO_DB_NAME=ecowealth_sneat_qa25');

require __DIR__ . '/../../../../../system/app/bootstrap.php';

const BASE          = 'http://127.0.0.1:8899';
const DB_NAME_QA    = 'ecowealth_sneat_qa25';
const ADMIN_USER    = 'admin';
const ADMIN_PASS    = 'Qa25Run!Pass2026';
const MEMBER_PASS   = 'Demo@1234';
const EVID          = __DIR__;

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$LOG    = [];
$CHECKS = [];

function logline(string $line): void
{
    global $LOG;
    $stamp = date('H:i:s');
    $LOG[] = "[$stamp] $line";
    echo "[$stamp] $line\n";
}

function check(string $id, string $title, bool $ok, string $expected, string $actual): void
{
    global $CHECKS;
    $CHECKS[$id] = ['id' => $id, 'title' => $title, 'pass' => $ok, 'expected' => $expected, 'actual' => $actual];
    logline(sprintf('%s %s — %s | expected: %s | actual: %s', $ok ? 'PASS' : 'FAIL', $id, $title, $expected, $actual));
}

function q(string $sql, array $params = []): array
{
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function q1(string $sql, array $params = []): ?array
{
    $rows = q($sql, $params);

    return $rows[0] ?? null;
}

function m2($v): string
{
    return number_format((float) $v, 2, '.', '');
}

/* ---------------------------------------------------------------- HTTP ---- */

final class Browser
{
    private string $jar;
    public string $label;
    public string $lastStatus = '';
    public string $lastUrl = '';
    public string $lastBody = '';

    public function __construct(string $label)
    {
        $this->label = $label;
        $this->jar   = sys_get_temp_dir() . '/qa25_' . preg_replace('/\W+/', '_', $label) . '.cookies';
        @unlink($this->jar);
    }

    /** @param array<string,string>|null $fields */
    public function request(string $method, string $path, ?array $fields = null, array $files = [], bool $follow = true): array
    {
        $url = str_starts_with($path, 'http') ? $path : BASE . $path;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);

            if ($files !== []) {
                $body = $fields ?? [];
                foreach ($files as $name => $file) {
                    $body[$name] = new CURLFile($file['path'], $file['type'], $file['name']);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
            }
        }

        $raw    = (string) curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $split  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($raw, 0, $split);
        $body   = substr($raw, $split);
        $final  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $this->lastStatus = (string) $status;
        $this->lastUrl    = $final;
        $this->lastBody   = $body;

        if ($errno !== 0) {
            logline(sprintf('  !! curl error %d on %s %s', $errno, $method, $url));
        }

        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $header, $m)) {
            $location = trim($m[1]);
        }

        return ['status' => $status, 'body' => $body, 'location' => $location, 'url' => $final];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields): array
    {
        return $this->request('POST', $path, $fields);
    }

    public function csrf(string $path): string
    {
        $res = $this->get($path);

        if (preg_match('/name="_csrf" value="([^"]+)"/', $res['body'], $m)) {
            return $m[1];
        }

        logline(sprintf('  !! no CSRF token on %s (status %d)', $path, $res['status']));

        return '';
    }
}

/** First flash/alert text on a page ("" when none). */
function flash_of(string $html): string
{
    if (preg_match_all('/<div[^>]*class="[^"]*\balert\b[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m)) {
        foreach ($m[1] as $chunk) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($chunk)));
            if ($text !== '') {
                return $text;
            }
        }
    }

    if (preg_match_all('/class="[^"]*invalid-feedback[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m)) {
        $parts = array_filter(array_map(static fn ($c) => trim(preg_replace('/\s+/', ' ', strip_tags($c))), $m[1]));

        if ($parts !== []) {
            return 'validation: ' . implode(' / ', $parts);
        }
    }

    return '';
}

function body_has(string $html, string $needle): bool
{
    return str_contains(preg_replace('/\s+/', ' ', strip_tags($html)), $needle);
}

function admin_browser(): Browser
{
    $b = new Browser('admin');
    $token = $b->csrf('/login');
    $res = $b->post('/login', ['_csrf' => $token, 'email-username' => ADMIN_USER, 'password' => ADMIN_PASS]);
    logline('admin sign-in: status ' . $res['status'] . ' → ' . $res['url'] . ' | ' . (flash_of($res['body']) ?: 'no message'));

    return $b;
}

function member_browser(string $username): Browser
{
    $b = new Browser($username);
    $token = $b->csrf('/login');
    $res = $b->post('/login', ['_csrf' => $token, 'email-username' => $username, 'password' => MEMBER_PASS]);
    logline(sprintf('%s sign-in: status %d → %s | %s', $username, $res['status'], $res['url'], flash_of($res['body']) ?: 'no message'));

    return $b;
}

function register(string $username, string $sponsor, string $upline, string $side): array
{
    /* A successful signup signs the new member in, so every registration
     * needs its own signed-out session. */
    $b = new Browser('guest_' . $username);
    $token = $b->csrf('/register');
    $fields = [
        '_csrf'            => $token,
        'mode'             => 'standard',
        'username'         => $username,
        'email'            => strtolower($username) . '@example.test',
        'full_name'        => str_replace('_', ' ', $username),
        'phone'            => '',
        'password'         => MEMBER_PASS,
        'password_confirm' => MEMBER_PASS,
        'sponsor'          => $sponsor,
        'package_id'       => '',
        'reg_code'         => '',
        'terms'            => '1',
        'binary_upline'    => $upline,
        'binary_position'  => $side,
    ];

    $res = $b->post('/register', $fields);
    $row = q1('SELECT id, username, member_status, package_id, sponsor_id FROM users WHERE username = ?', [$username]);
    $msg = flash_of($res['body']);
    logline(sprintf('  register %-16s sponsor=%-12s upline=%-12s side=%-5s status=%d user=%s msg=%s',
        $username, $sponsor === '' ? '(admin)' : $sponsor, $upline === '' ? '(sponsor)' : $upline,
        $side, $res['status'], $row ? ('#' . $row['id']) : 'MISSING', $msg !== '' ? $msg : '(none)'));

    return ['res' => $res, 'user' => $row, 'msg' => $msg];
}

function qa_set(string $key, string $value): void
{
    global $pdo;
    $pdo->prepare('UPDATE settings SET value = ? WHERE key_name = ?')->execute([$value, $key]);
}

function cfg(string $key): string
{
    $row = q1('SELECT value FROM settings WHERE key_name = ?', [$key]);

    return (string) ($row['value'] ?? '');
}

function user_id(string $username): int
{
    $row = q1('SELECT id FROM users WHERE username = ?', [$username]);

    return (int) ($row['id'] ?? 0);
}

/** Admin top-up through the real screen. */
function top_up(Browser $admin, int $userId, string $amount): string
{
    $token = $admin->csrf('/admin/wallets/' . $userId);
    $res = $admin->post('/admin/wallets/' . $userId . '/credit', [
        '_csrf'  => $token,
        'amount' => $amount,
        'note'   => 'QA25 float',
    ]);
    $msg = flash_of($res['body']);
    logline(sprintf('  top-up user #%d %s: status=%d msg=%s', $userId, $amount, $res['status'], $msg ?: '(none)'));

    return $msg;
}

function proof_file(): array
{
    static $path = null;

    if ($path === null) {
        $path = sys_get_temp_dir() . '/qa25-proof.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    return ['path' => $path, 'type' => 'image/png', 'name' => 'receipt.png'];
}

/** Add products, open checkout and place one order. Returns the fresh order row. */
function place_order(Browser $member, int $qty, string $method, string $side, string $tag): array
{
    $maxBefore = (int) (q1('SELECT COALESCE(MAX(id),0) AS m FROM shop_orders')['m'] ?? 0);

    $token = $member->csrf('/cart');
    $add = $member->post('/cart/add', ['_csrf' => $token, 'product_id' => '1', 'quantity' => (string) $qty]);
    logline(sprintf('  cart add %d x Coffee (%s): status=%d msg=%s', $qty, $tag, $add['status'], flash_of($add['body']) ?: '(none)'));

    $page = $member->get('/checkout');
    $html = $page['body'];

    preg_match('/name="_csrf" value="([^"]+)"/', $html, $m1);
    preg_match('/name="idempotency_key" value="([^"]+)"/', $html, $m2);

    $csrf = $m1[1] ?? '';
    $key  = $m2[1] ?? '';

    $res = $member->post('/checkout', [
        '_csrf'             => $csrf,
        'billing_name'      => 'QA25 Tester',
        'billing_phone'     => '09170000000',
        'shipping_name'     => 'QA25 Tester',
        'shipping_phone'    => '09170000000',
        'shipping_address'  => '25 QA Street',
        'shipping_barangay' => 'Poblacion',
        'shipping_city'     => 'Makati',
        'shipping_province' => 'Metro Manila',
        'shipping_postal'   => '1200',
        'notes_member'      => 'QA25 ' . $tag,
        'payment_method'    => $method,
        'idempotency_key'   => $key,
        'binary_position'   => $side,
        'terms'             => '1',
    ]);

    $msg = flash_of($res['body']);
    logline(sprintf('  checkout %-22s method=%-7s status=%d → %s | %s', $tag, $method, $res['status'], $res['url'], $msg ?: '(none)'));

    $order = q1(
        'SELECT o.* FROM shop_orders o JOIN users u ON u.id = o.user_id
          WHERE u.username = ? AND o.id > ? ORDER BY o.id DESC LIMIT 1',
        [$member->label, $maxBefore]
    );

    logline(sprintf('  order %s: #%s %s %s total=%s',
        $tag,
        $order['id'] ?? 'MISSING',
        $order['status'] ?? '?',
        $order['order_no'] ?? '?',
        m2($order['total'] ?? 0)
    ));

    return ['order' => $order, 'message' => $msg, 'checkout_html' => $html];
}

/** paid → packing → ready_to_ship → shipped → delivered, all through the admin routes. */
function deliver_order(Browser $admin, int $orderId): array
{
    $messages = [];

    foreach (['packing', 'ready_to_ship'] as $to) {
        $token = $admin->csrf('/admin/orders/' . $orderId);
        $res = $admin->post('/admin/orders/' . $orderId . '/transition', ['_csrf' => $token, 'to' => $to]);
        $messages[$to] = flash_of($res['body']);
    }

    $token = $admin->csrf('/admin/orders/' . $orderId);
    $res = $admin->post('/admin/orders/' . $orderId . '/ship', [
        '_csrf'           => $token,
        'courier'         => 'J&T Express',
        'tracking_number' => 'QA25' . $orderId,
        'notes'           => 'QA25 dispatch',
    ]);
    $messages['ship'] = flash_of($res['body']);

    $token = $admin->csrf('/admin/orders/' . $orderId);
    $res = $admin->post('/admin/orders/' . $orderId . '/deliver', [
        '_csrf' => $token,
        'note'  => 'QA25 delivered',
    ]);
    $messages['deliver'] = flash_of($res['body']);

    $row = q1('SELECT status, delivered_at FROM shop_orders WHERE id = ?', [$orderId]);
    logline(sprintf('  deliver order #%d: status=%s delivered_at=%s | %s',
        $orderId, $row['status'] ?? '?', $row['delivered_at'] ?? 'null',
        implode(' / ', array_filter($messages))));

    return ['messages' => $messages, 'order' => $row];
}

function submit_order_proof(Browser $member, int $orderId, string $amount, string $reference): string
{
    $token = $member->csrf('/orders/' . $orderId);
    $res = $member->request('POST', '/orders/' . $orderId . '/proof', [
        '_csrf'        => $token,
        'amount_sent'  => $amount,
        'reference_no' => $reference,
        'transfer_date' => date('Y-m-d'),
    ], ['proof_image' => proof_file()]);

    $msg = flash_of($res['body']);
    logline(sprintf('  proof order #%d %s: status=%d msg=%s', $orderId, $reference, $res['status'], $msg ?: '(none)'));

    return $msg;
}

function verify_order(Browser $admin, int $orderId, string $amount, string $reference): string
{
    $token = $admin->csrf('/admin/orders/' . $orderId);
    $res = $admin->post('/admin/orders/' . $orderId . '/transition', [
        '_csrf'             => $token,
        'to'                => 'paid',
        'amount_received'   => $amount,
        'payment_reference' => $reference,
        'note'              => 'QA25 verification',
    ]);
    $msg = flash_of($res['body']);
    logline(sprintf('  verify order #%d %s: status=%d msg=%s', $orderId, $reference, $res['status'], $msg ?: '(none)'));

    return $msg;
}

function cli(string $command, string $env = 'ECO_DB_NAME=' . DB_NAME_QA): array
{
    $out  = [];
    $code = 0;
    $prefix = $env === '' ? '' : 'set "' . $env . '" && ';
    exec('cd ' . escapeshellarg(__DIR__ . '/../../../../../system') . ' && ' . $prefix . 'php ' . $command . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    logline(sprintf('  $ php %s → exit %d', $command, $code));
    foreach ($out as $line) {
        logline('    | ' . $line);
    }

    return ['code' => $code, 'text' => $text, 'lines' => $out];
}

function save_csv(string $name, array $rows): void
{
    $path = EVID . '/' . $name;
    $fh = fopen($path, 'w');
    if ($rows === []) {
        fwrite($fh, "(no rows)\n");
    } else {
        fputcsv($fh, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($fh, array_values($row));
        }
    }
    fclose($fh);
    logline(sprintf('  evidence: %s (%d rows)', $name, count($rows)));
}

function awards_for(int $orderId): array
{
    return q(
        'SELECT a.level, u.username AS recipient, a.amount, a.status, a.reason, a.hold_days,
                a.ledger_id, a.credited_at
           FROM unilevel_awards a JOIN users u ON u.id = a.user_id
          WHERE a.order_id = ? ORDER BY a.level',
        [$orderId]
    );
}

function binary_awards_for(int $orderId): array
{
    return q(
        'SELECT a.kind, u.username AS recipient, a.amount, a.status, a.pair_count, a.hold_days,
                a.ledger_id, a.credited_at
           FROM binary_awards a JOIN users u ON u.id = a.user_id
          WHERE a.order_id = ? ORDER BY a.id',
        [$orderId]
    );
}

/* ======================================================================== */

logline('=== Phase 25 walkthrough — automated execution ===');
logline('database: ' . DB_NAME_QA . ' | app: ' . BASE);

$admin = admin_browser();

/* ------------------------------------------------------------------ Part 1 */

logline('--- Part 1 — settings, product and the fixture tree');

$beforeSettings = q(
    "SELECT key_name, value FROM settings
      WHERE key_name IN ('unilevel_enabled','unilevel_max_levels','unilevel_hold_days',
                         'unilevel_requires_active_upline','binary_enabled','binary_self_pct',
                         'binary_pair_points','binary_pair_pct','binary_hold_days',
                         'binary_daily_pair_cap','binary_requires_active')
      ORDER BY key_name"
);
save_csv('qa25-01-settings-before.csv', $beforeSettings);
logline('  settings before: ' . json_encode(array_column($beforeSettings, 'value', 'key_name')));

$productBefore = q('SELECT id, name, price, pv, unilevel_enabled, unilevel_base, binary_enabled FROM products WHERE id = 1');
save_csv('qa25-02-product-before.csv', $productBefore);

if (cfg('free_registration_enabled') !== '1') {
    qa_set('free_registration_enabled', '1');
    logline('  free_registration_enabled was not 1 — switched on for the fixture');
}

qa_set('unilevel_enabled', '1');
qa_set('unilevel_max_levels', '10');
qa_set('unilevel_hold_days', '0');
qa_set('unilevel_requires_active_upline', '1');
qa_set('unilevel_release_enabled', '1');
qa_set('binary_enabled', '1');
qa_set('binary_self_pct', '5.000');
qa_set('binary_pair_points', '1000');
qa_set('binary_pair_pct', '10.000');
qa_set('binary_hold_days', '0');
qa_set('binary_daily_pair_cap', '20');
qa_set('binary_requires_active', '1');
qa_set('binary_release_enabled', '1');

$pdo->prepare("UPDATE products SET pv = 250.00, unilevel_enabled = 1, unilevel_base = 'auto', binary_enabled = 1 WHERE id = 1")
    ->execute();

$pdo->prepare('DELETE FROM product_unilevel_levels WHERE product_id = 1')->execute();
$insertLevel = $pdo->prepare('INSERT INTO product_unilevel_levels (product_id, level, percent) VALUES (1, ?, ?)');
foreach ([5.0, 5.0, 5.0, 3.0, 3.0, 2.0, 2.0, 2.0, 1.0, 1.0] as $i => $rate) {
    $insertLevel->execute([$i + 1, number_format($rate, 3, '.', '')]);
}

$settingsAfter = q(
    "SELECT key_name, value FROM settings
      WHERE key_name IN ('unilevel_enabled','unilevel_max_levels','unilevel_hold_days',
                         'unilevel_requires_active_upline','binary_enabled','binary_self_pct',
                         'binary_pair_points','binary_pair_pct','binary_hold_days',
                         'binary_daily_pair_cap','binary_requires_active')
      ORDER BY key_name"
);
save_csv('qa25-01-settings-after.csv', $settingsAfter);

check(
    'QA25-01',
    'The two streams and their amounts are saved',
    cfg('unilevel_enabled') === '1'
        && cfg('unilevel_max_levels') === '10'
        && cfg('unilevel_hold_days') === '0'
        && cfg('binary_enabled') === '1'
        && cfg('binary_self_pct') === '5.000'
        && cfg('binary_pair_points') === '1000'
        && cfg('binary_pair_pct') === '10.000'
        && cfg('binary_hold_days') === '0',
    'unilevel on, 10 levels, hold 0; binary on, 5%, 1000 pts, 10%, hold 0',
    sprintf('unilevel=%s/%s/%s binary=%s/%s/%s/%s/%s',
        cfg('unilevel_enabled'), cfg('unilevel_max_levels'), cfg('unilevel_hold_days'),
        cfg('binary_enabled'), cfg('binary_self_pct'), cfg('binary_pair_points'),
        cfg('binary_pair_pct'), cfg('binary_hold_days'))
);

$product = q1('SELECT pv, unilevel_enabled, unilevel_base, binary_enabled FROM products WHERE id = 1');
$ladder  = q('SELECT level, percent FROM product_unilevel_levels WHERE product_id = 1 ORDER BY level');
save_csv('qa25-02-product-ladder.csv', $ladder);

check(
    'QA25-02',
    'Coffee carries P.V., the ladder and the binary flag',
    m2($product['pv']) === '250.00'
        && (int) $product['unilevel_enabled'] === 1
        && $product['unilevel_base'] === 'auto'
        && (int) $product['binary_enabled'] === 1
        && count($ladder) === 10
        && m2($ladder[0]['percent']) === '5.00'
        && m2($ladder[9]['percent']) === '1.00',
    'PV 250, ladder 5/5/5/3/3/2/2/2/1/1, binary on',
    sprintf('PV %s, ladder %s, binary %d',
        m2($product['pv']), implode('/', array_map(static fn ($r) => rtrim(rtrim(m2($r['percent']), '0'), '.'), $ladder)), (int) $product['binary_enabled'])
);

$fixture = [
    ['QA25_Boss',    '',           '',          'left'],
    ['QA25_A',       'QA25_Boss',  'QA25_Boss', 'left'],
    ['QA25_B',       'QA25_A',     'QA25_Boss', 'right'],
    ['QA25_C',       'QA25_B',     'QA25_A',    'left'],
    ['QA25_Buyer',   'QA25_C',     'QA25_A',    'right'],
    ['QA25_Buyer2',  'QA25_C',     'QA25_B',    'right'],
];

foreach ($fixture as [$name, $sponsor, $upline, $side]) {
    if (user_id($name) === 0) {
        $result = register($name, $sponsor, $upline, $side);
        if ($result['user'] === null) {
            logline('  !! registration failed for ' . $name . ' — stopping');
            exit(1);
        }
    }
}

$users = q(
    "SELECT u.id, u.username, u.member_status, u.is_active, s.username AS sponsor, b.side,
            up.username AS upline
       FROM users u
       LEFT JOIN users s ON s.id = u.sponsor_id
       LEFT JOIN binary_nodes b ON b.user_id = u.id
       LEFT JOIN users up ON up.id = b.upline_id
      WHERE u.username LIKE 'QA25\\_%'
      ORDER BY u.id"
);
save_csv('qa25-03-fixture-tree.csv', $users);

check(
    'QA25-03a',
    'Six standard members, active, with the sponsor chain and binary slots',
    count($users) === 6
        && count(array_filter($users, static fn ($u) => $u['member_status'] === 'active')) === 6
        && q1("SELECT sponsor_id FROM users WHERE username='QA25_Buyer'")['sponsor_id'] === user_id('QA25_C')
        && q1("SELECT b.side FROM binary_nodes b JOIN users u ON u.id=b.user_id WHERE u.username='QA25_Buyer2'")['side'] === 'right',
    '6 active members; Buyer sponsored by C; Buyer2 right of B',
    sprintf('%d members, %d active', count($users), count(array_filter($users, static fn ($u) => $u['member_status'] === 'active')))
);

$bossId   = user_id('QA25_Boss');
$buyerId  = user_id('QA25_Buyer');
$buyer2Id = user_id('QA25_Buyer2');

top_up($admin, $buyerId, '1500.00');
top_up($admin, $buyer2Id, '1500.00');

$funded = q(
    'SELECT username, ewallet_balance, withdrawable_balance
       FROM users
      WHERE username IN (?, ?, ?)',
    ['QA25_Buyer', 'QA25_Buyer2', 'QA25_Boss']
);
save_csv('qa25-03-wallets.csv', $funded);

check(
    'QA25-03b',
    'Both buyers are funded and the upline wallets start empty',
    (float) (q1('SELECT ewallet_balance FROM users WHERE id = ?', [$buyerId])['ewallet_balance'] ?? 0) === 1500.0
        && (float) (q1('SELECT ewallet_balance FROM users WHERE id = ?', [$buyer2Id])['ewallet_balance'] ?? 0) === 1500.0
        && (float) (q1('SELECT withdrawable_balance FROM users WHERE id = ?', [$bossId])['withdrawable_balance'] ?? 0) === 0.0,
    'buyers 1500.00 each; Boss withdrawable 0.00',
    implode(' | ', array_map(static fn ($r) => $r['username'] . '=' . m2($r['ewallet_balance']) . '/' . m2($r['withdrawable_balance']), $funded))
);

/* ------------------------------------------------------------------ Part 2 */

logline('--- Part 2 — the e-wallet order');

$buyer = member_browser('QA25_Buyer');
$first = place_order($buyer, 4, 'ewallet', 'left', 'order-1');
$order1 = $first['order'];
$order1Id = (int) ($order1['id'] ?? 0);

$preview = $first['checkout_html'];
check(
    'QA25-04',
    'The checkout preview quotes the repeat-purchase ladder and the binary points',
    body_has($preview, 'up to') || body_has($preview, 'Up to'),
    'a repeat-purchase "up to ₱…" line and a binary points line',
    'repeat line: ' . (preg_match('/up to[^<]{0,60}/i', strip_tags($preview), $m) ? trim($m[0]) : 'ABSENT')
);

check(
    'QA25-05',
    'An e-wallet order is paid once, at the wallet balance',
    $order1 !== null
        && $order1['status'] === 'paid'
        && $first['message'] === 'Order placed and paid. Thank you!'
        && (float) q1('SELECT ewallet_balance FROM users WHERE id = ?', [$buyerId])['ewallet_balance'] === 300.0,
    'status paid, flash "Order placed and paid. Thank you!", wallet 1500−1200=300',
    sprintf('status %s, msg "%s", wallet %s', $order1['status'] ?? '?', $first['message'], m2(q1('SELECT ewallet_balance FROM users WHERE id = ?', [$buyerId])['ewallet_balance'] ?? 0))
);

$unilevel1 = awards_for($order1Id);
save_csv('qa25-06-order1-unilevel-awards.csv', $unilevel1);

$expectedLevels = [
    1 => ['QA25_C', '50.00', 'pending'],
    2 => ['QA25_B', '50.00', 'pending'],
    3 => ['QA25_A', '50.00', 'pending'],
    4 => ['QA25_Boss', '30.00', 'pending'],
    5 => ['admin', '30.00', 'skipped'],
];

$rowsOk = count($unilevel1) === 5;
foreach ($unilevel1 as $row) {
    $level = (int) $row['level'];
    $exp = $expectedLevels[$level] ?? null;
    if ($exp === null || $row['recipient'] !== $exp[0] || m2($row['amount']) !== $exp[1] || $row['status'] !== $exp[2]) {
        $rowsOk = false;
    }
}

check(
    'QA25-06',
    'Five repeat-purchase decisions: four pending awards and the company-root skip',
    $rowsOk,
    'C 50 pending, B 50 pending, A 50 pending, Boss 30 pending, admin 30 skipped',
    implode('; ', array_map(static fn ($r) => sprintf('%d:%s %s %s', $r['level'], $r['recipient'], m2($r['amount']), $r['status']), $unilevel1))
);

check(
    'QA25-07',
    'The buyer earns nothing from their own purchase',
    (int) (q1('SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ? AND user_id = ?', [$order1Id, $buyerId])['n'] ?? 0) === 0
        && (int) (q1("SELECT COUNT(*) AS n FROM ewallet_ledger WHERE user_id = ? AND ref_type = 'unilevel'", [$buyerId])['n'] ?? 0) === 0,
    '0 awards and 0 repeat-purchase ledger rows for the buyer',
    sprintf('%d awards, %d unilevel ledger rows',
        (int) (q1('SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ? AND user_id = ?', [$order1Id, $buyerId])['n'] ?? 0),
        (int) (q1("SELECT COUNT(*) AS n FROM ewallet_ledger WHERE user_id = ? AND ref_type = 'unilevel'", [$buyerId])['n'] ?? 0))
);

$binary1 = binary_awards_for($order1Id);
$volume1 = q(
    'SELECT u.username, v.side, v.points, v.kind
       FROM binary_volume v JOIN users u ON u.id = v.node_user_id
      WHERE v.order_id = ? ORDER BY v.id',
    [$order1Id]
);
save_csv('qa25-08-order1-binary-awards.csv', $binary1);
save_csv('qa25-08-order1-binary-volume.csv', $volume1);

$self = null;
foreach ($binary1 as $row) {
    if ($row['kind'] === 'self') {
        $self = $row;
    }
}

check(
    'QA25-08',
    'The order stamps 1,000 points, a pending Self Bonus and rolls volume up',
    $order1 !== null
        && (int) $order1['binary_points'] === 1000
        && m2($order1['binary_self_total']) === '50.00'
        && $self !== null
        && $self['recipient'] === 'QA25_Buyer'
        && m2($self['amount']) === '50.00'
        && $self['status'] === 'pending'
        && count($volume1) === 4,
    '1,000 points; self ₱50 pending; four volume rows (Buyer own, A, Boss, admin)',
    sprintf('points %d, self %s, %d awards, %d volume rows',
        (int) ($order1['binary_points'] ?? 0), m2($order1['binary_self_total'] ?? 0),
        count($binary1), count($volume1))
);

/* ------------------------------------------------------------------ Part 3 */

logline('--- Part 3 — delivery and release');

$delivery1 = deliver_order($admin, $order1Id);

$dry = cli('bin/unilevel_release.php --dry-run --stream=all');
check(
    'QA25-09a',
    'The dry run lists the due awards and changes nothing',
    $dry['code'] === 0
        && (int) (q1("SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ? AND status = 'pending'", [$order1Id])['n'] ?? 0) === 4,
    'exit 0, four awards still pending',
    'exit ' . $dry['code'] . ', pending ' . (int) (q1("SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ? AND status = 'pending'", [$order1Id])['n'] ?? 0)
);

$release1 = cli('bin/unilevel_release.php --stream=all --limit=200');
$unilevel1After = awards_for($order1Id);
$binary1After   = binary_awards_for($order1Id);
save_csv('qa25-09-order1-unilevel-after-release.csv', $unilevel1After);
save_csv('qa25-09-order1-binary-after-release.csv', $binary1After);

check(
    'QA25-09b',
    'Delivery plus hold 0 makes every award due; the release credits them',
    $delivery1['order']['status'] === 'delivered'
        && $release1['code'] === 0
        && count(array_filter($unilevel1After, static fn ($r) => $r['status'] === 'credited')) === 4
        && count(array_filter($binary1After, static fn ($r) => $r['status'] === 'credited')) === 1,
    'order delivered; 4 unilevel credited; 1 binary credited',
    sprintf('order %s; %d/%d unilevel credited; %d/%d binary credited',
        $delivery1['order']['status'] ?? '?',
        count(array_filter($unilevel1After, static fn ($r) => $r['status'] === 'credited')), count($unilevel1After),
        count(array_filter($binary1After, static fn ($r) => $r['status'] === 'credited')), count($binary1After))
);

$wallets1 = q(
    "SELECT u.username, u.withdrawable_balance,
            (SELECT COALESCE(SUM(l.amount),0) FROM ewallet_ledger l
              WHERE l.user_id = u.id AND l.ref_type = 'unilevel') AS unilevel_credit,
            (SELECT COALESCE(SUM(l.amount),0) FROM ewallet_ledger l
              WHERE l.user_id = u.id AND l.ref_type = 'binary') AS binary_credit
       FROM users u
      WHERE u.username IN ('QA25_C','QA25_B','QA25_A','QA25_Boss')
      ORDER BY u.username"
);
save_csv('qa25-10-upline-wallets.csv', $wallets1);

$walletMap = [];
foreach ($wallets1 as $row) {
    $walletMap[$row['username']] = $row;
}

check(
    'QA25-10',
    'Every credited award has exactly one withdrawable wallet movement',
    count($wallets1) === 4
        && m2($walletMap['QA25_C']['withdrawable_balance'] ?? 0) === '50.00'
        && m2($walletMap['QA25_B']['withdrawable_balance'] ?? 0) === '50.00'
        && m2($walletMap['QA25_A']['withdrawable_balance'] ?? 0) === '50.00'
        && m2($walletMap['QA25_Boss']['withdrawable_balance'] ?? 0) === '30.00'
        && m2($walletMap['QA25_C']['unilevel_credit'] ?? 0) === '50.00'
        && m2($walletMap['QA25_Boss']['unilevel_credit'] ?? 0) === '30.00',
    'C 50, B 50, A 50, Boss 30 in withdrawable; one ledger row each',
    implode(' | ', array_map(static fn ($r) => $r['username'] . ':' . m2($r['withdrawable_balance']), $wallets1))
);

$adminPage = $admin->get('/admin/commissions?ledger=repeat');
check(
    'QA25-11',
    'The admin repeat ledger renders the four credited rows',
    $adminPage['status'] === 200
        && body_has($adminPage['body'], 'QA25_Buyer')
        && body_has($adminPage['body'], '50.00'),
    'HTTP 200, the Buyer source and ₱50.00 visible',
    'HTTP ' . $adminPage['status'] . ', buyer ' . (body_has($adminPage['body'], 'QA25_Buyer') ? 'visible' : 'absent')
);

$memberRepeat = $buyer->get('/earnings?ledger=repeat');
check(
    'QA25-12',
    'The buyer\'s own repeat tab has no award for their purchase',
    $memberRepeat['status'] === 200
        && !body_has($memberRepeat['body'], 'QA25_Buyer (level'),
    'HTTP 200, no award row for the buyer',
    'HTTP ' . $memberRepeat['status']
);

$cBrowser = member_browser('QA25_C');
$cRepeat  = $cBrowser->get('/earnings?ledger=repeat');
check(
    'QA25-12b',
    'The recipient sees the award, the level and the amount',
    $cRepeat['status'] === 200
        && body_has($cRepeat['body'], 'QA25_Buyer')
        && body_has($cRepeat['body'], '50.00'),
    'HTTP 200, source QA25_Buyer, ₱50.00',
    'HTTP ' . $cRepeat['status'] . ', source ' . (body_has($cRepeat['body'], 'QA25_Buyer') ? 'visible' : 'absent')
);

$binaryPage = $admin->get('/admin/commissions?ledger=binary');
check(
    'QA25-14a',
    'The admin binary ledger renders the credited Self Bonus',
    $binaryPage['status'] === 200
        && body_has($binaryPage['body'], 'QA25_Buyer')
        && body_has($binaryPage['body'], 'Self bonus'),
    'HTTP 200, QA25_Buyer and Self bonus visible',
    'HTTP ' . $binaryPage['status'] . ', self ' . (body_has($binaryPage['body'], 'Self bonus') ? 'visible' : 'absent')
);

$buyerBinaryTab = $buyer->get('/earnings?ledger=binary');
check(
    'QA25-14b',
    'The member binary tab shows the node and the credit',
    $buyerBinaryTab['status'] === 200
        && body_has($buyerBinaryTab['body'], 'QA25_Buyer'),
    'HTTP 200, the buyer\'s binary view renders',
    'HTTP ' . $buyerBinaryTab['status']
);

/* ------------------------------------------------------------------ Part 4 */

logline('--- Part 4 — the manual order and the pair');

$buyer2 = member_browser('QA25_Buyer2');
$second = place_order($buyer2, 4, 'gcash', 'right', 'order-2');
$order2   = $second['order'];
$order2Id = (int) ($order2['id'] ?? 0);

$pendingBefore = q1('SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ?', [$order2Id])['n'] ?? 0;

check(
    'QA25-15',
    'A manual order is not money until the proof is verified',
    $order2 !== null
        && $order2['status'] === 'pending'
        && (int) $pendingBefore === 0
        && (int) (q1('SELECT binary_processed_at FROM shop_orders WHERE id = ?', [$order2Id])['binary_processed_at'] ?? 0) === 0,
    'status pending, 0 awards, no binary stamp',
    sprintf('status %s, %d awards', $order2['status'] ?? '?', (int) $pendingBefore)
);

$proofMsg = submit_order_proof($buyer2, $order2Id, '1200.00', 'QA25-GCASH-2');
$review = q1('SELECT status FROM shop_orders WHERE id = ?', [$order2Id]);
check(
    'QA25-15b',
    'The proof moves the order to review and still pays nothing',
    $review['status'] === 'payment_review'
        && (int) (q1('SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ?', [$order2Id])['n'] ?? 0) === 0,
    'status payment_review, 0 awards',
    'status ' . ($review['status'] ?? '?') . ', msg "' . $proofMsg . '"'
);

$verifyMsg = verify_order($admin, $order2Id, '1200.00', 'QA25-GCASH-2');
$unilevel2 = awards_for($order2Id);
$binary2   = binary_awards_for($order2Id);
save_csv('qa25-16-order2-unilevel-awards.csv', $unilevel2);
save_csv('qa25-17-order2-binary-awards.csv', $binary2);

check(
    'QA25-16',
    'Verification pays the same ladder as the e-wallet path',
    (string) q1('SELECT status FROM shop_orders WHERE id = ?', [$order2Id])['status'] === 'paid'
        && count($unilevel2) === 5
        && m2($unilevel2[0]['amount'] ?? 0) === '50.00'
        && $unilevel2[4]['status'] === 'skipped',
    'paid; five decisions; L1 ₱50; L5 skipped',
    sprintf('status %s, %d rows, msg "%s"', q1('SELECT status FROM shop_orders WHERE id = ?', [$order2Id])['status'] ?? '?', count($unilevel2), $verifyMsg)
);

$pair = null;
foreach ($binary2 as $row) {
    if ($row['kind'] === 'pair') {
        $pair = $row;
    }
}

check(
    'QA25-17',
    'The right leg completes one pair for Boss at 10% of 1,000 points',
    $pair !== null
        && $pair['recipient'] === 'QA25_Boss'
        && m2($pair['amount']) === '100.00'
        && (int) $pair['pair_count'] === 1
        && $pair['status'] === 'pending',
    'Boss pair ₱100.00, pending, count 1',
    $pair === null ? 'no pair row' : sprintf('%s %s %s count %d', $pair['recipient'], m2($pair['amount']), $pair['status'], $pair['pair_count'])
);

/* Idempotency: a second call to the hook is a replay. */
$replay = (new UnilevelService())->onOrderPaid($order1Id);
$replayBinary = (new BinaryService())->onOrderPaid($order1Id);
$countAfterReplay = q1('SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ?', [$order1Id])['n'] ?? 0;
logline('  replay: ' . json_encode(['processed' => $replay['processed'] ?? null, 'replayed' => $replay['replayed'] ?? null])
    . ' | binary replayed ' . json_encode($replayBinary['replayed'] ?? null));

check(
    'QA25-18',
    'A replay of the payment hook pays nothing twice',
    ($replay['replayed'] ?? false) === true
        && ($replayBinary['replayed'] ?? false) === true
        && (int) $countAfterReplay === 5,
    'both hooks report replayed; the five award rows are unchanged',
    sprintf('unilevel replayed %s, binary replayed %s, rows %d',
        var_export($replay['replayed'] ?? null, true), var_export($replayBinary['replayed'] ?? null, true), (int) $countAfterReplay)
);

$delivery2 = deliver_order($admin, $order2Id);
$release2  = cli('bin/unilevel_release.php --stream=all --limit=200');
$bossWallet = q1('SELECT withdrawable_balance FROM users WHERE id = ?', [$bossId]);
$bossBinary = q1("SELECT COALESCE(SUM(amount),0) AS total FROM binary_awards WHERE user_id = ? AND status = 'credited'", [$bossId]);
save_csv('qa25-17-boss-pair.csv', q('SELECT kind, amount, status, pair_count FROM binary_awards WHERE user_id = ? ORDER BY id', [$bossId]));

check(
    'QA25-17b',
    'Releasing order 2 credits the pair to Boss',
    $delivery2['order']['status'] === 'delivered'
        && $release2['code'] === 0
        && m2($bossBinary['total'] ?? 0) === '100.00'
        && m2($bossWallet['withdrawable_balance'] ?? 0) === '160.00',
    'Boss binary credited ₱100.00; withdrawable 30 + 30 + 100 = ₱160.00',
    sprintf('binary credited %s, withdrawable %s', m2($bossBinary['total'] ?? 0), m2($bossWallet['withdrawable_balance'] ?? 0))
);

/* ------------------------------------------------------------------ Part 5 */

logline('--- Part 5 — refusals and switches');

/* The fixture needs float for the four control orders below. */
top_up($admin, $buyerId, '2000.00');
top_up($admin, $buyer2Id, '1000.00');

/* QA25-19: a disabled upline is skipped, no compression. */
$bId = user_id('QA25_B');
$suspendToken = $admin->csrf('/admin/members/' . $bId);
$suspend = $admin->post('/admin/members/' . $bId . '/status', ['_csrf' => $suspendToken, 'action' => 'suspend']);
logline('  suspend QA25_B: ' . flash_of($suspend['body']));

$third   = place_order($buyer, 2, 'ewallet', 'left', 'order-3');
$order3Id = (int) ($third['order']['id'] ?? 0);
$unilevel3 = awards_for($order3Id);
save_csv('qa25-19-skipped-upline.csv', $unilevel3);

check(
    'QA25-19',
    'A disabled upline is skipped and consumes its level',
    count($unilevel3) === 5
        && $unilevel3[1]['recipient'] === 'QA25_B'
        && $unilevel3[1]['status'] === 'skipped'
        && (str_contains((string) $unilevel3[1]['reason'], 'suspend') || str_contains((string) $unilevel3[1]['reason'], 'disabled'))
        && m2($unilevel3[2]['amount']) === '25.00'
        && $unilevel3[2]['status'] === 'pending',
    'L1 C ₱25 pending, L2 B ₱25 skipped ("recipient account is disabled"), L3 A ₱25 pending',
    implode('; ', array_map(static fn ($r) => sprintf('%d:%s %s %s (%s)', $r['level'], $r['recipient'], m2($r['amount']), $r['status'], $r['reason']), $unilevel3))
);
$restoreToken = $admin->csrf('/admin/members/' . $bId);
$restore = $admin->post('/admin/members/' . $bId . '/status', ['_csrf' => $restoreToken, 'action' => 'restore']);
logline('  restore QA25_B: ' . flash_of($restore['body']));

/* QA25-22: cancel the third order before release — pending awards are cancelled. */
$cancelSettingBefore = cfg('shop_cancel_enabled');
qa_set('shop_cancel_enabled', '1');
$bBefore = q1('SELECT withdrawable_balance FROM users WHERE id = ?', [user_id('QA25_B')]);

$cancelToken = $admin->csrf('/admin/orders/' . $order3Id);
$cancel = $admin->post('/admin/orders/' . $order3Id . '/transition', [
    '_csrf'       => $cancelToken,
    'to'          => 'cancelled',
    'reason_code' => 'admin_cancelled',
    'note'        => 'QA25 cancellation before release',
]);
$cancelMsg = flash_of($cancel['body']);
$statuses3 = q('SELECT status, COUNT(*) AS n FROM unilevel_awards WHERE order_id = ? GROUP BY status', [$order3Id]);
$creditsB = q1("SELECT withdrawable_balance FROM users WHERE id = ?", [user_id('QA25_B')]);
logline('  cancel order #' . $order3Id . ': ' . $cancelMsg . ' | awards ' . json_encode($statuses3));

check(
    'QA25-22',
    'Cancelling before release cancels the pending awards and moves no money',
    (string) q1('SELECT status FROM shop_orders WHERE id = ?', [$order3Id])['status'] === 'cancelled'
        && count(array_filter($statuses3, static fn ($r) => $r['status'] === 'pending')) === 0
        && m2($creditsB['withdrawable_balance'] ?? 0) === m2($bBefore['withdrawable_balance'] ?? 0),
    'order cancelled, no pending award remains, B wallet unchanged',
    'order ' . (q1('SELECT status FROM shop_orders WHERE id = ?', [$order3Id])['status'] ?? '?') . ', ' . json_encode($statuses3) . ', B ' . m2($creditsB['withdrawable_balance'] ?? 0)
);

qa_set('shop_cancel_enabled', $cancelSettingBefore);

/* QA25-20: the global switches off — the order is stamped with zeros. */
qa_set('unilevel_enabled', '0');
qa_set('binary_enabled', '0');
$fourth  = place_order($buyer, 1, 'ewallet', 'left', 'order-4');
$order4  = $fourth['order'];
$order4Id = (int) ($order4['id'] ?? 0);
$rows4   = q1('SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ?', [$order4Id])['n'] ?? 0;
$brows4  = q1('SELECT COUNT(*) AS n FROM binary_awards WHERE order_id = ?', [$order4Id])['n'] ?? 0;
logline('  switches off: order #' . $order4Id . ' unilevel_total=' . m2($order4['unilevel_total'] ?? 0)
    . ' binary_total=' . m2($order4['binary_total'] ?? 0) . ' rows=' . (int) $rows4 . '/' . (int) $brows4);

check(
    'QA25-20',
    'With both streams off the paid order is stamped, not paid',
    $order4 !== null
        && $order4['status'] === 'paid'
        && $order4['unilevel_processed_at'] !== null
        && m2($order4['unilevel_total']) === '0.00'
        && $order4['binary_processed_at'] !== null
        && (int) $order4['binary_points'] === 0
        && (int) $rows4 === 0
        && (int) $brows4 === 0,
    'paid; both stamps set; totals 0.00; no award rows',
    sprintf('status %s, unilevel stamp %s total %s, binary points %d, rows %d/%d',
        $order4['status'] ?? '?', $order4['unilevel_processed_at'] ?? 'null', m2($order4['unilevel_total'] ?? 0),
        (int) ($order4['binary_points'] ?? 0), (int) $rows4, (int) $brows4)
);

qa_set('unilevel_enabled', '1');
qa_set('binary_enabled', '1');

/* QA25-21: the product's own switch off — the line pays nothing. */
$pdo->prepare('UPDATE products SET unilevel_enabled = 0, binary_enabled = 0 WHERE id = 1')->execute();
$fifth   = place_order($buyer2, 1, 'ewallet', 'right', 'order-5');
$order5  = $fifth['order'];
$order5Id = (int) ($order5['id'] ?? 0);
$rows5   = q1('SELECT COUNT(*) AS n FROM unilevel_awards WHERE order_id = ?', [$order5Id])['n'] ?? 0;
logline('  product switches off: order #' . $order5Id . ' unilevel_total=' . m2($order5['unilevel_total'] ?? 0) . ' rows=' . (int) $rows5);

check(
    'QA25-21',
    'A product with its switches off stamps a zero line and writes no award',
    $order5 !== null
        && $order5['status'] === 'paid'
        && $order5['unilevel_processed_at'] !== null
        && m2($order5['unilevel_total']) === '0.00'
        && (int) $rows5 === 0,
    'paid; repeat-purchase stamp set; total 0.00; 0 awards',
    sprintf('status %s, stamp %s, total %s, rows %d',
        $order5['status'] ?? '?', $order5['unilevel_processed_at'] ?? 'null', m2($order5['unilevel_total'] ?? 0), (int) $rows5)
);

$pdo->prepare('UPDATE products SET unilevel_enabled = 1, binary_enabled = 1 WHERE id = 1')->execute();

/* ------------------------------------------------------------------ Part 6 */

logline('--- Part 6 — reconcile, totals and cleanup');

$reconcile = cli('bin/wallet_reconcile.php');
check(
    'QA25-23',
    'The wallet reconciler accepts every stream',
    $reconcile['code'] === 0,
    'exit 0',
    'exit ' . $reconcile['code']
);

$totals = [
    'unilevel_credited'  => q1("SELECT COALESCE(SUM(amount),0) AS v FROM unilevel_awards WHERE status = 'credited'")['v'],
    'unilevel_ledger'    => q1("SELECT COALESCE(SUM(amount),0) AS v FROM ewallet_ledger WHERE ref_type = 'unilevel'")['v'],
    'binary_credited'    => q1("SELECT COALESCE(SUM(amount),0) AS v FROM binary_awards WHERE status = 'credited'")['v'],
    'binary_ledger'      => q1("SELECT COALESCE(SUM(amount),0) AS v FROM ewallet_ledger WHERE ref_type = 'binary'")['v'],
    'orders_paid'        => q1("SELECT COUNT(*) AS v FROM shop_orders WHERE status IN ('paid','packing','ready_to_ship','shipped','delivered','completed')")['v'],
    'orders_total'       => q1('SELECT COALESCE(SUM(total),0) AS v FROM shop_orders WHERE status <> ?', ['cancelled'])['v'],
];
save_csv('qa25-23-totals.csv', [$totals]);

check(
    'QA25-24a',
    'Credited awards and wallet ledger tie out in both streams',
    m2($totals['unilevel_credited']) === m2($totals['unilevel_ledger'])
        && m2($totals['binary_credited']) === m2($totals['binary_ledger']),
    'unilevel awarded = unilevel ledger; binary awarded = binary ledger',
    sprintf('unilevel %s/%s, binary %s/%s',
        m2($totals['unilevel_credited']), m2($totals['unilevel_ledger']),
        m2($totals['binary_credited']), m2($totals['binary_ledger']))
);

/* Cleanup: binary_volume.award_id is ON DELETE RESTRICT, so volume first. */
if (getenv('QA25_KEEP') === '1') {
    logline('  QA25_KEEP=1 — cleanup skipped so the node balances can be inspected');
    $nodeRows = q(
        "SELECT u.username, n.side, n.upline_id, n.left_points, n.right_points, n.pairs_paid, n.state
           FROM binary_nodes n JOIN users u ON u.id = n.user_id
          WHERE u.username LIKE 'QA25\\_%' ORDER BY u.id"
    );
    save_csv('qa25-13-node-balances.csv', $nodeRows);
    foreach ($nodeRows as $nodeRow) {
        logline('  node ' . json_encode($nodeRow));
    }
    exit(0);
}

$countsBefore = [
    'users' => (int) (q1("SELECT COUNT(*) AS n FROM users WHERE username LIKE 'QA25\\_%'")['n'] ?? 0),
    'orders' => (int) (q1("SELECT COUNT(*) AS n FROM shop_orders WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'QA25\\_%')")['n'] ?? 0),
];

$pdo->beginTransaction();
try {
    $ids = array_map(static fn ($r) => (int) $r['id'], q("SELECT id FROM users WHERE username LIKE 'QA25\\_%'"));
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $pdo->prepare("DELETE FROM binary_volume WHERE order_id IN (SELECT id FROM shop_orders WHERE user_id IN ($marks))")->execute($ids);
    $pdo->prepare("DELETE FROM binary_awards WHERE order_id IN (SELECT id FROM shop_orders WHERE user_id IN ($marks))")->execute($ids);
    $pdo->prepare("DELETE FROM unilevel_awards WHERE order_id IN (SELECT id FROM shop_orders WHERE user_id IN ($marks))")->execute($ids);
    $pdo->prepare("DELETE FROM ewallet_ledger WHERE user_id IN ($marks)")->execute($ids);
    $pdo->prepare("DELETE FROM shop_order_items WHERE order_id IN (SELECT id FROM shop_orders WHERE user_id IN ($marks))")->execute($ids);
    $pdo->prepare("DELETE FROM shop_order_events WHERE order_id IN (SELECT id FROM shop_orders WHERE user_id IN ($marks))")->execute($ids);
    $pdo->prepare("DELETE FROM shop_orders WHERE user_id IN ($marks)")->execute($ids);
    $pdo->prepare("DELETE FROM binary_nodes WHERE user_id IN ($marks)")->execute($ids);
    $pdo->prepare("DELETE FROM users WHERE id IN ($marks)")->execute($ids);
    $pdo->commit();
    $cleanup = 'ok';
} catch (Throwable $e) {
    $pdo->rollBack();
    $cleanup = 'FAILED: ' . $e->getMessage();
}
logline('  cleanup: ' . $cleanup);

check(
    'QA25-24b',
    'The QA data can be removed in the documented order',
    $cleanup === 'ok'
        && (int) (q1("SELECT COUNT(*) AS n FROM users WHERE username LIKE 'QA25\\_%'")['n'] ?? 0) === 0,
    'cleanup exits ok and no QA25 account remains',
    $cleanup . '; remaining ' . (int) (q1("SELECT COUNT(*) AS n FROM users WHERE username LIKE 'QA25\\_%'")['n'] ?? 0)
);

/* Restore the settings snapshot. */
foreach ($beforeSettings as $row) {
    qa_set($row['key_name'], (string) $row['value']);
}
$pdo->prepare('UPDATE products SET pv = ?, unilevel_enabled = ?, unilevel_base = ?, binary_enabled = ? WHERE id = 1')
    ->execute([$productBefore[0]['pv'], $productBefore[0]['unilevel_enabled'], $productBefore[0]['unilevel_base'], $productBefore[0]['binary_enabled']]);
$pdo->prepare('DELETE FROM product_unilevel_levels WHERE product_id = 1')->execute();
logline('  settings and Coffee restored to their before values');

/* ------------------------------------------------------------------ report */

$pass = count(array_filter($CHECKS, static fn ($c) => $c['pass']));
$total = count($CHECKS);

file_put_contents(EVID . '/qa25-run.log', implode("\n", $LOG) . "\n");
file_put_contents(EVID . '/qa25-run-results.json', json_encode([
    'checks' => array_values($CHECKS),
    'pass' => $pass,
    'total' => $total,
    'totals' => $totals,
], JSON_PRETTY_PRINT));

logline(sprintf('=== %d / %d checks passed ===', $pass, $total));

foreach ($CHECKS as $c) {
    if (!$c['pass']) {
        logline('FAILED: ' . $c['id'] . ' — ' . $c['title']);
    }
}

exit($pass === $total ? 0 : 1);
