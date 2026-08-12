<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

/**
 * Authentication helpers: login brute-force protection, logout, ban management.
 */
class Auth
{
    private const int LOCK_DURATION  = 604800; // 1 week
    private const int ATTEMPT_WINDOW = 3600;   // sliding window (1 hour)
    private const int MAX_ATTEMPTS   = 3;

    /**
     * Central POST action permission map.
     *
     * null means any authenticated user may run the action. Unknown actions are
     * denied by Auth::canPerformAction(), so newly added POST handlers must be
     * classified here before they can execute.
     *
     * @var array<string, string|null>
     */
    private const array ACTION_PERMISSIONS = [
        // Session / navigation
        'login'        => null,
        'dev_login'    => null,
        'logout'       => null,
        'switch_store' => null,

        // Narrow read-only lookups
        'spotcheck'        => null,
        'tag_search'       => null,
        'metafield_search' => null,
        'metafield_lookup' => null,
        'customer_lookup'  => null,
        'lookup_tracking'  => null,
        'compare_orders'   => null,
        'order_timeline'   => null,
        'packingslip'      => null,
        'order_detail'     => null,

        // Operational mutations
        'push_to_shipstation' => 'push',
        'bulk_push'           => 'push',
        'preview_push'        => 'push',
        'save_order_note'     => 'edit_order',
        'ignore_order'        => 'ignore',
        'unignore_order'      => 'ignore',
        'bulk_ignore_orders'  => 'ignore',
        'bulk_unignore_orders'=> 'ignore',
        'import_ignore_csv'   => 'ignore',
        'flush_cache'         => 'flush_cache',
        'queue_audit'         => 'queue_audit',
        'run_audit'           => 'run_audit',
        'pq_add'              => 'manage_queue',
        'pq_remove'           => 'manage_queue',
        'pq_clear'            => 'manage_queue',

        // Batch scans and reports
        'tag_audit'             => 'run_audit',
        'scan_addresses'        => 'run_audit',
        'scan_emails'           => 'run_audit',
        'scan_hvorders'         => 'run_audit',
        'find_refunds'          => 'run_audit',
        'find_dupes'            => 'run_audit',
        'find_orphans'          => 'run_audit',
        'scan_repeat_refunds'   => 'run_audit',
        'scan_failed_shipments' => 'run_audit',
        'scan_addr_changes'     => 'run_audit',
        'scan_order_edits'      => 'run_audit',
        'scan_noteflags'        => 'run_audit',
        'scan_addrdupes'        => 'run_audit',
        'scan_discountabuse'    => 'run_audit',
        'scan_tagpolicy'        => 'run_audit',
        'scan_country_mismatch' => 'run_audit',
        'scan_partial_fulfill'  => 'run_audit',
        'scan_onhold'           => 'run_audit',
        'scan_notracking'       => 'run_audit',
        'scan_postshipaddr'     => 'run_audit',
        'scan_ssshipped'        => 'run_audit',
        'scan_sla'              => 'run_audit',
        'scan_shipmentaging'    => 'run_audit',
        'scan_carrierperf'      => 'run_audit',
        'scan_shipmargin'       => 'run_audit',
        'scan_fulfilleditems'   => 'run_audit',
        'email_fulfilleditems'  => 'run_audit',
        'scan_returneditems'    => 'run_audit',
        'email_returneditems'   => 'run_audit',
        'scan_itemmismatch'     => 'run_audit',
        'scan_activess'         => 'run_audit',
        'scan_bundle'           => 'run_audit',
        'scan_products'         => 'run_audit',
        'scan_skudupes'         => 'run_audit',
        'scan_inventory'        => 'run_audit',
        'scan_zombieproducts'   => 'run_audit',
        'scan_inventoryaging'   => 'run_audit',
        'scan_inventoryforecast'=> 'run_audit',
        'scan_returns'          => 'run_audit',
        'scan_ltv'              => 'run_audit',

        // Admin-only checks and settings
        'test_connection'    => 'manage_settings',
        'refresh_api_health' => 'manage_settings',
        'save_settings'      => 'manage_settings',
        'ban_ip'             => 'manage_settings',
        'unban_ip'           => 'manage_settings',
        'save_slack_rules'   => 'manage_settings',
        'save_email_rules'   => 'manage_settings',
        'save_sidebar_settings' => 'manage_settings',
        'add_user'           => 'manage_users',
        'delete_user'        => 'manage_users',
    ];

    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/login_attempts.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/login_attempts.json');
    }

    /**
     * Attempt a login. Returns an empty string on success, an error message on failure.
     * On success the caller is responsible for setting $_SESSION['authed'] = true.
     */
    public static function attempt(
        string $inputUser,
        string $inputPass,
        string $correctUser,
        string $correctPass,
        string $ip
    ): string {
        $attemptsFile = self::file();
        if (!is_dir(dirname($attemptsFile))) {
            mkdir(dirname($attemptsFile), 0755, true);
        }

        $lock     = self::lockAttempts($attemptsFile);
        $raw      = file_exists($attemptsFile) ? (string) file_get_contents($attemptsFile) : '';
        $attempts = $raw ? (json_decode($raw, true) ?: []) : [];

        $now = time();
        // Keep entries that are still banned OR had a recent failed attempt
        $attempts = array_filter(
            $attempts,
            fn($e) => ($e['until'] ?? 0) > $now || ($e['first'] ?? 0) > $now - self::ATTEMPT_WINDOW
        );

        $entry    = $attempts[$ip] ?? ['count' => 0, 'first' => $now, 'until' => 0];
        $lockedOut = ($entry['until'] ?? 0) > $now;

        if ($lockedOut) {
            self::writeAttempts($attemptsFile, $attempts);
            self::unlockAttempts($lock);
            $secs  = $entry['until'] - $now;
            $days  = (int) floor($secs / 86400);
            $hours = (int) floor(($secs % 86400) / 3600);
            return $days > 0
                ? "Too many failed attempts. Try again in {$days} day" . ($days !== 1 ? 's' : '') . ($hours > 0 ? " and {$hours}h" : '') . '.'
                : "Too many failed attempts. Try again in {$hours} hour" . ($hours !== 1 ? 's' : '') . '.';
        }

        $okUser = hash_equals($correctUser, $inputUser);
        $okPass = self::verifyPassword($inputPass, $correctPass);

        if ($okUser && $okPass) {
            unset($attempts[$ip]);
            self::writeAttempts($attemptsFile, $attempts);
            self::unlockAttempts($lock);
            return '';
        }

        $entry['count'] = ($entry['count'] ?? 0) + 1;
        if (!isset($entry['first'])) $entry['first'] = $now;
        if ($entry['count'] >= self::MAX_ATTEMPTS) {
            $entry['until'] = $now + self::LOCK_DURATION;
        }
        $attempts[$ip] = $entry;
        self::writeAttempts($attemptsFile, $attempts);
        self::unlockAttempts($lock);

        $remaining = self::MAX_ATTEMPTS - $entry['count'];
        return $remaining > 0
            ? 'Incorrect username or password. ' . $remaining . ' attempt' . ($remaining !== 1 ? 's' : '') . ' remaining.'
            : 'Too many failed attempts. Account locked for 1 week. Contact your administrator.';
    }

    /**
     * Destroy the current session (logout).
     */
    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function verifyPassword(string $inputPass, string $correctPass): bool
    {
        $info = password_get_info($correctPass);
        if (($info['algo'] ?? 0) !== 0) {
            return password_verify($inputPass, $correctPass);
        }
        return hash_equals($correctPass, $inputPass);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function rotateCsrfToken(): string
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function validateCsrf(string $token): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], $token);
    }

    // ── RBAC ─────────────────────────────────────────────────────────────────

    /**
     * Returns the role of the currently logged-in user ('viewer'|'operator'|'admin').
     * Defaults to 'admin' so that existing sessions (pre-RBAC) are not downgraded.
     */
    public static function role(): string
    {
        return $_SESSION['user_role'] ?? 'admin';
    }

    /**
     * Check if the current user may perform the given abstract action.
     *
     * Actions understood:
     *   'push'            – push orders to ShipStation (operator+)
     *   'ignore'          – ignore/unignore orders (operator+)
     *   'run_audit'       – run / queue audits (operator+)
     *   'flush_cache'     – flush cache (operator+)
     *   'queue_audit'     – queue audit jobs (operator+)
     *   'manage_settings' – change settings, ban/unban IPs, Slack rules (admin only)
     *   'manage_users'    – add/delete users (admin only)
     */
    public static function can(string $action): bool
    {
        $role = self::role();
        $adminOnly    = ['manage_settings', 'manage_users'];
        $operatorPlus = ['push', 'ignore', 'run_audit', 'flush_cache', 'queue_audit', 'edit_order', 'manage_queue'];

        if (in_array($action, $adminOnly, true)) {
            return $role === 'admin';
        }
        if (in_array($action, $operatorPlus, true)) {
            return in_array($role, ['operator', 'admin'], true);
        }
        return true; // viewers can read everything
    }

    /**
     * Returns null for authenticated-only actions, a permission name for
     * restricted actions, and false for unknown actions.
     */
    public static function permissionForAction(string $action): string|false|null
    {
        return array_key_exists($action, self::ACTION_PERMISSIONS)
            ? self::ACTION_PERMISSIONS[$action]
            : false;
    }

    public static function canPerformAction(string $action): bool
    {
        $permission = self::permissionForAction($action);
        if ($permission === false) {
            return false;
        }
        if ($permission === null) {
            return true;
        }
        return self::can($permission);
    }

    public static function isUnsafeLegacyPassword(string $password): bool
    {
        $password = trim($password);
        return $password === '' || in_array($password, ['changeme', 'change_me_now'], true);
    }

    // ── Multi-user support ────────────────────────────────────────────────────

    /**
     * Attempt login against data/users.json.
     * Applies the same brute-force tracking as attempt().
     * Returns the matched role string on success, '' on failure (bad credentials or locked out).
     */
    public static function attemptMultiUser(string $username, string $password, string $ip): string
    {
        $attemptsFile = self::file();
        if (!is_dir(dirname($attemptsFile))) {
            mkdir(dirname($attemptsFile), 0755, true);
        }

        $lock     = self::lockAttempts($attemptsFile);
        $raw      = file_exists($attemptsFile) ? (string) file_get_contents($attemptsFile) : '';
        $attempts = $raw ? (json_decode($raw, true) ?: []) : [];

        $now = time();
        $attempts = array_filter(
            $attempts,
            fn($e) => ($e['until'] ?? 0) > $now || ($e['first'] ?? 0) > $now - self::ATTEMPT_WINDOW
        );

        $entry     = $attempts[$ip] ?? ['count' => 0, 'first' => $now, 'until' => 0];
        $lockedOut = ($entry['until'] ?? 0) > $now;

        if ($lockedOut) {
            self::writeAttempts($attemptsFile, $attempts);
            self::unlockAttempts($lock);
            return '';
        }

        // Verify credentials against users.json
        $role  = '';
        $users = self::loadUsers();
        foreach ($users as $user) {
            if (isset($user['name'], $user['password_hash'], $user['role'])
                && hash_equals((string) $user['name'], $username)
                && self::verifyPassword($password, (string) $user['password_hash'])
            ) {
                $role = (string) $user['role'];
                break;
            }
        }

        if ($role !== '') {
            unset($attempts[$ip]);
            self::writeAttempts($attemptsFile, $attempts);
            self::unlockAttempts($lock);
            return $role;
        }

        $entry['count'] = ($entry['count'] ?? 0) + 1;
        if (!isset($entry['first'])) $entry['first'] = $now;
        if ($entry['count'] >= self::MAX_ATTEMPTS) {
            $entry['until'] = $now + self::LOCK_DURATION;
        }
        $attempts[$ip] = $entry;
        self::writeAttempts($attemptsFile, $attempts);
        self::unlockAttempts($lock);
        return '';
    }

    /**
     * Load all users from data/users.json.
     *
     * @return array<int, array<string, string>>
     */
    public static function loadUsers(): array
    {
        $file = self::usersFile();
        if (!file_exists($file)) return [];
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Persist the users array to data/users.json.
     *
     * @param array<int, array<string, string>> $users
     */
    public static function saveUsers(array $users): void
    {
        $file = self::usersFile();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        AtomicFile::writeJson($file, array_values($users));
    }

    private static function usersFile(): string
    {
        return __DIR__ . '/../data/users.json';
    }

    /**
     * Remove a specific IP from the ban list.
     */
    public static function unban(string $ip): void
    {
        $attemptsFile = self::file();
        if (!$ip || !file_exists($attemptsFile)) {
            return;
        }
        $lock = self::lockAttempts($attemptsFile);
        $attempts = json_decode((string) file_get_contents($attemptsFile), true) ?: [];
        unset($attempts[$ip]);
        self::writeAttempts($attemptsFile, $attempts);
        self::unlockAttempts($lock);
    }

    /**
     * @param array<string, mixed> $attempts
     */
    private static function writeAttempts(string $file, array $attempts): void
    {
        AtomicFile::writeJson($file, $attempts, 0);
    }

    /**
     * @return resource
     */
    private static function lockAttempts(string $file)
    {
        $lock = fopen($file . '.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException("Could not open login attempts lock file for {$file}");
        }
        flock($lock, LOCK_EX);
        return $lock;
    }

    /**
     * @param resource $lock
     */
    private static function unlockAttempts($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Returns an associative array of currently banned IPs keyed by IP address.
     * Each value is the raw entry array from the attempts file.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function bannedIps(): array
    {
        $attemptsFile = self::file();
        if (!file_exists($attemptsFile)) {
            return [];
        }
        $now    = time();
        $all    = json_decode(file_get_contents($attemptsFile), true) ?: [];
        $banned = [];
        foreach ($all as $ip => $entry) {
            if (($entry['until'] ?? 0) > $now) {
                $banned[$ip] = $entry;
            }
        }
        return $banned;
    }
}
