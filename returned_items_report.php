#!/usr/bin/env php
<?php
// returned_items_report.php - itemized quantity report for refunded line items
// Usage: php returned_items_report.php [--start YYYY-MM-DD --end YYYY-MM-DD] [--email]
// Defaults to last calendar month. --email requires SMTP_HOST + ALERT_EMAIL in .env.

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/Shopify.php';
require_once __DIR__ . '/src/EmailNotifier.php';
require_once __DIR__ . '/src/ReturnedItemsReport.php';

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

$orders = $shopify->fetchOrdersRefundedSince($startDate);
$totals = ReturnedItemsReport::aggregate($orders, $startDate, $endDate);

ReturnedItemsReport::printSummary($totals, $startDate, $endDate);
$csvPath = ReturnedItemsReport::saveCsv($totals, $startDate, $endDate);
echo "Saved: {$csvPath}\n\n";

if (in_array('--email', $argv, true)) {
    $notifier = EmailNotifier::fromEnvironment();
    if (!$notifier) {
        die("✗ --email requires SMTP_HOST and ALERT_EMAIL to be set in .env.\n\n");
    }
    $notifier->sendReport(
        "Returned Items Report ({$startDate} \u{2192} {$endDate})",
        ReturnedItemsReport::emailHtml($totals, $startDate, $endDate),
        "returned_items_{$startDate}_to_{$endDate}.csv",
        ReturnedItemsReport::toCsvString($totals)
    );
    echo "Emailed to " . getenv('ALERT_EMAIL') . "\n\n";
}
