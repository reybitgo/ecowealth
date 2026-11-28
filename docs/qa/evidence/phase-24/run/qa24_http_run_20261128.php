<?php
declare(strict_types=1);

/* ============================================================================
 * Phase 24 QA — automated execution of docs/qa/phase-24-package-commissions.md
 *
 * Runs the walkthrough against an ISOLATED schema (ecowealth_sneat_qa24) over
 * real HTTP (php -S on 127.0.0.1:8899), then asserts the database state the
 * document expects. Every HTTP status and every flash/validation message is
 * written to the run log.
 *
 *   ECO_DB_NAME=ecowealth_sneat_qa24 php qa24_http_run.php
 * ========================================================================== */

define('APP_BASE_URL', 'http://127.0.0.1:8899/');
define('APP_ENV', 'test');
putenv('ECO_DB_NAME=ecowealth_sneat');

require __DIR__ . '/../../../../../system/app/bootstrap.php';

const BASE    = 'http://127.0.0.1:8899';
const DB_NAME_QA = 'ecowealth_sneat';
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'Admin@123';
const MEMBER_PASS = 'Demo@1234';
const EVID = __DIR__ . '/run-20261128';
if (!is_dir(EVID)) { mkdir(EVID, 0777, true); }

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
    $CHECKS[$id] = ['id' => $id, 'title' => $title, 'pass' => $ok];
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
        $this->jar   = sys_get_temp_dir() . '/qa24_' . preg_replace('/\W+/', '_', $label) . '.cookies';
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

/** @param array<string,string> $extra */
function register(Browser $b, string $mode, string $username, string $sponsor, string $upline, string $side, array $extra = []): array
{
    /* A successful signup signs the new member in, so every registration
     * needs its own signed-out session (otherwise the next one runs as the
     * previous member and the placement is refused as "outside your subtree"). */
    $b = new Browser('guest_' . $username);
    $token = $b->csrf('/register');
    $fields = array_merge([
        '_csrf'            => $token,
        'mode'             => $mode,
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
    ], $extra);

    $res = $b->post('/register', $fields);
    $row = q1('SELECT id, username, member_status, package_id, sponsor_id FROM users WHERE username = ?', [$username]);
    $msg = flash_of($res['body']);
    logline(sprintf('  register %-20s mode=%-8s status=%d user=%s msg=%s', $username, $mode, $res['status'], $row ? ('#' . $row['id']) : 'MISSING', $msg !== '' ? $msg : '(none)'));

    return ['res' => $res, 'user' => $row, 'msg' => $msg];
}

