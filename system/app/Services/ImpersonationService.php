<?php
declare(strict_types=1);

/* ==============================================================
 *  ImpersonationService — Super-Login (plan §3.7, §4.4, §7 Phase 14;
 *  reference core/Auth.php + controllers/AuthController.php +
 *  models/ImpLog.php)
 *
 *  A superadmin opens a member's portal without a password:
 *
 *    1. start()  validates the caller and the target, rate-limits the
 *       attempt, draws a 32-byte nonce and writes the audit row with
 *       only its SHA-256.
 *    2. The controller closes the superadmin's cookie session, opens a
 *       cookie-less PHP session whose id IS the nonce and calls bind()
 *       to put the member identity in it.
 *    3. Every later request carries ?imp=<nonce>; check() re-validates
 *       the session, TTL, IP + user-agent binding, the log row and the
 *       target's right to be viewed.
 *    4. Exit, TTL, an origin change or a suspended target ends the
 *       session and marks the row logged_out / expired.
 *
 *  The raw nonce never reaches the database. The URL is a bearer
 *  credential, so the console cannot re-offer a lost link.
 * ============================================================== */

final class ImpersonationService
{
    /** An S-Login session lives 8 hours (plan §7 Phase 14). */
    public const TTL = IMP_SESSION_TTL;

    /** Failed attempts allowed per target inside the window. */
    public const MAX_ATTEMPTS = IMP_MAX_ATTEMPTS;

    /** The rate-limit window, in seconds (15 minutes). */
    public const WINDOW = IMP_ATTEMPT_WINDOW;

