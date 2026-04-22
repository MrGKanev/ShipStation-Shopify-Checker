#!/usr/bin/env php
<?php
// audit.php — ShipStation ↔ Shopify Order Audit
// Usage: php audit.php [--spot-check 164777,164789]

require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/ShipStation.php';
require_once __DIR__ . '/src/Shopify.php';
require_once __DIR__ . '/src/Comparator.php';
require_once __DIR__ . '/src/Reporter.php';

// ── Load .env ─────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die("\n✗ .env file not found. Copy .env.example → .env and fill in your credentials.\n\n");
}
foreach (file($envFile) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    [$key, $val] = array_map('trim', explode('=', $line, 2)) + ['', ''];
    if ($key && !isset($_ENV[$key])) {
        putenv("{$key}={$val}");
    }
}

// ── Validate required config ──────────────────────────────────────
$required = ['SS_API_KEY', 'SS_API_SECRET', 'SHOPIFY_STORE', 'SHOPIFY_ACCESS_TOKEN'];
$missing  = array_filter($required, fn($k) => !getenv($k));
if ($missing) {
    die("\n✗ Missing env variables: " . implode(', ', $missing) . "\n\n");
}

$ssKey        = getenv('SS_API_KEY');
$ssSecret     = getenv('SS_API_SECRET');
$shopifyStore = getenv('SHOPIFY_STORE');
$shopifyToken = getenv('SHOPIFY_ACCESS_TOKEN');

// Default: last 90 days
$startDate = getenv('AUDIT_START_DATE') ?: date('Y-m-d', strtotime('-90 days'));
$endDate   = getenv('AUDIT_END_DATE')   ?: date('Y-m-d');

// ── Parse --spot-check flag ───────────────────────────────────────
// php audit.php --spot-check 164777,164789
$spotCheckNumbers = ['164777', '164789']; // default from the brief
$idx = array_search('--spot-check', $argv ?? []);
if ($idx !== false && isset($argv[$idx + 1])) {
    $spotCheckNumbers = array_filter(array_map('trim', explode(',', $argv[$idx + 1])));
}

// ── Banner ────────────────────────────────────────────────────────
echo "\n🔍 ShipStation ↔ Shopify Order Audit\n";
echo "   Store  : {$shopifyStore}\n";
echo "   Period : {$startDate} → {$endDate}\n";
if ($spotCheckNumbers) {
    echo "   Spot-check : #" . implode(', #', $spotCheckNumbers) . "\n";
}
echo "\n";

try {
    $cacheTtl = (int) (getenv('CACHE_TTL') ?: 14400); // default 4 h
    $cache    = new Cache(__DIR__ . '/cache', $cacheTtl);

    $ss      = new ShipStation($ssKey, $ssSecret, $cache);
    $shopify = new Shopify($shopifyStore, $shopifyToken, $cache);

    // ── Step 1: Fetch orders from both platforms ──────────────────
    $shopifyOrders = $shopify->fetchAllOrders($startDate, $endDate);
    $ssOrders      = $ss->fetchAllOrders($startDate, $endDate);

    echo "\n  ✓ Shopify: " . count($shopifyOrders) . " total orders\n";
    echo "  ✓ ShipStation: " . count($ssOrders) . " total orders\n";

    // ── Step 2: Spot-check specific order numbers ─────────────────
    $spotCheckResults = [];
    if ($spotCheckNumbers) {
        echo "\n  Running spot-check lookups...\n";
        foreach ($spotCheckNumbers as $num) {
            $spotCheckResults[] = [
                'orderNumber' => $num,
                'ssOrders'    => $ss->findByOrderNumber($num),
            ];
        }
    }

    // ── Step 3: Build index + compare ────────────────────────────
    $ssIndex = Comparator::buildSSIndex($ssOrders);
    $result  = Comparator::compare($shopifyOrders, $ssIndex);

    // ── Step 4: Report ────────────────────────────────────────────
    Reporter::printSummary(
        $result['missing'],
        $result['found'],
        $result['skipped'],
        $startDate,
        $endDate,
        $spotCheckResults
    );

    Reporter::saveReports($result['missing'], $startDate, $endDate);

    // Exit code 1 = missing orders found (useful for cron alerting)
    exit(empty($result['missing']) ? 0 : 1);

} catch (Throwable $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    if (getenv('DEBUG')) echo $e->getTraceAsString() . "\n";
    exit(2);
}