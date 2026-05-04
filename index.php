<?php
declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2)) + ['', ''];
        if ($k && !isset($_ENV[$k])) putenv("{$k}={$v}");
    }
}

$webUsername  = getenv('WEB_USERNAME') ?: 'admin';
$webPassword  = getenv('WEB_PASSWORD') ?: 'changeme';
$shopifyStore = getenv('SHOPIFY_STORE') ?: 'N/A';
$appTitle     = getenv('APP_TITLE') ?: 'SS ↔ Shopify Audit';
$appBrand     = getenv('APP_BRAND') ?: 'SS ↔ Shopify';
$reportDir    = __DIR__ . '/reports';

// ── Session / auth ────────────────────────────────────────────────────────────

session_start();

$error  = '';
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    $attemptsFile = __DIR__ . '/data/login_attempts.json';
    $ip           = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockWindow   = 900;  // 15 minutes
    $maxAttempts  = 5;

    $attempts = [];
    if (file_exists($attemptsFile)) {
        $attempts = json_decode(file_get_contents($attemptsFile), true) ?: [];
    }

    // Prune expired entries
    $now = time();
    $attempts = array_filter($attempts, fn($e) => ($e['until'] ?? 0) > $now || ($e['first'] ?? 0) > $now - $lockWindow);

    $entry     = $attempts[$ip] ?? ['count' => 0, 'first' => $now, 'until' => 0];
    $lockedOut = ($entry['until'] ?? 0) > $now;

    if ($lockedOut) {
        $mins  = (int) ceil(($entry['until'] - $now) / 60);
        $error = "Too many failed attempts. Try again in {$mins} minute" . ($mins !== 1 ? 's' : '') . '.';
    } else {
        $okUser = hash_equals($webUsername, $_POST['username'] ?? '');
        $okPass = hash_equals($webPassword, $_POST['password'] ?? '');
        if ($okUser && $okPass) {
            unset($attempts[$ip]);
            if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
            file_put_contents($attemptsFile, json_encode($attempts));
            $_SESSION['authed'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $entry['count'] = ($entry['count'] ?? 0) + 1;
        if (!isset($entry['first'])) $entry['first'] = $now;
        if ($entry['count'] >= $maxAttempts) {
            $entry['until'] = $now + $lockWindow;
        }
        $attempts[$ip] = $entry;
        if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
        file_put_contents($attemptsFile, json_encode($attempts));

        $remaining = $maxAttempts - $entry['count'];
        $error = $remaining > 0
            ? 'Incorrect username or password. ' . $remaining . ' attempt' . ($remaining !== 1 ? 's' : '') . ' remaining.'
            : 'Too many failed attempts. Try again in 15 minutes.';
    }
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$authed = !empty($_SESSION['authed']);

// ── Shared setup ──────────────────────────────────────────────────────────────

$dataDir     = __DIR__ . '/data';
$ignoredFile = $dataDir . '/ignored.json';
$cacheDir    = __DIR__ . '/cache';
$cacheTtl    = (int) (getenv('CACHE_TTL') ?: 82800); // 23 hours

$ignoredOrders = [];
$cacheObj      = null;

if ($authed) {
    require_once __DIR__ . '/src/Cache.php';
    require_once __DIR__ . '/src/Comparator.php';

    $cacheObj = new Cache($cacheDir, $cacheTtl);

    if (file_exists($ignoredFile)) {
        $ignoredOrders = json_decode(file_get_contents($ignoredFile), true) ?: [];
    }
}

// ── CSV download ──────────────────────────────────────────────────────────────

if ($authed && ($_GET['action'] ?? '') === 'download') {
    $date = $_GET['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400); exit('Invalid date.');
    }
    $path = $reportDir . '/missing_' . $date . '.csv';
    if (!file_exists($path)) {
        http_response_code(404); exit('Report not found.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="missing_' . $date . '.csv"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Ignore / unignore ─────────────────────────────────────────────────────────

if ($authed && $action === 'ignore_order') {
    $num    = Comparator::normalise($_POST['order_number'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    if ($num) {
        if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
        $ignoredOrders[$num] = ['reason' => $reason, 'ignored_at' => date('Y-m-d')];
        file_put_contents($ignoredFile, json_encode($ignoredOrders, JSON_PRETTY_PRINT));
    }
    $redirectPage = $_POST['redirect_page'] ?? 'reports';
    $redirectDate = $_POST['redirect_date'] ?? '';
    $loc = '?page=' . urlencode($redirectPage);
    if ($redirectDate) $loc .= '&date=' . urlencode($redirectDate);
    header('Location: ' . $loc);
    exit;
}

if ($authed && $action === 'unignore_order') {
    $num = Comparator::normalise($_POST['order_number'] ?? '');
    if ($num && isset($ignoredOrders[$num])) {
        unset($ignoredOrders[$num]);
        file_put_contents($ignoredFile, json_encode($ignoredOrders, JSON_PRETTY_PRINT));
    }
    $redirectPage = $_POST['redirect_page'] ?? 'reports';
    $redirectDate = $_POST['redirect_date'] ?? '';
    $loc = '?page=' . urlencode($redirectPage);
    if ($redirectDate) $loc .= '&date=' . urlencode($redirectDate);
    header('Location: ' . $loc);
    exit;
}

// ── Bulk unignore ─────────────────────────────────────────────────────────────

if ($authed && $action === 'bulk_unignore_orders') {
    $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
    foreach ($numbers as $raw) {
        $norm = Comparator::normalise($raw);
        if ($norm) unset($ignoredOrders[$norm]);
    }
    if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
    file_put_contents($ignoredFile, json_encode($ignoredOrders, JSON_PRETTY_PRINT));
    header('Location: ?page=ignored');
    exit;
}

// ── Bulk ignore ───────────────────────────────────────────────────────────────

if ($authed && $action === 'bulk_ignore_orders') {
    $numbers = array_filter((array) ($_POST['order_numbers'] ?? []));
    $reason  = trim($_POST['reason'] ?? '');
    foreach ($numbers as $raw) {
        $norm = Comparator::normalise($raw);
        if ($norm) {
            $ignoredOrders[$norm] = ['reason' => $reason, 'ignored_at' => date('Y-m-d')];
        }
    }
    if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
    file_put_contents($ignoredFile, json_encode($ignoredOrders, JSON_PRETTY_PRINT));
    $redirectPage = $_POST['redirect_page'] ?? 'reports';
    $redirectDate = $_POST['redirect_date'] ?? '';
    $loc = '?page=' . urlencode($redirectPage);
    if ($redirectDate) $loc .= '&date=' . urlencode($redirectDate);
    header('Location: ' . $loc);
    exit;
}

// ── CSV import bulk ignore ────────────────────────────────────────────────────

if ($authed && $action === 'import_ignore_csv') {
    $file   = $_FILES['ignore_csv'] ?? null;
    $reason = trim($_POST['import_reason'] ?? '') ?: 'CSV import ' . date('Y-m-d');
    $count  = 0;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        if (($fh = fopen($file['tmp_name'], 'r')) !== false) {
            $first = fgetcsv($fh);
            if ($first) {
                $firstCell = ltrim(trim((string) ($first[0] ?? '')), '#');
                if (preg_match('/^\d+$/', $firstCell)) {
                    $norm = Comparator::normalise($firstCell);
                    if ($norm) { $ignoredOrders[$norm] = ['reason' => $reason, 'ignored_at' => date('Y-m-d')]; $count++; }
                }
            }
            while (($row = fgetcsv($fh)) !== false) {
                $norm = Comparator::normalise(ltrim(trim((string) ($row[0] ?? '')), '#'));
                if ($norm) { $ignoredOrders[$norm] = ['reason' => $reason, 'ignored_at' => date('Y-m-d')]; $count++; }
            }
            fclose($fh);
        }
        if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
        file_put_contents($ignoredFile, json_encode($ignoredOrders, JSON_PRETTY_PRINT));
    }
    header('Location: ?page=ignored&imported=' . $count);
    exit;
}

// ── Connection test ───────────────────────────────────────────────────────────

$connResults = null;

if ($authed && $action === 'test_connection') {
    $ssKey        = getenv('SS_API_KEY');
    $ssSecret     = getenv('SS_API_SECRET');
    $shopifyToken = getenv('SHOPIFY_ACCESS_TOKEN');

    $ping = function (string $url, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'SS-Shopify-Audit/1.0',
        ]);
        $t0   = microtime(true);
        curl_exec($ch);
        $ms   = (int) round((microtime(true) - $t0) * 1000);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'ms' => $ms, 'error' => $err ?: null];
    };

    if ($ssKey && $ssSecret) {
        $auth = base64_encode("{$ssKey}:{$ssSecret}");
        $connResults['ss'] = $ping(
            'https://ssapi.shipstation.com/orders?pageSize=1',
            ["Authorization: Basic {$auth}", 'Accept: application/json']
        );
    } else {
        $connResults['ss'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SS_API_KEY / SS_API_SECRET not set in .env'];
    }

    if ($shopifyToken && $shopifyStore !== 'N/A') {
        $host = str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com";
        $connResults['shopify'] = $ping(
            "https://{$host}/admin/api/2024-01/shop.json",
            ["X-Shopify-Access-Token: {$shopifyToken}", 'Accept: application/json']
        );
    } else {
        $connResults['shopify'] = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env'];
    }
}

// ── Cache flush ───────────────────────────────────────────────────────────────

$cacheEntries = [];
$cacheFlushed = 0;

if ($authed) {
    if ($action === 'flush_cache') {
        $cacheFlushed = $cacheObj->flush();
    }
    $cacheEntries = $cacheObj->entries();
}

// ── Spot-check ────────────────────────────────────────────────────────────────

$spotResults = null;
$spotInput   = '';
$spotError   = '';

if ($authed && $action === 'spotcheck') {
    $spotInput = trim($_POST['orders'] ?? '');
    $numbers   = array_filter(array_map('trim', preg_split('/[\s,]+/', $spotInput)));

    if (empty($numbers)) {
        $spotError = 'Enter at least one order number.';
    } elseif (count($numbers) > 50) {
        $spotError = 'Maximum 50 order numbers at once.';
    } else {
        require_once __DIR__ . '/src/ShipStation.php';
        $ssKey    = getenv('SS_API_KEY');
        $ssSecret = getenv('SS_API_SECRET');

        if (!$ssKey || !$ssSecret) {
            $spotError = 'SS_API_KEY / SS_API_SECRET not set in .env.';
        } else {
            try {
                $ss          = new ShipStation($ssKey, $ssSecret);
                $spotResults = [];
                foreach ($numbers as $num) {
                    $clean  = ltrim(trim($num), '#');
                    $orders = $ss->findByOrderNumber($clean);
                    $spotResults[] = [
                        'input'  => $num,
                        'number' => $clean,
                        'orders' => $orders,
                        'found'  => !empty($orders),
                    ];
                }
            } catch (Throwable $e) {
                $spotError = 'ShipStation error: ' . $e->getMessage();
            }
        }
    }
}

// ── On-demand audit ───────────────────────────────────────────────────────────

$auditResult    = null;
$auditStart     = $_POST['audit_start'] ?? $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$auditEnd       = $_POST['audit_end']   ?? $_GET['end']   ?? date('Y-m-d');
$auditError     = '';
$auditDuration  = 0;
$auditFromCache = ['shopify' => false, 'ss' => false];

if ($authed && $action === 'run_audit') {
    $auditStart = $_POST['audit_start'] ?? '';
    $auditEnd   = $_POST['audit_end']   ?? '';

    $validStart = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditStart);
    $validEnd   = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditEnd);

    if (!$validStart || !$validEnd) {
        $auditError = 'Invalid date format. Use YYYY-MM-DD.';
    } elseif ($auditStart > $auditEnd) {
        $auditError = 'Start date must be before end date.';
    } elseif ((strtotime($auditEnd) - strtotime($auditStart)) > 366 * 86400) {
        $auditError = 'Date range cannot exceed 366 days.';
    } else {
        $ssKey        = getenv('SS_API_KEY');
        $ssSecret     = getenv('SS_API_SECRET');
        $shopifyToken = getenv('SHOPIFY_ACCESS_TOKEN');

        if (!$ssKey || !$ssSecret || !$shopifyToken) {
            $auditError = 'API credentials missing in .env.';
        } else {
            require_once __DIR__ . '/src/ShipStation.php';
            require_once __DIR__ . '/src/Shopify.php';
            require_once __DIR__ . '/src/Reporter.php';

            try {
                set_time_limit(300);
                $t0 = microtime(true);

                $auditFromCache = [
                    'shopify' => $cacheObj->isFresh('shopify', "{$auditStart}|{$auditEnd}"),
                    'ss'      => $cacheObj->isFresh('ss',      "{$auditStart}|{$auditEnd}"),
                ];

                $ss      = new ShipStation($ssKey, $ssSecret, $cacheObj);
                $shopify = new Shopify($shopifyStore, $shopifyToken, $cacheObj);

                ob_start();
                $shopifyOrders = $shopify->fetchAllOrders($auditStart, $auditEnd);
                $ssOrders      = $ss->fetchAllOrders($auditStart, $auditEnd);
                ob_end_clean();

                $ssIndex    = Comparator::buildSSIndex($ssOrders);
                $comparison = Comparator::compare($shopifyOrders, $ssIndex, $ignoredOrders);

                Reporter::saveReports($comparison['missing'], $auditStart, $auditEnd);

                $auditDuration = round(microtime(true) - $t0, 1);
                $auditResult   = [
                    'missing'  => $comparison['missing'],
                    'ignored'  => $comparison['ignored'],
                    'found'    => count($comparison['found']),
                    'skipped'  => count($comparison['skipped']),
                    'total_ss' => count($ssOrders),
                ];

                $cacheEntries = $cacheObj->entries();
            } catch (Throwable $e) {
                $auditError = $e->getMessage();
            }
        }
    }
}

// ── Load report data ──────────────────────────────────────────────────────────

$reports      = [];
$latestReport = null;
$orderHistory = []; // [norm_num => ['count' => N, 'first' => date, 'last' => date]]

if ($authed && is_dir($reportDir)) {
    $files = glob($reportDir . '/missing_*.csv') ?: [];
    rsort($files);

    foreach ($files as $csvPath) {
        preg_match('/missing_(\d{4}-\d{2}-\d{2})\.csv$/', $csvPath, $m);
        $date    = $m[1] ?? 'unknown';
        $rawRows = [];

        if (($fh = fopen($csvPath, 'r')) !== false) {
            $headers = fgetcsv($fh, 0, ',', '"', '\\');
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                $rawRows[] = array_combine($headers ?: [], $row);
            }
            fclose($fh);
        }

        foreach ($rawRows as $row) {
            $num = Comparator::normalise((string) ($row['order_number'] ?? ''));
            if (!$num) continue;
            if (!isset($orderHistory[$num])) {
                $orderHistory[$num] = ['count' => 0, 'first' => $date, 'last' => $date];
            }
            $orderHistory[$num]['count']++;
            if ($date < $orderHistory[$num]['first']) $orderHistory[$num]['first'] = $date;
            if ($date > $orderHistory[$num]['last'])  $orderHistory[$num]['last']  = $date;
        }

        $missing = array_values(array_filter(
            $rawRows,
            fn($o) => !isset($ignoredOrders[Comparator::normalise((string)($o['order_number'] ?? ''))])
        ));

        $reports[] = ['date' => $date, 'csvPath' => $csvPath, 'missing' => $missing, 'count' => count($missing)];
    }

    $latestReport = $reports[0] ?? null;
}

$selectedDate   = $_GET['date'] ?? ($latestReport['date'] ?? null);
$selectedReport = null;
foreach ($reports as $r) {
    if ($r['date'] === $selectedDate) { $selectedReport = $r; break; }
}

$shopifyAdminBase = 'https://'
    . (str_contains($shopifyStore, '.') ? $shopifyStore : "{$shopifyStore}.myshopify.com")
    . '/admin/orders';

// ── View helpers ──────────────────────────────────────────────────────────────

function esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function badge(int $count): string
{
    return $count === 0
        ? '<span class="badge badge-ok">All clear</span>'
        : '<span class="badge badge-warn">' . $count . ' missing</span>';
}

function renderMissingTable(
    array  $missing,
    array  $ignoredOrders,
    string $shopifyAdminBase,
    string $context,
    string $contextVal,
    string $contextVal2 = '',
    array  $orderHistory = []
): string {
    $count   = count($missing);
    $tableId = 'tbl-' . substr(md5($context . $contextVal), 0, 6);
    $formId  = 'bulk-' . substr(md5($context . $contextVal), 0, 6);
    ob_start();
    ?>
    <div class="search-wrap">
      <input class="js-search" data-target="<?= esc($tableId) ?>"
             placeholder="Filter by order #, email, status…" type="search">
    </div>

    <?php if ($count > 0): ?>
    <form id="<?= esc($formId) ?>" method="post" class="bulk-form">
      <input type="hidden" name="action" value="bulk_ignore_orders">
      <input type="hidden" name="redirect_page" value="<?= esc($context) ?>">
      <input type="hidden" name="redirect_date" value="<?= esc($contextVal) ?>">
      <div class="bulk-bar" id="bar-<?= esc($formId) ?>">
        <span class="bulk-count" id="cnt-<?= esc($formId) ?>">0 selected</span>
        <input type="text" name="reason" placeholder="Reason (optional)" class="bulk-reason">
        <button class="btn btn-sm btn-danger" type="submit">Ignore selected</button>
        <button class="btn btn-sm btn-ghost" type="button"
                onclick="document.querySelectorAll('#<?= esc($tableId) ?> .js-row-check').forEach(c=>c.checked=false);updateBulkBar('<?= esc($formId) ?>')">
          Clear
        </button>
      </div>
    <?php endif; ?>

    <div class="table-wrap">
      <div class="table-header">
        <h2>Missing Orders</h2>
        <span><?= $count ?> order<?= $count !== 1 ? 's' : '' ?></span>
      </div>

      <?php if ($count === 0): ?>
        <div class="empty">
          <div class="icon">✅</div>
          <h3>All clear!</h3>
          <p>Every paid Shopify order was found in ShipStation.</p>
        </div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th style="width:32px">
                <input type="checkbox" class="js-select-all" data-target="<?= esc($tableId) ?>"
                       data-bar="<?= esc($formId) ?>" title="Select all">
              </th>
              <th>Order #</th>
              <th>Seen</th>
              <th>Created</th>
              <th>Total</th>
              <th>Financial</th>
              <th>Fulfillment</th>
              <th>Email</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="<?= esc($tableId) ?>">
            <?php foreach ($missing as $row):
              $num       = (string) ($row['order_number'] ?? $row['name'] ?? '?');
              $shopifyId = $row['id'] ?? $row['shopify_id'] ?? '';
              $financial = strtolower($row['financial_status'] ?? '');
              $chipClass = match($financial) {
                'paid'           => 'chip-paid',
                'partially_paid' => 'chip-partial',
                'unpaid'         => 'chip-unpaid',
                default          => 'chip-unknown',
              };
              $totalPrice = isset($row['total_price']) && $row['total_price'] !== ''
                ? '$' . number_format((float) $row['total_price'], 2)
                : '—';
              $adminUrl    = $shopifyId ? $shopifyAdminBase . '/' . esc($shopifyId) : null;
              $normNum     = preg_replace('/\D/', '', ltrim(trim($num), '#'));
              $ssSearchUrl = 'https://app.shipstation.com/#!/orders/all-orders-search-result?quickSearch='
                           . urlencode(ltrim($num, '#'));
              $seenCount   = $orderHistory[$normNum]['count'] ?? 1;
              $isRepeat    = $seenCount >= 2;
            ?>
            <tr class="<?= $isRepeat ? 'row-repeat' : '' ?>">
              <td>
                <input type="checkbox" class="js-row-check" name="order_numbers[]"
                       value="<?= esc($num) ?>" data-bar="<?= esc($formId) ?>"
                       onchange="updateBulkBar('<?= esc($formId) ?>')">
              </td>
              <td>
                <?php if ($adminUrl): ?>
                  <a class="order-num" href="<?= $adminUrl ?>" target="_blank" rel="noopener">#<?= esc($num) ?></a>
                <?php else: ?>
                  <span class="order-num">#<?= esc($num) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($seenCount >= 3): ?>
                  <span class="seen-badge seen-hot" title="Appeared in <?= $seenCount ?> reports"><?= $seenCount ?>×</span>
                <?php elseif ($seenCount === 2): ?>
                  <span class="seen-badge seen-warn" title="Appeared in 2 reports">2×</span>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:.78rem">1×</span>
                <?php endif; ?>
              </td>
              <td><?= esc(substr($row['created_at'] ?? '', 0, 10)) ?></td>
              <td style="font-variant-numeric:tabular-nums"><?= $totalPrice ?></td>
              <td><span class="chip <?= $chipClass ?>"><?= esc($row['financial_status'] ?? '—') ?></span></td>
              <td><?= esc($row['fulfillment_status'] ?? '—') ?></td>
              <td style="color:var(--muted)"><?= esc($row['email'] ?? '—') ?></td>
              <td style="white-space:nowrap">
                <a class="ignore-btn" href="<?= esc($ssSearchUrl) ?>" target="_blank" rel="noopener"
                   style="margin-right:.3rem;text-decoration:none">Search SS</a>
                <button class="ignore-btn js-ignore-toggle" data-order="<?= esc($normNum) ?>">Ignore</button>
                <div id="ignore-form-<?= esc($normNum) ?>" class="ignore-form-row" style="display:none">
                  <form method="post" style="display:contents">
                    <input type="hidden" name="action" value="ignore_order">
                    <input type="hidden" name="order_number" value="<?= esc($num) ?>">
                    <input type="hidden" name="redirect_page" value="<?= esc($context) ?>">
                    <input type="hidden" name="redirect_date" value="<?= esc($contextVal) ?>">
                    <input type="text" name="reason" placeholder="Reason (optional)" style="width:150px">
                    <button class="btn btn-sm btn-danger" type="submit">Confirm</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <?php if ($count > 0): ?>
    </form>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

// ── Render ────────────────────────────────────────────────────────────────────

if (!$authed) {
    require __DIR__ . '/views/login.php';
} else {
    $page = $_GET['page'] ?? 'reports';
    require __DIR__ . '/views/layout.php';
}
