#!/usr/bin/env php
<?php
// email_digest.php - sends one rollup email per recipient for every check
// set to "digest" mode in Email Rules (see the "emailrules" settings page),
// if that check had a qualifying run today.
// Usage: php email_digest.php [--date YYYY-MM-DD]
// Schedule via cron once a day, e.g.: 0 9 * * * php email_digest.php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/ToolRegistry.php';
require_once __DIR__ . '/src/EmailRules.php';
require_once __DIR__ . '/src/EmailDigest.php';
require_once __DIR__ . '/src/EmailNotifier.php';
require_once __DIR__ . '/src/RunLog.php';
require_once __DIR__ . '/src/Logger.php';

if (!file_exists(__DIR__ . '/.env')) {
    die("\n✗ .env file not found. Copy .env.example → .env and fill in your credentials.\n\n");
}
Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->load();

function argValue(array $argv, string $flag): ?string
{
    $idx = array_search($flag, $argv);
    return ($idx !== false && isset($argv[$idx + 1])) ? $argv[$idx + 1] : null;
}

$today = argValue($argv, '--date') ?: date('Y-m-d');

$notifier = EmailNotifier::fromEnvironment();
if (!$notifier) {
    echo "Email digest skipped: SMTP_HOST / ALERT_EMAIL not set in .env.\n\n";
    exit(0);
}

$sections = EmailDigest::buildSections(EmailRules::load(), RunLog::all(), $today);

if (empty($sections)) {
    echo "Email digest: no digest-mode checks had qualifying issues today ({$today}).\n\n";
    exit(0);
}

$logger    = Logger::getInstance(__DIR__ . '/logs');
$defaultTo = trim((string) getenv('ALERT_EMAIL'));
$sentCount = 0;

foreach ($sections as $recipient => $toolSections) {
    $to = $recipient !== '' ? $recipient : $defaultTo;
    $ok = $notifier->notifyDigestSafely($toolSections, $logger, $recipient);

    RunLog::append([
        'tool'       => 'email_digest',
        'status'     => $ok ? 'ok' : 'error',
        'rows_found' => count($toolSections),
        'meta'       => ['recipient' => $to, 'checks' => array_column($toolSections, 'tool')],
    ]);

    $count = count($toolSections);
    if ($ok) {
        $sentCount++;
        echo "Digest emailed to {$to} ({$count} check" . ($count === 1 ? '' : 's') . ").\n";
    } else {
        echo "✗ Failed to email digest to {$to} - see logs/.\n";
    }
}

echo "\nDone: {$sentCount} of " . count($sections) . " digest email(s) sent.\n\n";