function proof_file(): array
{
    static $path = null;

    if ($path === null) {
        $path = sys_get_temp_dir() . '/qa24-proof.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    return ['path' => $path, 'type' => 'image/png', 'name' => 'receipt.png'];
}

/** Submit a manual proof as the signed-in member. */
function submit_proof(Browser $member, string $reference): array
{
    $token = $member->csrf('/membership');
    $res = $member->request('POST', '/membership/activate', [
        '_csrf'        => $token,
        'method'       => 'manual',
        'package_id'   => '1',
        'channel'      => 'gcash',
        'reference_no' => $reference,
    ], ['proof' => proof_file()]);
    logline(sprintf('  proof %-12s status=%d msg=%s', $reference, $res['status'], flash_of($res['body']) ?: '(none)'));

    return $res;
}

function latest_activation(string $username): ?array
{
    return q1(
        'SELECT a.* FROM activations a JOIN users u ON u.id = a.user_id WHERE u.username = ? ORDER BY a.id DESC LIMIT 1',
        [$username]
    );
}

function admin_approve(Browser $admin, int $activationId): string
{
    $token = $admin->csrf('/admin/activations');
    $res = $admin->post('/admin/activations/' . $activationId . '/approve', ['_csrf' => $token]);
    $msg = flash_of($res['body']);
    logline(sprintf('  approve activation #%d: status=%d msg=%s', $activationId, $res['status'], $msg ?: '(none)'));

    return $msg;
}

function admin_reject(Browser $admin, int $activationId, string $reason): string
{
    $token = $admin->csrf('/admin/activations');
    $res = $admin->post('/admin/activations/' . $activationId . '/reject', ['_csrf' => $token, 'reason' => $reason]);
    $msg = flash_of($res['body']);
    logline(sprintf('  reject activation #%d: status=%d msg=%s', $activationId, $res['status'], $msg ?: '(none)'));

    return $msg;
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

function issue_codes(int $quantity): array
{
    $result = (new CodeService())->generate(
        ['package_id' => 1, 'quantity' => $quantity, 'price' => '0'],
        ['id' => 1, 'role' => 'admin']
    );
    logline('issued registration codes: ' . implode(', ', $result['codes']));

    return $result['codes'];
}

function cli(string $command): array
{
    $full = 'set "ECO_DB_NAME=' . DB_NAME_QA . '" && php ' . $command . ' 2>&1';
    $out  = [];
    $code = 0;
    exec('cd ' . escapeshellarg(__DIR__ . '/../../../../../system') . ' && ' . $full, $out, $code);
    $text = implode("\n", $out);
    logline(sprintf('  $ php %s → exit %d', $command, $code));
    foreach ($out as $line) {
        logline('    | ' . $line);
    }

    return ['code' => $code, 'text' => $text];
}

function save_evidence(string $name, array $rows): void
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
    logline('  evidence written: ' . $name . ' (' . count($rows) . ' rows)');
}

/* ------------------------------------------------------------- the run ---- */

logline('=== Phase 24 walkthrough — automated execution ===');
logline('database: ' . DB_NAME_QA . ' | app: ' . BASE);

$admin = admin_browser();
$guest = new Browser('guest'); // registrations run signed out, exactly as QA24-01/02 describe
if (!body_has($admin->lastBody, 'Dashboard') && !body_has($admin->lastBody, 'Sign out')) {
    logline('ABORT: admin sign-in did not reach an authenticated page.');
    exit(2);
}

/* --- Part 1 ------------------------------------------------------------- */
logline('--- Part 1 — settings and Package A points (QA24-07, QA24-08)');

$settingsBefore = q("SELECT key_name, value FROM settings WHERE key_name IN
  ('commission_direct_enabled','commission_indirect_enabled','commission_indirect_max_levels',
   'team_harvest_enabled','team_harvest_qualify_directs','team_harvest_max_levels',
   'general_harvest_enabled','general_harvest_max_levels','package_binary_enabled')");
save_evidence('qa24-07-settings-before.csv', $settingsBefore);
logline('  settings before: ' . json_encode(array_column($settingsBefore, 'value', 'key_name')));

$packageBefore = q1('SELECT id, name, binary_enabled, binary_points FROM packages WHERE id = 1');
save_evidence('qa24-08-package-before.csv', [$packageBefore]);

qa_set('commission_direct_enabled', '1');
qa_set('commission_indirect_enabled', '1');
qa_set('commission_indirect_max_levels', '4');
qa_set('team_harvest_enabled', '1');
qa_set('team_harvest_qualify_directs', '1');
qa_set('team_harvest_max_levels', '1');
qa_set('general_harvest_enabled', '0'); // the arm refuses an enabled stream; switched on after arming
qa_set('general_harvest_max_levels', '1');

check('QA24-07', 'QA settings saved', cfg('commission_indirect_max_levels') === '4'
    && cfg('team_harvest_qualify_directs') === '1' && cfg('general_harvest_max_levels') === '1',
    'depth 4, gate 1, general depth 1', 'depth ' . cfg('commission_indirect_max_levels')
    . ', gate ' . cfg('team_harvest_qualify_directs') . ', general ' . cfg('general_harvest_max_levels'));

/* The queue must be seeded BEFORE the first qualifying registration: an
 * unseeded queue makes the hook inert, and seeding afterwards turns every
 * completed level into a `baseline` row that is never paid. */
logline('--- seeding the Team Harvest queue (before any registration)');
$seed = cli('bin/harvest_queue_seed.php');
check('QA24-19-seed', 'queue seeded before registrations', $seed['code'] === 0 && cfg('team_harvest_ledger_since') !== '',
    'exit 0 and a ledger_since baseline', 'exit ' . $seed['code'] . ', ledger_since="' . cfg('team_harvest_ledger_since') . '"');

/* The General Harvest board must ALSO be armed before the queue grows: a
 * block already full at the arm is recorded `baseline` and never paid. */
logline('--- arming the General Harvest board at N = 0 (before any registration)');
$armEarly = cli('bin/general_harvest_arm.php');
qa_set('general_harvest_enabled', '1');
$board = q1('SELECT baseline_n, armed_at FROM general_harvest_board WHERE id = 1');
check('QA24-23-arm', 'board armed at N = 0 before the tree is built',
    $armEarly['code'] === 0 && $board !== null && (string) $board['baseline_n'] === '0' && $board['armed_at'] !== null,
    'exit 0, baseline_n 0, armed_at set', 'exit ' . $armEarly['code'] . ', board ' . json_encode($board));

$pdo->prepare('UPDATE packages SET binary_enabled = 1, binary_points = 1000 WHERE id = 1')->execute();
qa_set('package_binary_enabled', '1');

$p = q1('SELECT binary_enabled, binary_points FROM packages WHERE id = 1');
check('QA24-08', 'Package A points enabled before the first activation',
    (string) $p['binary_enabled'] === '1' && (string) $p['binary_points'] === '1000' && cfg('package_binary_enabled') === '1',
    'enabled=1 points=1000 master=1',
    'enabled=' . $p['binary_enabled'] . ' points=' . $p['binary_points'] . ' master=' . cfg('package_binary_enabled'));

/* --- Part 2 ------------------------------------------------------------- */
logline('--- Part 2 — anchor account and the pair (QA24-02, QA24-15..17)');
$codes = issue_codes(7); // Sponsor, A, B, NoPkg_Member, three negative-control members

$sponsor = register($guest, 'code', 'QA24_Sponsor', '', '', 'left', ['reg_code' => $codes[0]]);
$memberA = register($guest, 'code', 'QA24_Member_A', 'QA24_Sponsor', '', 'left', ['reg_code' => $codes[1]]);
$memberB = register($guest, 'code', 'QA24_Member_B', 'QA24_Member_A', 'QA24_Sponsor', 'right', ['reg_code' => $codes[2]]);

$rowSponsor = q1("SELECT u.id, u.member_status, u.package_id, bn.side, bn.upline_id FROM users u LEFT JOIN binary_nodes bn ON bn.user_id = u.id WHERE u.username='QA24_Sponsor'");
$rowA = q1("SELECT u.id, u.member_status, bn.side, bn.upline_id FROM users u LEFT JOIN binary_nodes bn ON bn.user_id = u.id WHERE u.username='QA24_Member_A'");
$rowB = q1("SELECT u.id, u.member_status, bn.side, bn.upline_id FROM users u LEFT JOIN binary_nodes bn ON bn.user_id = u.id WHERE u.username='QA24_Member_B'");
logline('  nodes: Sponsor=' . json_encode($rowSponsor) . ' A=' . json_encode($rowA) . ' B=' . json_encode($rowB));

check('QA24-02', 'anchor account and pair created', $sponsor['user'] !== null && $memberA['user'] !== null && $memberB['user'] !== null,
    'three active members', 'three members rows: ' . ($sponsor['user'] ? 'yes' : 'no') . '/' . ($memberA['user'] ? 'yes' : 'no') . '/' . ($memberB['user'] ? 'yes' : 'no'));

$pairAward = q1("SELECT a.* FROM binary_awards a JOIN users u ON u.id=a.user_id WHERE u.username='QA24_Sponsor' AND a.kind='pair'");
logline('  sponsor pair award: ' . json_encode($pairAward));
check('QA24-16', 'exactly one pair award of ₱100 to the sponsor',
    $pairAward !== null && m2($pairAward['amount']) === '100.00',
    'one pair, 100.00', $pairAward ? ('amount ' . m2($pairAward['amount'])) : 'no pair award');

$sponsorPoints = q1("SELECT binary_points, binary_total, binary_processed_at FROM activations a JOIN users u ON u.id=a.user_id WHERE u.username='QA24_Sponsor' ORDER BY a.id DESC LIMIT 1");
check('QA24-15', 'points stamped on the activation', $sponsorPoints !== null && (string) $sponsorPoints['binary_points'] === '1000',
    'binary_points 1000', $sponsorPoints ? ('points ' . $sponsorPoints['binary_points'] . ', total ' . $sponsorPoints['binary_total']) : 'no activation');

/* --- Part 3 ------------------------------------------------------------- */
logline('--- Part 3 — fill the tree (QA24-01, QA24-03, QA24-04)');

$team = [
    ['QA24_Team_1', 'QA24_Sponsor', 'QA24_Member_A', 'left'],
    ['QA24_Team_1a', 'QA24_Team_1', '', 'left'],
    ['QA24_Team_2', 'QA24_Sponsor', 'QA24_Member_A', 'right'],
    ['QA24_Team_2a', 'QA24_Team_2', '', 'left'],
    ['QA24_Team_3', 'QA24_Sponsor', 'QA24_Team_1', 'right'],
    ['QA24_Team_3a', 'QA24_Team_3', '', 'left'],
];
foreach ($team as [$name, $sp, $up, $side]) {
    register($guest, 'standard', $name, $sp, $up, $side);
}

$queue = q('SELECT q.id, u.username, q.teammates, q.baseline_n FROM harvest_queue q JOIN users u ON u.id=q.user_id ORDER BY q.id');
save_evidence('qa24-19-queue.csv', $queue);
logline('  queue: ' . json_encode(array_map(static fn ($r) => $r['username'] . '#' . $r['id'] . ' n=' . $r['teammates'], $queue)));

$teamAwards = q('SELECT a.id, u.username, a.level, a.threshold_n, a.n_at_award, a.amount, a.status FROM team_harvest_awards a JOIN users u ON u.id=a.user_id ORDER BY a.id');
save_evidence('qa24-20-team-awards.csv', $teamAwards);
logline('  team awards: ' . json_encode($teamAwards));

$teamTotal = 0.0;
foreach ($teamAwards as $award) {
    if ((string) $award['status'] === 'credited') {
        $teamTotal += (float) $award['amount'];
    }
}
check('QA24-20', 'team harvest pays the Sponsor ₱1,000 once',
    count($teamAwards) === 1 && m2($teamTotal) === '1000.00' && $teamAwards[0]['username'] === 'QA24_Sponsor',
    '1 credited row (Sponsor), total 1000.00',
    count($teamAwards) . ' rows, total ' . m2($teamTotal) . ', names ' . implode(',', array_column($teamAwards, 'username')));

/* QA24-03 — an invalid registration code is refused with a message. */
$badCode = register($guest, 'code', 'QA24_BadCode', 'QA24_Sponsor', '', 'left', ['reg_code' => 'XXXX-XXXX-XXXX']);
check('QA24-03', 'invalid registration code refused', $badCode['user'] === null && $badCode['msg'] !== '',
    'no account and a message', ($badCode['user'] === null ? 'no account' : 'ACCOUNT CREATED') . '; msg: ' . $badCode['msg']);

/* QA24-04 — member C: pending registration, proof, admin approval. */
logline('--- QA24-04 — member C activates with a proof');
$memberC = register($guest, 'pending', 'QA24_Member_C', 'QA24_Member_B', '', 'left');
$cBrowser = member_browser('QA24_Member_C');
submit_proof($cBrowser, 'QA24-C-001');
$cAct = latest_activation('QA24_Member_C');
logline('  C activation: ' . json_encode($cAct ? ['id' => $cAct['id'], 'status' => $cAct['status']] : null));
if ($cAct !== null) {
    logline('  C approve message: ' . admin_approve($admin, (int) $cAct['id']));
}
$cAfter = q1("SELECT u.member_status, u.package_id, a.status, a.commission_processed_at FROM users u LEFT JOIN activations a ON a.user_id=u.id WHERE u.username='QA24_Member_C' ORDER BY a.id DESC LIMIT 1");
check('QA24-04', 'member C active with Package A after approval',
    $cAfter !== null && (string) $cAfter['member_status'] === 'active' && (string) $cAfter['status'] === 'approved',
    'active + approved', json_encode($cAfter));

/* --- Part 4 ------------------------------------------------------------- */
logline('--- Part 4 — referral ladder (QA24-09 .. QA24-14)');
$commissions = q('SELECT c.id, u.username AS recipient, s.username AS source, c.type, c.level, c.amount, c.status, c.ledger_id
  FROM commissions c JOIN users u ON u.id=c.user_id LEFT JOIN users s ON s.id=c.source_user_id ORDER BY c.id');
save_evidence('qa24-09-commissions.csv', $commissions);
foreach ($commissions as $c) {
    logline(sprintf('  commission %-16s %-10s L%d %s %s ledger=%s', $c['recipient'], $c['source'], $c['level'], $c['amount'], $c['status'], $c['ledger_id'] ?? '-'));
}

$credited = [];
foreach ($commissions as $c) {
    if ((string) $c['status'] === 'credited') {
        $credited[] = $c;
    }
}
check('QA24-09', 'activation 1 pays the sponsor 500 direct',
    count(array_filter($credited, static fn ($c) => $c['recipient'] === 'QA24_Sponsor' && $c['level'] == 1 && m2($c['amount']) === '500.00')) === 1,
    'one 500 row', (string) count($credited) . ' credited rows total');

check('QA24-10', 'activation 2 pays A 500 direct and Sponsor 100 indirect',
    count(array_filter($credited, static fn ($c) => $c['source'] === 'QA24_Member_B' && $c['recipient'] === 'QA24_Member_A' && $c['level'] == 1)) === 1
    && count(array_filter($credited, static fn ($c) => $c['source'] === 'QA24_Member_B' && $c['recipient'] === 'QA24_Sponsor' && $c['level'] == 2 && m2($c['amount']) === '100.00')) === 1,
    'A 500 L1 + Sponsor 100 L2', 'see commission rows above');

check('QA24-11', 'activation 3 pays three levels with the company root skipped',
    count(array_filter($credited, static fn ($c) => $c['source'] === 'QA24_Member_C' && $c['status'] === 'credited')) === 3,
    '3 credited rows from C', (string) count(array_filter($commissions, static fn ($c) => $c['source'] === 'QA24_Member_C')) . ' rows from C');

$earn = member_browser('QA24_Sponsor')->get('/earnings');
logline('  /earnings status ' . $earn['status'] . ' | has 650.00: ' . (body_has($earn['body'], '650.00') ? 'yes' : 'no'));
check('QA24-13', 'member earnings page renders the ladder and total',
    $earn['status'] === 200 && body_has($earn['body'], '650.00'),
    'HTTP 200 with 650.00', 'HTTP ' . $earn['status'] . ', 650.00 ' . (body_has($earn['body'], '650.00') ? 'present' : 'MISSING'));

$ledgerPage = $admin->get('/admin/commissions');
logline('  /admin/commissions status ' . $ledgerPage['status'] . ' | has 500.00: ' . (body_has($ledgerPage['body'], '500.00') ? 'yes' : 'no'));
check('QA24-14', 'admin commission ledger renders', $ledgerPage['status'] === 200 && body_has($ledgerPage['body'], '500.00'),
    'HTTP 200 with award rows', 'HTTP ' . $ledgerPage['status']);

$export = $admin->get('/admin/commissions/export');
logline('  /admin/commissions/export status ' . $export['status'] . ', ' . strlen($export['body']) . ' bytes');
check('QA24-14-export', 'admin ledger CSV export', $export['status'] === 200 && str_contains($export['body'], ','),
    'HTTP 200 CSV', 'HTTP ' . $export['status']);

/* --- Part 5 ------------------------------------------------------------- */
logline('--- Part 5 — harvest (QA24-19 .. QA24-26)');
$queueN = (int) (q1('SELECT COUNT(*) AS n, MAX(id) AS max_id FROM harvest_queue')['n'] ?? 0);
check('QA24-19', 'queue holds six positions with no gaps', $queueN === 6,
    '6 positions', (string) $queueN . ' positions');

$boardState = q1('SELECT baseline_n, settled_n, armed_at FROM general_harvest_board WHERE id = 1');
logline('  board state: ' . json_encode($boardState));
check('QA24-23', 'board settled the first position as the tree grew',
    $boardState !== null && (int) $boardState['settled_n'] >= 1,
    'settled_n >= 1', json_encode($boardState));

$general = q('SELECT a.id, a.seq, u.username, a.level, a.amount, a.status FROM general_harvest_awards a JOIN users u ON u.id=a.user_id ORDER BY a.id');
save_evidence('qa24-24-general-awards.csv', $general);
$generalTotal = 0.0;
foreach ($general as $g) {
    $generalTotal += (float) $g['amount'];
}
logline('  general awards: ' . json_encode($general));
check('QA24-24', 'one general award of ₱2,000 to position #1', count($general) === 1 && m2($generalTotal) === '2000.00',
    '1 award, 2000.00', count($general) . ' awards, ' . m2($generalTotal));

$positions = $admin->get('/admin/commissions?ledger=general_harvest');
logline('  general harvest page status ' . $positions['status']);
check('QA24-25', 'general harvest position view renders', $positions['status'] === 200, 'HTTP 200', 'HTTP ' . $positions['status']);

$teamPage = $admin->get('/admin/commissions?ledger=team_harvest');
check('QA24-22', 'admin team harvest ledger renders', $teamPage['status'] === 200, 'HTTP 200', 'HTTP ' . $teamPage['status']);

$teamMember = member_browser('QA24_Sponsor')->get('/earnings?ledger=team_harvest');
check('QA24-21', 'member Team Harvest tab renders', $teamMember['status'] === 200 && body_has($teamMember['body'], '1,000'),
    'HTTP 200 with 1,000', 'HTTP ' . $teamMember['status'] . ', amount ' . (body_has($teamMember['body'], '1,000') ? 'present' : 'MISSING'));

/* --- Part 6 ------------------------------------------------------------- */
logline('--- Part 6 — late activations (QA24-05, QA24-12, QA24-06, QA24-18)');

$memberD = register($guest, 'pending', 'QA24_Member_D', 'QA24_Member_C', '', 'left');
$dBrowser = member_browser('QA24_Member_D');
submit_proof($dBrowser, 'QA24-D-REJECT');
$dAct = latest_activation('QA24_Member_D');
$rejectMsg = $dAct !== null ? admin_reject($admin, (int) $dAct['id'], 'Blurred receipt') : 'no activation';
$dState = q1("SELECT u.member_status, a.status FROM users u LEFT JOIN activations a ON a.user_id=u.id WHERE u.username='QA24_Member_D' ORDER BY a.id DESC LIMIT 1");
check('QA24-05', 'rejected proof leaves D pending and pays nothing',
    $dAct !== null && (string) $dState['member_status'] === 'pending' && (string) $dState['status'] === 'rejected',
    'pending + rejected', json_encode($dState) . ' | msg: ' . $rejectMsg);

/* Re-registering the same member is not possible; submit a new proof. */
submit_proof($dBrowser, 'QA24-D-002');
$dAct2 = latest_activation('QA24_Member_D');
$approveMsg = $dAct2 !== null ? admin_approve($admin, (int) $dAct2['id']) : 'no activation';
logline('  D approve message: ' . $approveMsg);

$fromD = q("SELECT u.username AS recipient, c.level, c.amount, c.status, c.ledger_id FROM commissions c
  JOIN users u ON u.id=c.user_id JOIN users s ON s.id=c.source_user_id WHERE s.username='QA24_Member_D' ORDER BY c.level");
save_evidence('qa24-12-from-d.csv', $fromD);
logline('  from D: ' . json_encode($fromD));
$level4 = array_values(array_filter($fromD, static fn ($r) => (int) $r['level'] === 4));
check('QA24-12', 'D activation pays all four configured levels',
    count(array_filter($fromD, static fn ($r) => (string) $r['status'] === 'credited')) === 4
    && count($level4) === 1 && $level4[0]['recipient'] === 'QA24_Sponsor' && m2($level4[0]['amount']) === '40.00',
    '4 credited rows, L4 Sponsor 40.00', count($fromD) . ' rows; L4 ' . json_encode($level4[0] ?? null));

/* QA24-06 — sponsor without a package. */
$noPkg = register($guest, 'standard', 'QA24_NoPkg_Sponsor', 'QA24_Sponsor', 'admin', 'right');
$noPkgMember = register($guest, 'code', 'QA24_NoPkg_Member', 'QA24_NoPkg_Sponsor', '', 'left', ['reg_code' => $codes[3]]);
$noPkgRows = q("SELECT u.username AS recipient, c.level, c.amount, c.status FROM commissions c
  JOIN users u ON u.id=c.user_id JOIN users s ON s.id=c.source_user_id WHERE s.username='QA24_NoPkg_Member' ORDER BY c.level");
save_evidence('qa24-06-skipped.csv', $noPkgRows);
logline('  from NoPkg_Member: ' . json_encode($noPkgRows));
$skip500 = array_values(array_filter($noPkgRows, static fn ($r) => $r['recipient'] === 'QA24_NoPkg_Sponsor' && (string) $r['status'] === 'skipped'));
$sponsor100 = array_values(array_filter($noPkgRows, static fn ($r) => $r['recipient'] === 'QA24_Sponsor' && m2($r['amount']) === '100.00'));
check('QA24-06', 'no-package sponsor is skipped and the next upline is paid',
    count($skip500) === 1 && m2($skip500[0]['amount']) === '500.00' && count($sponsor100) === 1,
    '1 skipped 500 + 1 credited 100', json_encode($noPkgRows));

/* QA24-18 — three zero-point activations. */
logline('--- QA24-18 — zero points / disabled package / disabled master switch');
$negNames = ['QA24_Zero1', 'QA24_Zero2', 'QA24_Zero3'];
/* Each needs a FREE binary slot: the sponsor's own left slot is taken by A. */
$negUplines = ['QA24_Team_1a', 'QA24_Team_2a', 'QA24_Team_3a'];
foreach ($negNames as $i => $name) {
    register($guest, 'pending', $name, 'QA24_Sponsor', $negUplines[$i], 'left');
}
$negResults = [];

/* 1: package points 0 */
$pdo->prepare('UPDATE packages SET binary_points = 0, binary_enabled = 1 WHERE id = 1')->execute();
$b = member_browser($negNames[0]);
submit_proof($b, 'QA24-Z1');
$act = latest_activation($negNames[0]);
if ($act) { admin_approve($admin, (int) $act['id']); }
$negResults[0] = latest_activation($negNames[0]);

/* 2: package binary disabled */
$pdo->prepare('UPDATE packages SET binary_points = 1000, binary_enabled = 0 WHERE id = 1')->execute();
$b = member_browser($negNames[1]);
submit_proof($b, 'QA24-Z2');
$act = latest_activation($negNames[1]);
if ($act) { admin_approve($admin, (int) $act['id']); }
$negResults[1] = latest_activation($negNames[1]);

/* 3: master switch off */
$pdo->prepare('UPDATE packages SET binary_enabled = 1, binary_points = 1000 WHERE id = 1')->execute();
qa_set('package_binary_enabled', '0');
$b = member_browser($negNames[2]);
submit_proof($b, 'QA24-Z3');
$act = latest_activation($negNames[2]);
if ($act) { admin_approve($admin, (int) $act['id']); }
$negResults[2] = latest_activation($negNames[2]);

$restoreProblem = (string) cfg('package_binary_enabled') !== '1';

/* restore the QA values (the document's step 4) */
$pdo->prepare('UPDATE packages SET binary_enabled = 1, binary_points = 1000 WHERE id = 1')->execute();
qa_set('package_binary_enabled', '1');

$zeroOk = true;
foreach ($negResults as $row) {
    if ($row === null || (string) $row['binary_points'] !== '0' || m2($row['binary_total']) !== '0.00') {
        $zeroOk = false;
    }
}
save_evidence('qa24-18-zero-points.csv', array_values(array_filter($negResults)));
check('QA24-18', 'three zero-point activations stamp zeros',
    $zeroOk, 'each activation binary_points=0, binary_total=0.00',
    json_encode(array_map(static fn ($r) => $r ? ['points' => $r['binary_points'], 'total' => $r['binary_total']] : null, $negResults)));

$volumeRows = (int) (q1("SELECT COUNT(*) AS n FROM binary_volume")['n'] ?? 0);
logline('  binary_volume rows: ' . $volumeRows . ' (not asserted: the earlier pairs legally wrote volume)');

/* --- Part 7 ------------------------------------------------------------- */
logline('--- Part 7 — reconcile, tie out (QA24-27, QA24-28)');
$reconcile = cli('bin/wallet_reconcile.php');
check('QA24-27', 'wallet reconciliation exits clean', $reconcile['code'] === 0, 'exit 0', 'exit ' . $reconcile['code']);

$sums = q1("SELECT
  (SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status='credited') AS awards,
  (SELECT COALESCE(SUM(amount),0) FROM ewallet_ledger WHERE ref_type='commission') AS ledger,
  (SELECT COALESCE(SUM(amount),0) FROM team_harvest_awards WHERE status='credited') AS team,
  (SELECT COALESCE(SUM(amount),0) FROM general_harvest_awards) AS general,
  (SELECT COALESCE(SUM(amount),0) FROM binary_awards WHERE status='credited') AS pairs");
logline('  sums: ' . json_encode($sums));
check('QA24-28', 'awards equal ledger lines', m2($sums['awards']) === m2($sums['ledger']),
    'awards == ledger', m2($sums['awards']) . ' vs ' . m2($sums['ledger']));

$orphans = (int) (q1("SELECT COUNT(*) AS n FROM commissions WHERE status='credited' AND ledger_id IS NULL")['n'] ?? 0);
check('QA24-28-b', 'every credited award has a ledger id', $orphans === 0, '0 orphans', $orphans . ' orphans');

/* --- snapshot + cleanup (QA24-29) -------------------------------------- */
logline('--- snapshot and cleanup (QA24-29)');
save_evidence('qa24-29-final-wallets.csv', q('SELECT username, ewallet_balance, withdrawable_balance FROM users WHERE role = \'member\' ORDER BY username'));
save_evidence('qa24-29-final-queue.csv', q('SELECT q.id, u.username, q.teammates FROM harvest_queue q JOIN users u ON u.id=q.user_id ORDER BY q.id'));

$before = (int) (q1("SELECT COUNT(*) AS n FROM users WHERE username LIKE 'QA24%'")['n'] ?? 0);
save_evidence('qa24-29-commissions-before-cleanup.csv', q('SELECT c.id, u.username AS recipient, c.type, c.level, c.amount, c.status FROM commissions c JOIN users u ON u.id=c.user_id ORDER BY c.id'));
$pdo->exec("DELETE FROM reg_codes WHERE code IN ('" . implode("','", array_map('addslashes', $codes)) . "')");

/* The document's cleanup deletes the QA24 accounts. That delete is what we
 * are testing: report what actually happens. */
$cleanupError = '';
try {
    $pdo->exec("DELETE bv FROM binary_volume bv JOIN binary_awards ba ON ba.id = bv.award_id JOIN users u ON u.id = ba.user_id WHERE u.username LIKE 'QA24%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'QA24%'");
} catch (Throwable $e) {
    $cleanupError = $e->getMessage();
    logline('  cleanup refused: ' . $cleanupError);
}
$after = (int) (q1("SELECT COUNT(*) AS n FROM users WHERE username LIKE 'QA24%'")['n'] ?? 0);
logline(sprintf('  cleanup: %d QA24 users before, %d after', $before, $after));

/* restore the recorded pre-run settings */
$pdo->prepare('UPDATE packages SET binary_enabled = 0, binary_points = 0 WHERE id = 1')->execute();
foreach ($settingsBefore as $row) {
    qa_set((string) $row['key_name'], (string) $row['value']);
}
qa_set('package_binary_enabled', '0');
check('QA24-29', 'QA24 accounts removed by deleting them', $after === 0,
    '0 QA24 users', $after . ' users remain' . ($cleanupError !== '' ? ' — ' . $cleanupError : ''));

/* ------------------------------------------------------------------------ */
$pass = count(array_filter($CHECKS, static fn ($c) => $c['pass']));
$fail = count($CHECKS) - $pass;

logline('');
logline(sprintf('=== result: %d checks executed, %d passed, %d failed ===', count($CHECKS), $pass, $fail));
foreach ($CHECKS as $c) {
    if (!$c['pass']) {
        logline('  FAILED: ' . $c['id'] . ' — ' . $c['title']);
    }
}

file_put_contents(EVID . '/qa24-run.log', implode("\n", $LOG) . "\n");
file_put_contents(EVID . '/qa24-run-results.json', json_encode([
    'database' => DB_NAME_QA,
    'ran_at'   => date('c'),
    'checks'   => array_values($CHECKS),
    'totals'   => ['executed' => count($CHECKS), 'passed' => $pass, 'failed' => $fail],
], JSON_PRETTY_PRINT));

echo "\nlog: " . EVID . "/qa24-run.log\n";
exit($fail === 0 ? 0 : 1);
