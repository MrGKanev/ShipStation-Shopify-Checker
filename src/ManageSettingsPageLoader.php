<?php
declare(strict_types=1);

/**
 * Loads low-risk manage/settings pages that do not share audit/search helpers.
 */
class ManageSettingsPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'jobs'          => self::loadJobs(),
            'slackrules'    => self::loadSlackRules(),
            'emailrules'    => self::loadEmailRules(),
            'apihealth'     => self::loadApiHealth($ctx),
            'configcheck'   => self::loadConfigCheck(),
            'actionlog'     => self::loadActionLog(),
            'settings'      => self::loadSettings($ctx),
            'webhookhealth' => self::loadWebhookHealth($ctx),
            'printqueue'    => self::loadPrintQueue($ctx),
            default         => [],
        };
    }

    private static function loadJobs(): array
    {
        $jobs = JobQueue::all();
        return compact('jobs');
    }

    private static function loadSlackRules(): array
    {
        $slackRules = SlackRules::load();
        $slackConfigured = SlackNotifier::isConfigured();
        return compact('slackRules', 'slackConfigured');
    }

    private static function loadApiHealth(array $ctx): array
    {
        $apiHealth = $ctx['flash']['api_health'] ?? null;
        $shopifyFlowHealth = self::shopifyFlowHealth(RunLog::all());

        return compact('apiHealth', 'shopifyFlowHealth');
    }

    /**
     * @param array<int, array<string, mixed>> $runLog newest first
     * @return array{summary: array<string, int>, flows: list<array<string, mixed>>}
     */
    private static function shopifyFlowHealth(array $runLog): array
    {
        $flows = ToolRegistry::triggerCatalog();
        $indexed = [];

        foreach ($runLog as $entry) {
            $tool = (string)($entry['tool'] ?? '');
            if (!isset($flows[$tool])) {
                continue;
            }

            $status = (string)($entry['status'] ?? '');
            $hasFailure = in_array($status, ['error', 'validation_error', 'config_error'], true)
                || trim((string)($entry['error'] ?? '')) !== '';

            if (!isset($indexed[$tool]['latest'])) {
                $indexed[$tool]['latest'] = $entry;
            }
            if ($hasFailure && !isset($indexed[$tool]['last_error'])) {
                $indexed[$tool]['last_error'] = $entry;
            }

            $indexed[$tool]['runs'] = ($indexed[$tool]['runs'] ?? 0) + 1;
            if ($hasFailure) {
                $indexed[$tool]['errors'] = ($indexed[$tool]['errors'] ?? 0) + 1;
            }
        }

        $rows = [];
        $summary = ['total' => count($flows), 'never_run' => 0, 'healthy' => 0, 'attention' => 0];

        foreach ($flows as $tool => $flow) {
            $latest = $indexed[$tool]['latest'] ?? null;
            $lastError = $indexed[$tool]['last_error'] ?? null;
            $status = $latest['status'] ?? 'never_run';
            $needsAttention = in_array($status, ['error', 'validation_error', 'config_error'], true)
                || ($lastError !== null && $status === 'never_run');

            if ($status === 'never_run') {
                $summary['never_run']++;
            } elseif ($needsAttention) {
                $summary['attention']++;
            } else {
                $summary['healthy']++;
            }

            $rows[] = $flow + [
                'tool'          => $tool,
                'status'        => $status,
                'latest'        => $latest,
                'last_error'    => $lastError,
                'runs'          => $indexed[$tool]['runs'] ?? 0,
                'errors'        => $indexed[$tool]['errors'] ?? 0,
                'last_run_at'   => $latest['created_at'] ?? '',
                'last_error_at' => $lastError['created_at'] ?? '',
                'error_message' => $lastError['error'] ?? '',
            ];
        }

        return ['summary' => $summary, 'flows' => $rows];
    }

    private static function loadEmailRules(): array
    {
        $emailRules = EmailRules::load();
        $emailConfigured = EmailNotifier::isConfigured();
        $emailCatalog = ToolRegistry::triggerCatalog();
        return compact('emailRules', 'emailConfigured', 'emailCatalog');
    }

    private static function loadConfigCheck(): array
    {
        $configResults = ConfigValidator::validateAll(dirname(__DIR__));
        return compact('configResults');
    }

    private static function loadActionLog(): array
    {
        $actionLog = UserActionLog::all();
        return compact('actionLog');
    }

    private static function loadSettings(array $ctx): array
    {
        $connResults     = $ctx['flash']['conn_results'] ?? null;
        $cacheEntries    = $ctx['cacheObj']->entries();
        $cacheFlushed    = max(0, (int) ($_GET['cache_flushed'] ?? 0));
        $cacheTtl        = $ctx['cacheTtl'];
        $sidebarSettings = SidebarSettings::load();

        return compact('connResults', 'cacheEntries', 'cacheFlushed', 'cacheTtl', 'sidebarSettings');
    }

    private static function loadWebhookHealth(array $ctx): array
    {
        $whWebhooks = [];
        $whError    = '';

        if (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
            $whError = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
        } else {
            try {
                $shopify    = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $result     = $shopify->fetchWebhooks($ctx['shopifyRequest'] ?? null);
                $whWebhooks = $result['webhooks'];
                $whError    = $result['error'];
            } catch (Throwable $e) {
                $whError = $e->getMessage();
            }
        }

        return compact('whWebhooks', 'whError');
    }

    private static function loadPrintQueue(array $ctx): array
    {
        $pqMessage = (string) ($ctx['flash']['pq_message'] ?? '');
        $pqError   = (string) ($ctx['flash']['pq_error'] ?? '');

        $pqItems = PrintQueue::all();
        return compact('pqItems', 'pqMessage', 'pqError');
    }
}
