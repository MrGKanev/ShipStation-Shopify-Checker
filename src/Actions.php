<?php
declare(strict_types=1);

/**
 * Handles all POST actions that terminate with a redirect or direct output.
 * Call Actions::dispatch() early in index.php; it exits if the action was handled.
 */
class Actions
{
    public static function dispatch(string $action, array $ctx): void
    {
        if (!$ctx['authed']) return;

        match ($action) {
            'switch_store'        => self::switchStore($ctx),
            'unban_ip'            => self::unbanIp($ctx),
            'ignore_order'        => self::ignoreOrder($ctx),
            'unignore_order'      => self::unignoreOrder($ctx),
            'bulk_ignore_orders'  => self::bulkIgnore($ctx),
            'bulk_unignore_orders'=> self::bulkUnignore($ctx),
            'import_ignore_csv'   => self::importIgnoreCsv($ctx),
            'push_to_shipstation' => self::pushToShipStation($ctx),
            'queue_audit'         => self::queueAudit($ctx),
            'save_slack_rules'    => self::saveSlackRules($ctx),
            'save_email_rules'    => self::saveEmailRules($ctx),
            'save_sidebar_settings' => self::saveSidebarSettings($ctx),
            'preview_push'        => self::previewPush($ctx),
            'order_detail'        => self::orderDetail($ctx),
            'flush_cache'         => self::flushCache($ctx),
            'test_connection'     => self::testConnection($ctx),
            'refresh_api_health'  => self::refreshApiHealth($ctx),
            'pq_add'              => self::printQueueAdd(),
            'pq_remove'           => self::printQueueRemove(),
            'pq_clear'            => self::printQueueClear(),
            'add_user'            => self::addUser($ctx),
            'delete_user'         => self::deleteUser($ctx),
            'save_order_note'     => self::saveOrderNote($ctx),
            default               => null,
        };

        if (($_GET['action'] ?? '') === 'download') {
            self::csvDownload($ctx);
        }
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    private static function switchStore(array $ctx): void
    {
        if (!class_exists('Stores') || !Stores::isMultiStore()) return;
        $storeId = $_POST['store_id'] ?? '';
        Stores::setActive($storeId);
        UserActionLog::append('switch_store', ['store_id' => $storeId]);
        header('Location: ?');
        exit;
    }

    private static function unbanIp(array $ctx): void
    {
        $ip = $_POST['ip'] ?? '';
        Auth::unban($ip);
        UserActionLog::append('unban_ip', ['ip' => $ip]);
        header('Location: ?page=settings&unbanned=1');
        exit;
    }

    private static function ignoreOrder(array $ctx): void
    {
        $norm = Comparator::normalise($_POST['order_number'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        IgnoreList::add($norm, $reason);
        UserActionLog::append('ignore_order', ['order_number' => $norm, 'reason' => $reason]);
        header('Location: ' . self::redirectBack()); exit;
    }

    private static function unignoreOrder(array $ctx): void
    {
        $norm = Comparator::normalise($_POST['order_number'] ?? '');
        IgnoreList::remove($norm);
        UserActionLog::append('unignore_order', ['order_number' => $norm]);
        header('Location: ' . self::redirectBack()); exit;
    }

    private static function bulkIgnore(array $ctx): void
    {
        $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
        $reason  = trim($_POST['reason'] ?? '');
        $entries = self::buildBulkIgnoreEntries($numbers, $reason);
        IgnoreList::bulkAdd($entries);
        UserActionLog::append('bulk_ignore_orders', ['count' => count($entries), 'reason' => $reason]);
        header('Location: ' . self::redirectBack()); exit;
    }

    /**
     * Normalises raw order numbers into IgnoreList::bulkAdd() entries,
     * dropping any that normalise to an empty string.
     *
     * @param  array<int, mixed> $rawNumbers
     * @return array<int, array{number: string, reason: string}>
     */
    public static function buildBulkIgnoreEntries(array $rawNumbers, string $reason): array
    {
        $entries = [];
        foreach ($rawNumbers as $raw) {
            $norm = Comparator::normalise((string) $raw);
            if ($norm) $entries[] = ['number' => $norm, 'reason' => $reason];
        }
        return $entries;
    }

    private static function bulkUnignore(array $ctx): void
    {
        $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
        $norms   = self::normaliseOrderNumbers($numbers);
        IgnoreList::bulkRemove($norms);
        UserActionLog::append('bulk_unignore_orders', ['count' => count($norms)]);
        header('Location: ?page=ignored'); exit;
    }

    /**
     * Normalises raw order numbers, dropping any that normalise to an
     * empty string (dedup is IgnoreList::bulkRemove()'s job, not ours).
     *
     * @param  array<int, mixed> $rawNumbers
     * @return array<int, string>
     */
    public static function normaliseOrderNumbers(array $rawNumbers): array
    {
        return array_values(array_filter(array_map(
            fn($raw) => Comparator::normalise((string) $raw),
            $rawNumbers
        )));
    }

    private static function importIgnoreCsv(array $ctx): void
    {
        $file   = $_FILES['ignore_csv'] ?? null;
        $reason = trim($_POST['import_reason'] ?? '') ?: 'CSV import ' . date('Y-m-d');
        $count  = ($file && $file['error'] === UPLOAD_ERR_OK)
            ? IgnoreList::importCsv($file['tmp_name'], $reason)
            : 0;
        UserActionLog::append('import_ignore_csv', ['count' => $count, 'reason' => $reason]);
        header('Location: ?page=ignored&imported=' . $count); exit;
    }

    private static function pushToShipStation(array $ctx): void
    {
        $shopifyId = trim($_POST['shopify_id'] ?? '');
        $loc       = self::redirectBack('run');

        if (!$shopifyId || !$ctx['ssKey'] || !$ctx['ssSecret'] || !$ctx['shopifyToken']) {
            $loc .= '&push_error=' . urlencode('Missing credentials or order ID.');
        } else {
            try {
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                $pushed  = self::performPush($shopify, $ss, $shopifyId);

                PushLog::append($pushed + ['pushed_at' => date('Y-m-d H:i:s')]);
                UserActionLog::append('push_to_shipstation', $pushed);

                $loc .= '&push_ok=' . urlencode($pushed['order_number']);
            } catch (Throwable $e) {
                $loc .= '&push_error=' . urlencode($e->getMessage());
            }
        }

        header('Location: ' . $loc); exit;
    }

    /**
     * Fetches $shopifyId from Shopify and creates the corresponding
     * ShipStation order. Throws if the Shopify order can't be found -
     * callers decide how to surface that (redirect vs. JSON error).
     *
     * @return array{order_number: string, shopify_id: string, ss_order_id: mixed}
     */
    public static function performPush(Shopify $shopify, ShipStation $ss, string $shopifyId): array
    {
        $shopifyOrder = $shopify->getOrder($shopifyId);
        if (empty($shopifyOrder)) {
            throw new RuntimeException("Order {$shopifyId} not found in Shopify.");
        }

        $created  = $ss->createOrder($shopifyOrder);
        $orderNum = $created['orderNumber'] ?? $shopifyId;

        return [
            'order_number' => $orderNum,
            'shopify_id'   => $shopifyId,
            'ss_order_id'  => $created['orderId'] ?? null,
        ];
    }

    private static function queueAudit(array $ctx): void
    {
        $start = trim($_POST['audit_start'] ?? '');
        $end   = trim($_POST['audit_end'] ?? '');
        $loc   = '?page=jobs';

        if ($err = DateRange::validate($start, $end)) {
            header('Location: ' . $loc . '&queue_error=' . urlencode($err)); exit;
        }

        $id = JobQueue::enqueue('audit', [
            'start'    => $start,
            'end'      => $end,
            'store_id' => $ctx['storeId'] ?? '',
        ], "Audit {$start} -> {$end}");
        UserActionLog::append('queue_audit', ['job_id' => $id, 'start' => $start, 'end' => $end]);
        header('Location: ' . $loc . '&queued=' . urlencode($id)); exit;
    }

    private static function saveSlackRules(array $ctx): void
    {
        $rules = [
            'audit_enabled'      => isset($_POST['audit_enabled']),
            'audit_min_missing'  => $_POST['audit_min_missing'] ?? 0,
            'scan_enabled'       => isset($_POST['scan_enabled']),
            'scan_min_rows'      => $_POST['scan_min_rows'] ?? 1,
            'include_zero_audit' => isset($_POST['include_zero_audit']),
        ];
        SlackRules::save($rules);
        UserActionLog::append('save_slack_rules', SlackRules::load());
        header('Location: ?page=slackrules&saved=1'); exit;
    }

    private static function saveEmailRules(array $ctx): void
    {
        EmailRules::save(self::buildEmailRulesFromRequest($_POST));
        UserActionLog::append('save_email_rules', []);
        header('Location: ?page=emailrules&saved=1'); exit;
    }

    /**
     * Parses the per-tool table submitted by views/emailrules.php (parallel
     * arrays keyed by tool, e.g. mode[scan_addresses]=immediate) into
     * EmailRules::save()'s expected shape. Values aren't validated here -
     * EmailRules::normalise() clamps thresholds, rejects unknown modes, and
     * drops malformed email addresses.
     *
     * @param  array<string, mixed> $post
     * @return array<string, array{mode: string, threshold: int, include_zero: bool, email: string}>
     */
    public static function buildEmailRulesFromRequest(array $post): array
    {
        $modes        = (array) ($post['mode']         ?? []);
        $thresholds   = (array) ($post['threshold']    ?? []);
        $includeZeros = (array) ($post['include_zero'] ?? []);
        $emails       = (array) ($post['email']        ?? []);

        $rules = [];
        foreach (ToolRegistry::triggerCatalog() as $tool => $meta) {
            $rules[$tool] = [
                'mode'         => (string) ($modes[$tool] ?? 'off'),
                'threshold'    => (int) ($thresholds[$tool] ?? 1),
                'include_zero' => isset($includeZeros[$tool]),
                'email'        => trim((string) ($emails[$tool] ?? '')),
            ];
        }
        return $rules;
    }

    private static function saveSidebarSettings(array $ctx): void
    {
        SidebarSettings::save([
            'show_missing_orders'  => isset($_POST['show_missing_orders']),
            'show_recent_activity' => isset($_POST['show_recent_activity']),
        ]);
        UserActionLog::append('save_sidebar_settings', SidebarSettings::load());
        header('Location: ?page=settings&saved=1'); exit;
    }

    private static function previewPush(array $ctx): void
    {
        $shopifyId = trim($_POST['shopify_id'] ?? '');
        header('Content-Type: application/json');

        if (!$shopifyId || !$ctx['shopifyToken']) {
            echo json_encode(['error' => 'Missing credentials or order ID.']); exit;
        }

        try {
            $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
            $ss      = new ShipStation($ctx['ssKey'] ?: 'preview', $ctx['ssSecret'] ?: 'preview');
            $payload = self::buildPushPreview($shopify, $ss, $shopifyId);
            echo json_encode(['payload' => $payload], JSON_PRETTY_PRINT);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Builds the ShipStation payload preview for $shopifyId without
     * actually creating the order. Throws if the Shopify order can't be
     * found.
     */
    public static function buildPushPreview(Shopify $shopify, ShipStation $ss, string $shopifyId): array
    {
        $shopifyOrder = $shopify->getOrder($shopifyId);
        if (empty($shopifyOrder)) {
            throw new RuntimeException("Order {$shopifyId} not found in Shopify.");
        }

        return $ss->buildPayload($shopifyOrder);
    }

    private static function orderDetail(array $ctx): void
    {
        $shopifyId = trim($_POST['shopify_id'] ?? '');
        header('Content-Type: application/json');

        if (!$shopifyId || !$ctx['shopifyToken']) {
            echo json_encode(['error' => 'Missing credentials or order ID.']); exit;
        }

        try {
            $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
            $order   = $shopify->getOrder($shopifyId);
            if (empty($order)) {
                echo json_encode(['error' => "Order {$shopifyId} not found."]); exit;
            }
            echo json_encode(['order' => $order]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    private static function flushCache(array $ctx): void
    {
        $count = $ctx['cacheObj'] ? $ctx['cacheObj']->flush() : 0;
        UserActionLog::append('flush_cache', ['count' => $count, 'store_id' => $ctx['storeId'] ?? '']);

        $loc = self::redirectBack($_GET['page'] ?? 'dashboard');
        $loc = self::appendQuery($loc, [
            'cache_flushed' => $count,
            'start' => $_POST['audit_start'] ?? '',
            'end' => $_POST['audit_end'] ?? '',
        ]);
        header('Location: ' . $loc);
        exit;
    }

    private static function testConnection(array $ctx): void
    {
        self::flash('conn_results', self::connectionResults($ctx));
        header('Location: ?page=settings&connection_test=1');
        exit;
    }

    private static function refreshApiHealth(array $ctx): void
    {
        $apiHealth = [
            'shopify'     => ApiHealth::checkShopify($ctx['shopifyStore'], $ctx['shopifyToken']),
            'shipstation' => ApiHealth::checkShipStation($ctx['ssKey'], $ctx['ssSecret']),
            'checked_at'   => date('Y-m-d H:i:s'),
        ];

        RunLog::append([
            'tool'       => 'api_health',
            'status'     => (($apiHealth['shopify']['ok'] ?? false) && ($apiHealth['shipstation']['ok'] ?? false)) ? 'ok' : 'issues_found',
            'rows_found' => count($apiHealth['shopify']['missing_scopes'] ?? []),
            'meta'       => ['api_version' => Shopify::API_VERSION],
        ]);

        self::flash('api_health', $apiHealth);
        header('Location: ?page=apihealth&api_health=1');
        exit;
    }

    private static function printQueueAdd(): void
    {
        $num = trim($_POST['pq_order_number'] ?? '');
        if ($num === '') {
            self::flash('pq_error', 'Order number cannot be empty.');
            header('Location: ?page=printqueue');
            exit;
        }

        PrintQueue::add($num, trim($_POST['pq_note'] ?? ''));
        UserActionLog::append('pq_add', ['order_number' => $num]);
        self::flash('pq_message', "Order #{$num} added to the print queue.");
        header('Location: ?page=printqueue');
        exit;
    }

    private static function printQueueRemove(): void
    {
        $num = trim($_POST['pq_order_number'] ?? '');
        PrintQueue::remove($num);
        UserActionLog::append('pq_remove', ['order_number' => $num]);
        self::flash('pq_message', "Order #{$num} removed from the queue.");
        header('Location: ?page=printqueue');
        exit;
    }

    private static function printQueueClear(): void
    {
        $count = count(PrintQueue::all());
        PrintQueue::clear();
        UserActionLog::append('pq_clear', ['count' => $count]);
        self::flash('pq_message', 'Print queue cleared.');
        header('Location: ?page=printqueue');
        exit;
    }

    private static function addUser(array $ctx): void
    {
        $username = trim($_POST['new_username'] ?? '');
        $password = $_POST['new_password'] ?? '';
        $role     = $_POST['new_role'] ?? 'viewer';

        $users = Auth::loadUsers();
        if ($err = self::validateNewUser($users, $username, $password, $role)) {
            header('Location: ?page=settings&user_error=' . urlencode($err)); exit;
        }

        $users[] = [
            'name'          => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
        ];
        Auth::saveUsers($users);
        UserActionLog::append('add_user', ['username' => $username, 'role' => $role]);
        header('Location: ?page=settings&user_added=1'); exit;
    }

    /**
     * Validates a new-user submission against the existing user list.
     * Returns an error message, or null when valid.
     *
     * @param  array<int, array{name: string, password_hash: string, role: string}> $existingUsers
     */
    public static function validateNewUser(array $existingUsers, string $username, string $password, string $role): ?string
    {
        if (!in_array($role, ['viewer', 'operator', 'admin'], true)) {
            return 'Invalid role.';
        }
        if ($username === '' || $password === '') {
            return 'Username and password are required.';
        }
        foreach ($existingUsers as $u) {
            if (($u['name'] ?? '') === $username) {
                return 'A user with that username already exists.';
            }
        }
        return null;
    }

    private static function deleteUser(array $ctx): void
    {
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            header('Location: ?page=settings'); exit;
        }

        $users = Auth::loadUsers();
        $users = array_values(array_filter($users, fn($u) => ($u['name'] ?? '') !== $username));
        Auth::saveUsers($users);
        UserActionLog::append('delete_user', ['username' => $username]);
        header('Location: ?page=settings&user_deleted=1'); exit;
    }

    private static function saveOrderNote(array $ctx): void
    {
        $shopifyId = trim($_POST['shopify_id'] ?? '');
        $note      = trim($_POST['note'] ?? '');
        $loc       = self::redirectBack('spotcheck');

        if ($err = self::validateSaveOrderNoteRequest($shopifyId, $ctx)) {
            header('Location: ' . $loc . '&note_error=' . urlencode($err) . '&note_order=' . urlencode($shopifyId));
            exit;
        }

        try {
            $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
            $shopify->updateOrderNote($shopifyId, $note);
            UserActionLog::append('save_order_note', ['shopify_id' => $shopifyId, 'note_length' => strlen($note)]);
            header('Location: ' . $loc . '&note_ok=' . urlencode($shopifyId));
        } catch (Throwable $e) {
            header('Location: ' . $loc . '&note_error=' . urlencode($e->getMessage()) . '&note_order=' . urlencode($shopifyId));
        }
        exit;
    }

    /** Returns an error message, or null when the note request is valid. */
    public static function validateSaveOrderNoteRequest(string $shopifyId, array $ctx): ?string
    {
        if (!$shopifyId) {
            return 'Missing order ID.';
        }
        if (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
            return 'Shopify credentials not configured.';
        }
        return null;
    }

    private static function csvDownload(array $ctx): void
    {
        $date = $_GET['date'] ?? '';
        if (!self::isValidReportDate($date)) {
            http_response_code(400); exit('Invalid date.');
        }
        $path = $ctx['reportDir'] . '/missing_' . $date . '.csv';
        if (!file_exists($path)) {
            http_response_code(404); exit('Report not found.');
        }
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="missing_' . $date . '.csv"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Strictly anchored YYYY-MM-DD check - the value is interpolated
     * directly into a filesystem path (csvDownload's report file lookup),
     * so this also guards against path traversal (`../`, extra segments).
     */
    public static function isValidReportDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function redirectBack(string $defaultPage = 'reports'): string
    {
        $page = $_POST['redirect_page'] ?? $defaultPage;
        $date = $_POST['redirect_date'] ?? '';
        $loc  = '?page=' . urlencode($page);
        if ($date) $loc .= '&date=' . urlencode($date);
        return $loc;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, array{ok: bool, code: int, ms: int, error: ?string}>
     */
    private static function connectionResults(array $ctx): array
    {
        $results = [];
        $ping = function (string $url, array $headers, string $method = 'GET', ?string $body = null): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_USERAGENT      => 'ShopifyOps/1.0',
            ]);
            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $t0   = microtime(true);
            curl_exec($ch);
            $ms   = (int) round((microtime(true) - $t0) * 1000);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'ms' => $ms, 'error' => $err ?: null];
        };

        if ($ctx['ssKey'] && $ctx['ssSecret']) {
            $auth = base64_encode("{$ctx['ssKey']}:{$ctx['ssSecret']}");
            $results['ss'] = $ping(
                'https://ssapi.shipstation.com/orders?pageSize=1',
                ["Authorization: Basic {$auth}", 'Accept: application/json']
            );
        } else {
            $results['ss'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SS_API_KEY / SS_API_SECRET not set in .env'];
        }

        if ($ctx['shopifyToken'] && $ctx['shopifyStore'] !== 'N/A') {
            $host = str_contains($ctx['shopifyStore'], '.') ? $ctx['shopifyStore'] : "{$ctx['shopifyStore']}.myshopify.com";
            $results['shopify'] = $ping(
                "https://{$host}/admin/api/" . Shopify::API_VERSION . "/graphql.json",
                [
                    "X-Shopify-Access-Token: {$ctx['shopifyToken']}",
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                'POST',
                json_encode(['query' => '{ shop { name } }'])
            );
        } else {
            $results['shopify'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env'];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function appendQuery(string $loc, array $params): string
    {
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $loc .= (str_contains($loc, '?') ? '&' : '?') . urlencode((string) $key) . '=' . urlencode((string) $value);
        }
        return $loc;
    }

    private static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

}
