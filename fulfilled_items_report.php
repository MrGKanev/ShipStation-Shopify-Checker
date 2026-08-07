#!/usr/bin/env php
<?php
// fulfilled_items_report.php - itemized quantity report for fulfilled orders
// Usage: php fulfilled_items_report.php [--start YYYY-MM-DD --end YYYY-MM-DD] [--email]
// Defaults to last calendar month. --email requires SMTP_HOST + ALERT_EMAIL in .env.

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/Shopify.php';
require_once __DIR__ . '/src/EmailNotifier.php';
require_once __DIR__ . '/src/ItemizedFulfillmentReport.php';

if (!file_exists(__DIR__ . '/.env')) {
    die("\n✗ .env file not found. Copy .env.example → .env and fill in your credentials.\n\n");
}
Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->load();

$required = ['SHOPIFY_STORE', 'SHOPIFY_ACCESS_TOKEN'];
$missing  = array_filter($required, fn($k) => !getenv($k));
if ($missing) {
    die("\n✗ Missing env variables: " . implode(', ', $missing) . "\n\n");
}

function argValue(array $argv, string $flag): ?string
{
    $idx = array_search($flag, $argv);
    return ($idx !== false && isset($argv[$idx + 1])) ? $argv[$idx + 1] : null;
}

$startDate = argValue($argv, '--start') ?: date('Y-m-01', strtotime('first day of last month'));
$endDate   = argValue($argv, '--end')   ?: date('Y-m-t', strtotime('last day of last month'));

$cache   = new Cache(__DIR__ . '/cache', (int)(getenv('CACHE_TTL') ?: 82800));
$shopify = new Shopify(getenv('SHOPIFY_STORE'), getenv('SHOPIFY_ACCESS_TOKEN'), $cache);

$orders = $shopify->fetchOrdersFulfilledSince($startDate);
$totals = ItemizedFulfillmentReport::aggregate($orders, $startDate, $endDate);

ItemizedFulfillmentReport::printSummary($totals, $startDate, $endDate);
$csvPath = ItemizedFulfillmentReport::saveCsv($totals, $startDate, $endDate);
echo "Saved: {$csvPath}\n\n";

if (in_array('--email', $argv, true)) {
    $notifier = EmailNotifier::fromEnvironment();
    if (!$notifier) {
        die("✗ --email requires SMTP_HOST and ALERT_EMAIL to be set in .env.\n\n");
    }
    $notifier->sendReport(
        "Fulfilled Items Report ({$startDate} \u{2192} {$endDate})",
        ItemizedFulfillmentReport::emailHtml($totals, $startDate, $endDate),
        "fulfilled_items_{$startDate}_to_{$endDate}.csv",
        ItemizedFulfillmentReport::toCsvString($totals)
    );
    echo "Emailed to " . getenv('ALERT_EMAIL') . "\n\n";
}
