#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Stores.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/IgnoreList.php';
require_once __DIR__ . '/src/RunLog.php';
require_once __DIR__ . '/src/SlackRules.php';
require_once __DIR__ . '/src/SlackNotifier.php';
require_once __DIR__ . '/src/JobQueue.php';
require_once __DIR__ . '/src/ShipStation.php';
require_once __DIR__ . '/src/Shopify.php';
require_once __DIR__ . '/src/Comparator.php';
require_once __DIR__ . '/src/DateRange.php';
require_once __DIR__ . '/src/Reporter.php';
require_once __DIR__ . '/src/Worker.php';

Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();
Stores::init(__DIR__);

$args = $argv ?? [];
$storeId = '';
foreach ($args as $i => $arg) {
    if ($arg === '--store' && isset($args[$i + 1])) {
        $storeId = (string)$args[$i + 1];
    }
}

$config = Worker::resolveStore($storeId);
Worker::configureDataDirs($config['store_id'], __DIR__);

$job = JobQueue::claimNext();
if ($job === null) {
    echo "No pending jobs.\n";
    exit(0);
}

echo "Running job {$job['id']} ({$job['type']})...\n";

try {
    $result = match ($job['type']) {
        'audit' => Worker::runAuditJob($job['payload'] ?? [], $config, __DIR__),
        default => throw new RuntimeException("Unsupported job type: {$job['type']}"),
    };
    JobQueue::complete($job['id'], $result);
    echo "Done.\n";
    exit(0);
} catch (Throwable $e) {
    JobQueue::fail($job['id'], $e->getMessage());
    Logger::getInstance(__DIR__ . '/logs')->error('Worker job failed: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e->getFile() . ':' . $e->getLine(),
    ]);
    echo "Failed: {$e->getMessage()}\n";
    exit(1);
}