    /** Same username shape the register form accepts. */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,50}$/';

    /* ----------------------------------------------------------
     *  Open a session
     * ---------------------------------------------------------- */

    /**
     * Validate and issue a new impersonation session.
     *
     * @param array<string, mixed>|null $superadmin the signed-in row (defaults to auth_user())
     * @return array{
     *   nonce:string, log_id:int, expires_at:int, expires_at_db:string,
     *   superadmin:array<string, mixed>, target:array<string, mixed>,
     *   ip:string, ua:string
     * }
     * @throws ImpersonationException
     */
    public function start(
        string $username,
        ?array $superadmin = null,
        ?string $ip = null,
        ?string $ua = null
    ): array {
        $superadmin ??= auth_user();
        $ip         ??= Request::ip();
        $ua         ??= Request::userAgent();

        if ($superadmin === null || !is_superadmin($superadmin)) {
            throw ImpersonationException::make('admin', 'Only a superadmin can open a member session.');
        }

        $username = trim($username);

        if ($username === '' || preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $this->auditDenied($superadmin, mb_substr($username, 0, 50), 'username', $ip);

            throw ImpersonationException::make('username', 'Enter a valid member username.');
        }

        $key = strtolower($username);

        if ($this->attempts($key) >= self::MAX_ATTEMPTS) {
            $this->auditDenied($superadmin, $username, 'rate_limited', $ip);

            throw ImpersonationException::make(
                'rate_limited',
                'Too many S-Login attempts for that member. Please wait 15 minutes and try again.'
            );
        }

        $target = Member::findByUsername($username);

        if ($target === null) {
            $this->fail($superadmin, $key, $username, null, 'not_found', $ip);
            throw ImpersonationException::make('not_found', 'No member with that username was found.');
        }

        $targetId = (int) $target['id'];

        if ((string) $target['role'] !== 'member') {
            $this->fail($superadmin, $key, $username, $targetId, 'not_member', $ip);
            throw ImpersonationException::make('not_member', 'S-Login is only available for member accounts.');
        }

        if ((int) $target['is_active'] !== 1 || (string) $target['member_status'] === 'suspended') {
            $this->fail($superadmin, $key, $username, $targetId, 'suspended', $ip);
            throw ImpersonationException::make('suspended', 'That member account is suspended or deactivated.');
        }

        if ((int) $superadmin['id'] === $targetId) {
            throw ImpersonationException::make('self', 'You are already signed in as that account.');
        }

        $this->clearAttempts($key);

        $nonce     = bin2hex(random_bytes(32));
        $expiresAt = time() + self::TTL;
        $expiresDb = date('Y-m-d H:i:s', $expiresAt);

        $logId = Impersonation::record(
            (int) $superadmin['id'],
            (string) $superadmin['username'],
            $targetId,
            (string) $target['username'],
            hash('sha256', $nonce),
            $ip,
            $ua,
            $expiresDb
        );

        try {
            AuditLog::record([
                'action'       => 'slogin.start',
                'actor_id'     => (int) $superadmin['id'],
                'actor_role'   => (string) $superadmin['role'],
                'subject_type' => 'user',
                'subject_id'   => $targetId,
                'meta'         => [
                    'impersonation_id' => $logId,
                    'target'           => (string) $target['username'],
                    'expires_at'       => $expiresDb,
                    'user_agent'       => mb_substr($ua, 0, 120),
                ],
                'ip'           => $ip,
            ]);
        } catch (Throwable $e) {
            error_log('[ecowealth] S-Login start audit failed: ' . $e->getMessage());
        }

        return [
            'nonce'         => $nonce,
            'log_id'        => $logId,
            'expires_at'    => $expiresAt,
            'expires_at_db' => $expiresDb,
            'superadmin'    => $superadmin,
            'target'        => $target,
            'ip'            => $ip,
            'ua'            => mb_substr($ua, 0, 255),
        ];
    }

    /**
     * Put the member identity and the session markers into the freshly
     * opened cookie-less session (the caller opened it via
     * auth_start_imp_session()).
     *
     * @param array<string, mixed> $issued the array returned by start()
     */
    public static function bind(array $issued): void
    {
        $nonce = (string) ($issued['nonce'] ?? '');

        if (preg_match('/\A[0-9a-f]{64}\z/', $nonce) !== 1) {
            throw new InvalidArgumentException('Cannot bind an S-Login session without a valid nonce.');
        }

        $target     = is_array($issued['target'] ?? null) ? $issued['target'] : [];
        $superadmin = is_array($issued['superadmin'] ?? null) ? $issued['superadmin'] : [];

        $_SESSION['user_id']       = (int) ($target['id'] ?? 0);
        $_SESSION['logged_in_at']  = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['auth_via']      = 'slogin';

        $_SESSION['imp_session']            = true;
        $_SESSION['imp_token']              = $nonce;
        $_SESSION['imp_log_id']             = (int) ($issued['log_id'] ?? 0);
        $_SESSION['imp_created_by']         = (int) ($superadmin['id'] ?? 0);
        $_SESSION['imp_created_by_username'] = (string) ($superadmin['username'] ?? '');
        $_SESSION['imp_target_username']    = (string) ($target['username'] ?? '');
        $_SESSION['imp_expires']            = (int) ($issued['expires_at'] ?? 0);
        $_SESSION['imp_ip']                 = (string) ($issued['ip'] ?? '');
        $_SESSION['imp_ua']                 = (string) ($issued['ua'] ?? '');

        /* The cookie session's token must not travel into this one: the
         * impersonated tab gets its own CSRF token when it renders a form. */
        unset($_SESSION['_csrf']);

        auth_cache_reset();
    }

    /* ----------------------------------------------------------
     *  Validate every impersonated request
     * ---------------------------------------------------------- */

    /**
     * Re-validate the current impersonation session.
     *
     * Never redirects and never destroys the session — the front
     * controller / controller turn a refusal into the appropriate
     * redirect and audit row.
     *
     * @param array<string, mixed>|null $session defaults to $_SESSION
     * @return array{ok:bool, reason:string, log:array<string, mixed>|null, target:array<string, mixed>|null}
     */
    public function check(
        ?array $session = null,
        ?string $ip = null,
        ?string $ua = null,
        ?int $now = null
    ): array {
        $session ??= $_SESSION;
        $ip      ??= Request::ip();
        $ua      ??= Request::userAgent();
        $now     ??= time();

        $refuse = static fn (string $reason, ?array $log = null): array => [
            'ok'     => false,
            'reason' => $reason,
            'log'    => $log,
            'target' => null,
        ];

        if (empty($session['imp_session'])) {
            return $refuse('missing');
        }

        $token = (string) ($session['imp_token'] ?? '');

        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return $refuse('missing');
        }

        $logId = (int) ($session['imp_log_id'] ?? 0);

        if ($logId <= 0) {
            return $refuse('missing_log');
        }

        $log = Impersonation::find($logId);

        if ($log === null) {
            return $refuse('missing_log');
        }

        if (!hash_equals((string) $log['nonce_hash'], hash('sha256', $token))) {
            return $refuse('tampered', $log);
        }

        if ((int) $log['target_user_id'] !== (int) ($session['user_id'] ?? 0)
            || (int) $log['superadmin_id'] !== (int) ($session['imp_created_by'] ?? 0)) {
            return $refuse('tampered', $log);
        }

        if ((string) $log['status'] === 'logged_out') {
            return $refuse('logged_out', $log);
        }

        if ((string) $log['status'] === 'expired') {
            return $refuse('expired', $log);
        }

        if ((string) $log['status'] !== 'active') {
            return $refuse('missing_log', $log);
        }

        $sessionExpires = (int) ($session['imp_expires'] ?? 0);
        $rowExpires     = strtotime((string) $log['expires_at']);
        $rowExpires     = $rowExpires === false ? 0 : $rowExpires;

        if ($sessionExpires <= $now || $rowExpires <= $now) {
            return $refuse('expired', $log);
        }

        if (!hash_equals((string) $log['ip'], $ip)
            || !hash_equals((string) ($session['imp_ip'] ?? ''), $ip)) {
            return $refuse('ip', $log);
        }

        if (!hash_equals((string) $log['user_agent'], $ua)
            || !hash_equals((string) ($session['imp_ua'] ?? ''), $ua)) {
            return $refuse('ua', $log);
        }

        $target = Member::find((int) $log['target_user_id']);

        if ($target === null
            || (string) $target['role'] !== 'member'
            || (int) $target['is_active'] !== 1
            || (string) $target['member_status'] === 'suspended') {
            return $refuse('target', $log);
        }

        return [
            'ok'     => true,
            'reason' => 'ok',
            'log'    => $log,
            'target' => $target,
        ];
    }

    /* ----------------------------------------------------------
     *  End a session
     * ---------------------------------------------------------- */

    /**
     * End the current S-Login session as a deliberate exit.
     *
     * Marks the audit row logged_out (the first terminal status wins),
     * records the audit entry and leaves session cleanup to the caller
     * (auth_end_imp_session()).
     *
     * @param array<string, mixed>|null $session defaults to $_SESSION
     * @return array<string, mixed> the log row that was ended
     * @throws ImpersonationException
     */
    public function exit(?array $session = null): array
    {
        $session ??= $_SESSION;
        $logId     = (int) ($session['imp_log_id'] ?? 0);

        if (empty($session['imp_session']) || $logId <= 0) {
            throw ImpersonationException::make('missing', 'There is no super-login session to end.');
        }

        $log = Impersonation::find($logId);

        if ($log === null) {
            throw ImpersonationException::make('missing_log', 'That super-login session is no longer recorded.');
        }

        Impersonation::mark($logId, 'logged_out');

        try {
            AuditLog::record([
                'action'       => 'slogin.stop',
                'actor_id'     => (int) $log['superadmin_id'],
                'actor_role'   => 'superadmin',
                'subject_type' => 'user',
                'subject_id'   => (int) $log['target_user_id'],
                'meta'         => [
                    'impersonation_id' => $logId,
                    'target'           => (string) $log['target_username'],
                ],
                'ip'           => Request::ip(),
            ]);
        } catch (Throwable $e) {
            error_log('[ecowealth] S-Login stop audit failed: ' . $e->getMessage());
        }

        return $log;
    }

    /* ----------------------------------------------------------
     *  Rate limit (per target, inside the superadmin's session)
     * ---------------------------------------------------------- */

    /** Failed attempts for a target inside the current window. */
    public function attempts(string $key): int
    {
        return count($this->attemptList($key));
    }

    /** Remaining attempts before the console refuses this target. */
    public function attemptsLeft(string $key): int
    {
        return max(0, self::MAX_ATTEMPTS - $this->attempts($key));
    }

    /** @return list<int> timestamps still inside the window */
    private function attemptList(string $key): array
    {
        $store = session_status() === PHP_SESSION_ACTIVE
            ? ($_SESSION['_slogin_attempts'] ?? [])
            : ($GLOBALS['__slogin_attempts'] ?? []);

        $list   = is_array($store[$key] ?? null) ? $store[$key] : [];
        $cutoff = time() - self::WINDOW;

        return array_values(array_filter(
            $list,
            static fn (mixed $ts): bool => is_numeric($ts) && (int) $ts > $cutoff
        ));
    }

    private function recordFailure(string $key): void
    {
        $list   = $this->attemptList($key);
        $list[] = time();

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_slogin_attempts'][$key] = $list;
        } else {
            $GLOBALS['__slogin_attempts'][$key] = $list;
        }
    }

    private function clearAttempts(string $key): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['_slogin_attempts'][$key]);
        } else {
            unset($GLOBALS['__slogin_attempts'][$key]);
        }
    }

    /* ----------------------------------------------------------
     *  Audit of refused attempts
     * ---------------------------------------------------------- */

    /**
     * Record the refusal and count it against the target's window.
     *
     * @param array<string, mixed> $superadmin
     */
    private function fail(
        array $superadmin,
        string $key,
        string $username,
        ?int $targetId,
        string $reason,
        string $ip
    ): void {
        $this->recordFailure($key);
        $this->auditDenied($superadmin, $username, $reason, $ip, $targetId);
    }

    /**
     * @param array<string, mixed> $superadmin
     */
    private function auditDenied(
        array $superadmin,
        string $username,
        string $reason,
        string $ip,
        ?int $targetId = null
    ): void {
        try {
            AuditLog::record([
                'action'       => 'slogin.denied',
                'actor_id'     => (int) ($superadmin['id'] ?? 0),
                'actor_role'   => (string) ($superadmin['role'] ?? 'superadmin'),
                'subject_type' => $targetId !== null ? 'user' : null,
                'subject_id'   => $targetId,
                'meta'         => [
                    'target' => mb_substr($username, 0, 50),
                    'reason' => $reason,
                ],
                'ip'           => $ip,
            ]);
        } catch (Throwable $e) {
            error_log('[ecowealth] S-Login denial audit failed: ' . $e->getMessage());
        }
    }
}
