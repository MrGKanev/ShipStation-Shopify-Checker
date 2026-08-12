#!/usr/bin/env php
<?php
// audit.php - ShipStation ↔ Shopify Order Audit
// Usage: php audit.php [--spot-check 100042,100043]

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/ShipStation.php';
require_once __DIR__ . '/src/Shopify.php';
require_once __DIR__ . '/src/Comparator.php';
require_once __DIR__ . '/src/DateRange.php';
require_once __DIR__ . '/src/Reporter.php';
require_once __DIR__ . '/src/SlackNotifier.php';
require_once __DIR__ . '/src/SlackRules.php';
require_once __DIR__ . '/src/EmailNotifier.php';
require_once __DIR__ . '/src/EmailRules.php';
require_once __DIR__ . '/src/DiscordNotifier.php';
require_once __DIR__ . '/src/RunLog.php';
require_once __DIR__ . '/src/Audit.php';

// ── Load .env ─────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/.env')) {
    die("\n✗ .env file not found. Copy .env.example → .env and fill in your credentials.\n\n");
}
Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->load();

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
// php audit.php --spot-check 100042,100043
$spotCheckNumbers = [];
$idx = array_search('--spot-check', $argv ?? []);
if ($idx !== false && isset($argv[$idx + 1])) {
    $spotCheckNumbers = array_filter(array_map('trim', explode(',', $argv[$idx + 1])));
}

// ── Banner ────────────────────────────────────────────────────────
$sendDigest = (bool) getenv('SEND_DIGEST');
echo "\n🔍 ShipStation ↔ Shopify Order Audit\n";
echo "   Store  : {$shopifyStore}\n";
echo "   Period : {$startDate} → {$endDate}\n";
if ($sendDigest) {
    echo "   Mode   : digest (SEND_DIGEST=1 — notifications sent regardless of thresholds)\n";
}
if ($spotCheckNumbers) {
    echo "   Spot-check : #" . implode(', #', $spotCheckNumbers) . "\n";
}
echo "\n";

$auditStartedAt = date('Y-m-d H:i:s');
$auditT0 = microtime(true);

try {
    Audit::withErrorLogging(function () use (
        $ssKey, $ssSecret, $shopifyStore, $shopifyToken, $startDate, $endDate,
        $spotCheckNumbers, $sendDigest, $auditStartedAt, $auditT0
    ) {
    $cacheTtl = (int) (getenv('CACHE_TTL') ?: 82800); // default 23 h
    $cache    = new Cache(__DIR__ . '/cache', $cacheTtl);

    $ss      = new ShipStation($ssKey, $ssSecret, $cache);
    $shopify = new Shopify($shopifyStore, $shopifyToken, $cache);

    // ── Step 1: Fetch orders from both platforms ──────────────────
    // SS end date is extended by 7 days to catch sub-orders
    // that are created in ShipStation a few days after the Shopify order.
    $ssEndDate     = DateRange::addDays($endDate, 7);
    $shopifyOrders = $shopify->fetchAllOrders($startDate, $endDate);
    $ssOrders      = $ss->fetchAllOrders($startDate, $ssEndDate);

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

    // ── Step 3: Load ignored orders ──────────────────────────────
    $ignoredFile    = __DIR__ . '/data/ignored.json';
    $ignoredNumbers = file_exists($ignoredFile)
        ? (json_decode(file_get_contents($ignoredFile), true) ?: [])
        : [];

    // ── Step 4: Build index + compare ────────────────────────────
    $ssIndex      = Comparator::buildSSIndex($ssOrders);
    $ssEmailIndex = Comparator::buildSSEmailIndex($ssOrders);
    $result       = Comparator::compare($shopifyOrders, $ssIndex, $ignoredNumbers, $ssEmailIndex);

    // ── Step 4b: On-hold check ────────────────────────────────────
    // 'on_hold' is not exposed on the order object itself - it lives on
    // the Fulfillment Order level and requires a separate API call per order.
    // We only check the (small) set of orders already flagged as missing to
    // keep the total number of extra requests low (typically 5-10/day).
    // Results are cached per order ID so historical re-runs are cheap.
    if (!empty($result['missing'])) {
        echo "\n  Checking on-hold status for " . count($result['missing']) . " missing order(s)...";
        $result = Comparator::applyOnHoldSkip($result, function (array $order) use ($shopify) {
            $onHold = $shopify->isOnHold((string) $order['id']);
            echo $onHold ? 'H' : '.'; // visual indicator
            return $onHold;
        });
        echo " done\n";
    }

    // ── Step 4c: Classify order types ─────────────────────────────
    foreach ($result['missing'] as &$order) {
        $order['_order_type'] = Comparator::classifyOrder($order);
    }
    unset($order);

    // ── Step 5: Report ────────────────────────────────────────────
    Reporter::printSummary(
        $result['missing'],
        $result['found'],
        $result['skipped'],
        $startDate,
        $endDate,
        $spotCheckResults,
        $result['ignored']
    );

    Reporter::saveReports($result['missing'], $startDate, $endDate);

    $auditSummary = [
        'store'          => $shopifyStore,
        'start'          => $startDate,
        'end'            => $endDate,
        'missing_count'  => count($result['missing']),
        'missing_orders' => $result['missing'],
        'found'          => count($result['found']),
        'skipped'        => count($result['skipped']),
        'ignored'        => count($result['ignored']),
        'total_ss'       => count($ssOrders),
    ];

    if (($sendDigest || SlackRules::shouldNotifyAudit(count($result['missing']))) && ($notifier = SlackNotifier::fromEnvironment())) {
        $sent = $notifier->notifyAuditSafely($auditSummary);
        echo $sent
            ? "  Slack " . ($sendDigest ? 'digest' : 'notification') . " sent.\n"
            : "  Slack notification failed; audit result was still saved.\n";
    }

    if (($sendDigest || EmailRules::shouldNotify('run_audit', count($result['missing']))) && ($emailNotifier = EmailNotifier::fromEnvironment())) {
        $sent = $emailNotifier->notifyAuditSafely($auditSummary, null, EmailRules::recipientFor('run_audit'));
        echo $sent
            ? "  Email " . ($sendDigest ? 'digest' : 'notification') . " sent.\n"
            : "  Email notification failed; audit result was still saved.\n";
    }

    if ($discordNotifier = DiscordNotifier::fromEnvironment()) {
        $sent = $discordNotifier->notifyAuditSafely($auditSummary);
        echo $sent
            ? "  Discord " . ($sendDigest ? 'digest' : 'notification') . " sent.\n"
            : "  Discord notification failed; audit result was still saved.\n";
    }

    RunLog::append([
        'tool'       => 'cli_audit',
        'status'     => count($result['missing']) > 0 ? 'issues_found' : 'ok',
        'created_at' => $auditStartedAt,
        'duration'   => round(microtime(true) - $auditT0, 2),
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'scanned'    => count($shopifyOrders),
        'rows_found' => count($result['missing']),
        'meta'       => [
            'api_version' => Shopify::API_VERSION,
            'shipstation_total' => count($ssOrders),
            'found' => count($result['found']),
            'skipped' => count($result['skipped']),
            'ignored' => count($result['ignored']),
        ],
    ]);

    // Exit code 1 = missing orders found (useful for cron alerting)
    exit(empty($result['missing']) ? 0 : 1);

    }, 'cli_audit', $startDate, $endDate);
} catch (Throwable $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    if (getenv('DEBUG')) echo $e->getTraceAsString() . "\n";
    exit(2);
}
