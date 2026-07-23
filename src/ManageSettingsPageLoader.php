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
        $flows = self::shopifyFlowCatalog();
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

    /**
     * @return array<string, array{label: string, page: string, area: string, dependency: string}>
     */
    private static function shopifyFlowCatalog(): array
    {
        return [
            'run_audit'             => ['label' => 'Main missing-order audit', 'page' => 'run', 'area' => 'Audit', 'dependency' => 'Shopify + ShipStation'],
            'tag_audit'             => ['label' => 'Tag audit', 'page' => 'tagaudit', 'area' => 'Audit', 'dependency' => 'Shopify'],
            'scan_bundle'           => ['label' => 'Bundle / required item check', 'page' => 'bundlecheck', 'area' => 'Audit', 'dependency' => 'Shopify'],
            'scan_addresses'        => ['label' => 'Address validation', 'page' => 'addrcheck', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_emails'           => ['label' => 'Email validation', 'page' => 'emailcheck', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_hvorders'         => ['label' => 'High-value missing phone', 'page' => 'hvorders', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'find_refunds'          => ['label' => 'Refunded orders vs ShipStation', 'page' => 'refunds', 'area' => 'Risk', 'dependency' => 'Shopify + ShipStation'],
            'scan_repeat_refunds'   => ['label' => 'Repeat refunds', 'page' => 'repeatrefunds', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'find_dupes'            => ['label' => 'Duplicate orders', 'page' => 'dupes', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'find_orphans'          => ['label' => 'ShipStation orphan orders', 'page' => 'orphans', 'area' => 'Risk', 'dependency' => 'Shopify + ShipStation'],
            'scan_addr_changes'     => ['label' => 'Address changes after order', 'page' => 'addrchanges', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_order_edits'      => ['label' => 'Order edits', 'page' => 'orderedits', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_noteflags'        => ['label' => 'Order note flags', 'page' => 'noteflags', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_addrdupes'        => ['label' => 'Shared address / email conflicts', 'page' => 'addrdupes', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_discountabuse'    => ['label' => 'Discount abuse', 'page' => 'discountabuse', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_tagpolicy'        => ['label' => 'Tag policy', 'page' => 'tagpolicy', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_country_mismatch' => ['label' => 'Billing / shipping country mismatch', 'page' => 'countrymismatch', 'area' => 'Risk', 'dependency' => 'Shopify'],
            'scan_partial_fulfill'  => ['label' => 'Partial fulfillment stall', 'page' => 'partialfulfill', 'area' => 'Fulfillment', 'dependency' => 'Shopify'],
            'scan_onhold'           => ['label' => 'On-hold fulfillment stall', 'page' => 'onholdstall', 'area' => 'Fulfillment', 'dependency' => 'Shopify'],
            'scan_notracking'       => ['label' => 'Fulfilled without tracking', 'page' => 'notracking', 'area' => 'Fulfillment', 'dependency' => 'Shopify'],
            'scan_postshipaddr'     => ['label' => 'Post-shipment address changes', 'page' => 'postshipaddr', 'area' => 'Fulfillment', 'dependency' => 'Shopify'],
            'scan_ssshipped'        => ['label' => 'ShipStation shipped, Shopify unfulfilled', 'page' => 'ssshipped', 'area' => 'Fulfillment', 'dependency' => 'Shopify + ShipStation'],
            'scan_sla'              => ['label' => 'SLA breaches', 'page' => 'slabreaches', 'area' => 'Fulfillment', 'dependency' => 'Shopify'],
            'scan_activess'         => ['label' => 'Refunded/cancelled but active in ShipStation', 'page' => 'activess', 'area' => 'Fulfillment', 'dependency' => 'Shopify + ShipStation'],
            'scan_products'         => ['label' => 'Product content check', 'page' => 'productcheck', 'area' => 'Inventory', 'dependency' => 'Shopify'],
            'scan_skudupes'         => ['label' => 'Duplicate SKU check', 'page' => 'skudupes', 'area' => 'Inventory', 'dependency' => 'Shopify'],
            'scan_inventory'        => ['label' => 'Inventory oversell', 'page' => 'inventoryoversell', 'area' => 'Inventory', 'dependency' => 'Shopify + ShipStation'],
            'scan_zombieproducts'   => ['label' => 'Zombie products', 'page' => 'zombieproducts', 'area' => 'Inventory', 'dependency' => 'Shopify'],
            'scan_inventoryaging'   => ['label' => 'Inventory aging', 'page' => 'inventoryaging', 'area' => 'Inventory', 'dependency' => 'Shopify'],
        ];
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
        $connResults  = $ctx['flash']['conn_results'] ?? null;
        $cacheEntries = $ctx['cacheObj']->entries();
        $cacheFlushed = max(0, (int) ($_GET['cache_flushed'] ?? 0));
        $cacheTtl     = $ctx['cacheTtl'];

        return compact('connResults', 'cacheEntries', 'cacheFlushed', 'cacheTtl');
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
                $result     = $shopify->fetchWebhooks();
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
