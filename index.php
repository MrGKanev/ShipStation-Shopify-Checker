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
    if (hash_equals($webPassword, $_POST['password'] ?? '')) {
        $_SESSION['authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $error = 'Incorrect password.';
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
$cacheTtl    = (int) (getenv('CACHE_TTL') ?: 14400);

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
$auditStart     = $_POST['audit_start'] ?? date('Y-m-d', strtotime('-30 days'));
$auditEnd       = $_POST['audit_end']   ?? date('Y-m-d');
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

if ($authed && is_dir($reportDir)) {
    $files = glob($reportDir . '/missing_*.csv') ?: [];
    rsort($files);

    foreach ($files as $csvPath) {
        preg_match('/missing_(\d{4}-\d{2}-\d{2})\.csv$/', $csvPath, $m);
        $date    = $m[1] ?? 'unknown';
        $missing = [];

        if (($fh = fopen($csvPath, 'r')) !== false) {
            $headers = fgetcsv($fh, 0, ',', '"', '\\');
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                $missing[] = array_combine($headers ?: [], $row);
            }
            fclose($fh);
        }

        $missing = array_values(array_filter(
            $missing,
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
    string $contextVal2 = ''
): string {
    $count   = count($missing);
    $tableId = 'tbl-' . substr(md5($context . $contextVal), 0, 6);
    ob_start();
    ?>
    <div class="search-wrap">
      <input class="js-search" data-target="<?= esc($tableId) ?>"
             placeholder="Filter by order #, email, status…" type="search">
    </div>
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
              <th>Order #</th>
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
            ?>
            <tr>
              <td>
                <?php if ($adminUrl): ?>
                  <a class="order-num" href="<?= $adminUrl ?>" target="_blank" rel="noopener">#<?= esc($num) ?></a>
                <?php else: ?>
                  <span class="order-num">#<?= esc($num) ?></span>
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
