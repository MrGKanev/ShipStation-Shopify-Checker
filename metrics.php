<?php
declare(strict_types=1);

// metrics.php — Prometheus text-format metrics endpoint
// Protect with METRICS_TOKEN env var (Bearer token or ?token= query param).

require_once __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();

// ── Token auth ─────────────────────────────────────────────────────────────
$configuredToken = trim((string) getenv('METRICS_TOKEN'));
$allowPublic = filter_var(getenv('METRICS_ALLOW_PUBLIC') ?: '', FILTER_VALIDATE_BOOLEAN);

if ($configuredToken === '' && !$allowPublic) {
    http_response_code(503);
    header('Content-Type: text/plain');
    echo "Metrics endpoint disabled. Set METRICS_TOKEN or METRICS_ALLOW_PUBLIC=1.\n";
    exit;
}

if ($configuredToken !== '') {
    $provided = '';
    $authHeader = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($authHeader, 'Bearer ') === 0) {
        $provided = substr($authHeader, 7);
    } elseif (isset($_GET['token'])) {
        $provided = trim((string) $_GET['token']);
    }
    if (!hash_equals($configuredToken, $provided)) {
        http_response_code(401);
        header('Content-Type: text/plain');
        echo "Unauthorized\n";
        exit;
    }
}

// ── Store label ────────────────────────────────────────────────────────────
$storeId      = trim((string) (getenv('SHOPIFY_STORE') ?: 'default'));
$storeEscaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $storeId);
$l            = '{store="' . $storeEscaped . '"}';

// ── Missing orders (most recent audit run) ─────────────────────────────────
$missingTotal = 0;
$runLogFile   = __DIR__ . '/data/run_log.json';
if (file_exists($runLogFile)) {
    $runEntries = json_decode(file_get_contents($runLogFile), true) ?: [];
    // Entries are stored oldest-first; iterate in reverse for most recent
    foreach (array_reverse($runEntries) as $entry) {
        $tool = (string) ($entry['tool'] ?? '');
        if (in_array($tool, ['cli_audit', 'run_audit'], true) && isset($entry['rows_found'])) {
            $missingTotal = (int) $entry['rows_found'];
            break;
        }
    }
}

// ── Audit reports (CSV files in reports/) ─────────────────────────────────
$reportsDir        = __DIR__ . '/reports';
$reportFiles       = is_dir($reportsDir) ? (glob($reportsDir . '/missing_*.csv', GLOB_NOSORT) ?: []) : [];
$auditReportsTotal = count($reportFiles);

// ── Ignored orders ─────────────────────────────────────────────────────────
$ignoredFile  = __DIR__ . '/data/ignored.json';
$ignoredTotal = 0;
if (file_exists($ignoredFile)) {
    $ignoredData  = json_decode(file_get_contents($ignoredFile), true) ?: [];
    $ignoredTotal = count($ignoredData);
}

// ── Push log total ─────────────────────────────────────────────────────────
$pushLogFile  = __DIR__ . '/data/push_log.json';
$pushTotal    = 0;
if (file_exists($pushLogFile)) {
    $pushData  = json_decode(file_get_contents($pushLogFile), true) ?: [];
    $pushTotal = count($pushData);
}

// ── Pending jobs ───────────────────────────────────────────────────────────
$jobsFile    = __DIR__ . '/data/jobs.json';
$pendingJobs = 0;
if (file_exists($jobsFile)) {
    $jobs = json_decode(file_get_contents($jobsFile), true) ?: [];
    foreach ($jobs as $job) {
        if (($job['status'] ?? '') === 'pending') {
            $pendingJobs++;
        }
    }
}

// ── Output ─────────────────────────────────────────────────────────────────
header('Content-Type: text/plain; version=0.0.4');

echo "# HELP shopify_ops_missing_orders_total Total missing orders in most recent audit report\n";
echo "# TYPE shopify_ops_missing_orders_total gauge\n";
echo "shopify_ops_missing_orders_total{$l} {$missingTotal}\n";
echo "\n";
echo "# HELP shopify_ops_audit_reports_total Total number of saved audit reports\n";
echo "# TYPE shopify_ops_audit_reports_total gauge\n";
echo "shopify_ops_audit_reports_total{$l} {$auditReportsTotal}\n";
echo "\n";
echo "# HELP shopify_ops_ignored_orders_total Total ignored orders\n";
echo "# TYPE shopify_ops_ignored_orders_total gauge\n";
echo "shopify_ops_ignored_orders_total{$l} {$ignoredTotal}\n";
echo "\n";
echo "# HELP shopify_ops_push_log_total Total orders pushed to ShipStation\n";
echo "# TYPE shopify_ops_push_log_total gauge\n";
echo "shopify_ops_push_log_total{$l} {$pushTotal}\n";
echo "\n";
echo "# HELP shopify_ops_job_queue_pending Pending background jobs\n";
echo "# TYPE shopify_ops_job_queue_pending gauge\n";
echo "shopify_ops_job_queue_pending{$l} {$pendingJobs}\n";
