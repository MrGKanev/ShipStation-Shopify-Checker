<?php
declare(strict_types=1);

/**
 * Single source of truth for tool pages, labels, groups, and hub cards.
 */
class ToolRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $toolMapCache = null;

    /** @var array<string, array<string, mixed>> */
    private const HUBS = [
        'audit' => [
            'label' => 'Audit',
            'href'  => '?page=hub-audit',
            'hub'   => 'hub-audit',
            'sections' => [
                'Core Audit' => [
                    ['page' => 'reports',  'icon' => '📋', 'name' => 'Reports',    'desc' => 'View and download saved audit reports'],
                    ['page' => 'run',      'icon' => '▶',  'name' => 'Run Audit',  'desc' => 'Compare Shopify vs ShipStation for any date range'],
                    ['page' => 'trends',   'icon' => '📈', 'name' => 'Trends',     'desc' => 'Aggregated stats across all audit reports'],
                ],
                'Order Issues' => [
                    ['page' => 'dupes',         'icon' => '🔁', 'name' => 'Duplicate Detector',          'desc' => 'Same customer, same total - placed within 10 minutes'],
                    ['page' => 'refunds',       'icon' => '💸', 'name' => 'Refunds Tracker',             'desc' => 'Refunded Shopify orders cross-checked against ShipStation'],
                    ['page' => 'repeatrefunds', 'icon' => '♻',  'name' => 'Repeat Refunds',              'desc' => 'Customers with multiple refunded orders in a date range'],
                    ['page' => 'returns',       'icon' => '↩',  'name' => 'Return / RMA Tracker',        'desc' => 'Refunded orders with item-level return details and per-SKU return rate summary'],
                    ['page' => 'returneditems', 'icon' => '📦', 'name' => 'Returned Items Report',       'desc' => 'Itemized quantity totals for refunded line items in a date range'],
                    ['page' => 'orphans',       'icon' => '👻', 'name' => 'Orphan Detector',             'desc' => 'ShipStation orders with no matching Shopify order'],
                    ['page' => 'activess',      'icon' => '🛑', 'name' => 'Active SS Conflicts',         'desc' => 'Refunded or cancelled Shopify orders still active in ShipStation'],
                    ['page' => 'ssshipped',     'icon' => '🔄', 'name' => 'SS Shipped / Shopify Unful.', 'title' => 'SS Shipped / Shopify Unfulfilled', 'desc' => 'ShipStation shipped orders that Shopify still shows as unfulfilled (sync failure)'],
                    ['page' => 'orderedits',    'icon' => '✏️',  'name' => 'Order Edit History',          'desc' => 'Orders with post-placement edits: line items, discounts, notes or custom attributes'],
                    ['page' => 'noteflags',     'icon' => '🚩', 'name' => 'Note Flags',                  'desc' => 'Paid unfulfilled orders with flagged keywords in the order note'],
                ],
                'Address & Contact' => [
                    ['page' => 'addrcheck',    'icon' => '📍', 'name' => 'Address Scanner',           'desc' => 'Paid orders with incomplete or invalid shipping addresses'],
                    ['page' => 'emailcheck',   'icon' => '✉',  'name' => 'Email Checker',             'desc' => 'Orders with invalid, disposable or suspicious emails'],
                    ['page' => 'hvorders',     'icon' => '📦', 'name' => 'High-Value No Phone',       'desc' => 'High-value unfulfilled orders missing a shipping phone'],
                    ['page' => 'addrchanges',  'icon' => '🔀', 'name' => 'Address Changes',           'desc' => 'Orders whose shipping address was edited after placement'],
                    ['page' => 'postshipaddr', 'icon' => '📮', 'name' => 'Post-Ship Address Change',  'desc' => 'Address edited AFTER the order was already fulfilled - package already in transit'],
                    ['page' => 'addrdupes',    'icon' => '👥', 'name' => 'Duplicate Shipping Addrs.', 'title' => 'Duplicate Shipping Addresses', 'desc' => 'Different customer emails shipping to the exact same address'],
                ],
                'Fulfillment' => [
                    ['page' => 'failedship',     'icon' => '🚫', 'name' => 'Voided Shipments',           'desc' => 'ShipStation shipments voided in the selected date range'],
                    ['page' => 'slabreaches',    'icon' => '⏱',  'name' => 'Fulfillment SLA Breaches',  'desc' => 'Orders exceeding your time-to-first-fulfillment SLA by shipping method and region'],
                    ['page' => 'bundlecheck',    'icon' => '🧩', 'name' => 'Bundle Check',               'desc' => 'Bundled orders missing required companion items (Addon items)'],
                    ['page' => 'partialfulfill', 'icon' => '⏳', 'name' => 'Partial Fulfillment Stalls', 'desc' => 'Open orders partially shipped with unfulfilled items stalled for N+ days'],
                    ['page' => 'onholdstall',    'icon' => '⏸',  'name' => 'On-Hold Stall',              'desc' => 'Fulfillment orders sitting on hold - sorted by how long the order has been waiting'],
                    ['page' => 'notracking',     'icon' => '📪', 'name' => 'Fulfilled Without Tracking', 'desc' => 'Fulfilled orders with no tracking number after a configurable grace period'],
                    ['page' => 'shipmentaging',  'icon' => '🕒', 'name' => 'Shipment Aging',             'desc' => 'ShipStation awaiting-shipment orders older than a configurable threshold'],
                    ['page' => 'itemmismatch',   'icon' => '📦', 'name' => 'Shipped Item Mismatch',      'desc' => 'ShipStation shipped items that don\'t match what was ordered in Shopify - catches picking errors, especially missing accessories on bundled products'],
                    ['page' => 'fulfilleditems', 'icon' => '🧾', 'name' => 'Fulfilled Items Report',    'desc' => 'Itemized quantity totals for orders fulfilled in a date range'],
                ],
                'Carrier Analytics' => [
                    ['page' => 'carrierperf', 'icon' => '🚚', 'name' => 'Carrier Performance', 'desc' => 'Avg delivery time, late rate, and order count grouped by carrier for a date range'],
                    ['page' => 'shipmargin', 'icon' => '💸', 'name' => 'Shipping Margin Erosion', 'desc' => 'Orders where the ShipStation label cost exceeds what the customer was charged for shipping — flags orders shipped at a loss'],
                ],
                'Products & Inventory' => [
                    ['page' => 'productcheck',      'icon' => '🖼', 'name' => 'Product Completeness',   'desc' => 'Active products missing images, descriptions, or variant SKUs'],
                    ['page' => 'skudupes',          'icon' => '🔑', 'name' => 'SKU Duplicates',          'desc' => 'Variants sharing the same SKU across your product catalog'],
                    ['page' => 'inventoryoversell', 'icon' => '📉', 'name' => 'Inventory Oversell Risk', 'desc' => 'SKUs where ShipStation awaiting qty exceeds available Shopify stock'],
                    ['page' => 'inventoryaging',    'icon' => '📦', 'name' => 'Inventory Aging',         'desc' => 'Zero-stock active variants that still sold recently'],
                    ['page' => 'inventoryforecast', 'icon' => '🔮', 'name' => 'Inventory Forecast',      'desc' => 'Days until zero stock based on 30-day sell-through rate per SKU'],
                    ['page' => 'zombieproducts',    'icon' => '🧟', 'name' => 'Zombie Products',         'desc' => 'Active products with no variants or all tracked variants permanently out of stock'],
                    ['page' => 'catalogquality',    'icon' => '🔍', 'name' => 'Catalog Quality',         'desc' => 'Active products not published to Online Store, missing SEO fields, or not in any collection'],
                ],
                'Gift Cards' => [
                    ['page' => 'giftcards', 'icon' => '🎁', 'name' => 'Gift Cards', 'desc' => 'Unused or soon-to-expire gift card balances'],
                ],
                'Fraud & Compliance' => [
                    ['page' => 'countrymismatch', 'icon' => '🌍', 'name' => 'Billing ≠ Shipping Country', 'desc' => 'Paid orders where billing and shipping countries differ - a documented fraud signal'],
                    ['page' => 'discountabuse',   'icon' => '🎟', 'name' => 'Discount Abuse',             'desc' => 'Discount code clusters at the same shipping address across different emails'],
                    ['page' => 'tagpolicy',       'icon' => '🏷', 'name' => 'Tag Policy Audit',           'desc' => 'Required and forbidden Shopify tag combinations from local policy rules'],
                    ['page' => 'taxaudit',        'icon' => '🧾', 'name' => 'Tax Audit',                  'desc' => 'Paid orders above a minimum amount with $0 tax charged to a non-exempt customer'],
                    ['page' => 'consentaudit',    'icon' => '📢', 'name' => 'Marketing Consent Audit',    'desc' => 'Orders from customers without active email marketing consent - a compliance risk if targeted'],
                    ['page' => 'riskreport',      'icon' => '🚨', 'name' => 'Fraud Risk Report',          'desc' => 'Paid orders scored by combined fraud signals - disposable email, country mismatch, HIGH risk level, and more'],
                    ['page' => 'sameip',          'icon' => '🖥',  'name' => 'Same IP, Different Emails',  'desc' => 'Client IP addresses used by two or more distinct customer emails - a fraud ring signal'],
                    ['page' => 'disputes',        'icon' => '⚖',  'name' => 'Chargebacks / Disputes',     'desc' => 'Open Shopify Payments disputes needing evidence, sorted by response deadline'],
                ],
            ],
        ],
        'search' => [
            'label' => 'Search &amp; Lookup',
            'href'  => '?page=hub-search',
            'hub'   => 'hub-search',
            'sections' => [
                'Orders' => [
                    ['page' => 'spotcheck', 'icon' => '🔎', 'name' => 'Spot-check',    'desc' => 'Live lookup of specific order numbers in ShipStation and Shopify'],
                    ['page' => 'compare',   'icon' => '⚖',  'name' => 'Order Compare', 'desc' => 'Two orders side by side with differences highlighted'],
                    ['page' => 'timeline',  'icon' => '📅', 'name' => 'Order Timeline', 'desc' => 'Full chronological history of a single order: Shopify events + ShipStation shipments'],
                ],
                'Customers & Tags' => [
                    ['page' => 'customer',  'icon' => '👤', 'name' => 'Customer Lookup',   'desc' => 'Full order history for a customer by email address'],
                    ['page' => 'cohort',    'icon' => '📊', 'name' => 'Customer LTV',       'desc' => 'Top customers by lifetime value and monthly cohort retention'],
                    ['page' => 'tagsearch', 'icon' => '🔖', 'name' => 'Tag Search',         'desc' => 'Find all orders that carry a specific Shopify tag'],
                    ['page' => 'tagaudit',  'icon' => '🏷',  'name' => 'Tag Audit',          'desc' => 'All unique tags on orders - with frequency and last-seen date'],
                ],
                'Metadata' => [
                    ['page' => 'metafields', 'icon' => '🗂', 'name' => 'Metafields', 'desc' => 'Browse metafield definitions and search orders by value'],
                ],
                'Shipping' => [
                    ['page' => 'tracking',    'icon' => '🚚', 'name' => 'Tracking Feed',        'desc' => 'Shipment tracking info for orders via ShipStation'],
                    ['page' => 'packingslip', 'icon' => '🖨',  'name' => 'Packing Slip Preview', 'desc' => 'Visualise a ShipStation packing slip for any order - without logging in'],
                ],
            ],
        ],
    ];

    /** @var array<string, array{group: string, title: string}> */
    private const STANDALONE = [
        'dashboard'     => ['group' => 'audit',    'title' => 'Dashboard'],
        'hub-audit'     => ['group' => 'audit',    'title' => 'Audit'],
        'hub-search'    => ['group' => 'search',   'title' => 'Search & Lookup'],
        'globalsearch'  => ['group' => 'search',   'title' => 'Global Search'],
        'ignored'       => ['group' => 'manage',   'title' => 'Ignored Orders'],
        'pushlog'       => ['group' => 'manage',   'title' => 'Push Log'],
        'runlog'        => ['group' => 'manage',   'title' => 'Run History'],
        'jobs'          => ['group' => 'manage',   'title' => 'Job Queue'],
        'actionlog'     => ['group' => 'manage',   'title' => 'Action Log'],
        'printqueue'    => ['group' => 'manage',   'title' => 'Print Queue'],
        'settings'      => ['group' => 'settings', 'title' => 'Settings'],
        'slackrules'    => ['group' => 'settings', 'title' => 'Slack Rules'],
        'emailrules'    => ['group' => 'settings', 'title' => 'Email Rules'],
        'apihealth'     => ['group' => 'settings', 'title' => 'API Health'],
        'configcheck'   => ['group' => 'settings', 'title' => 'Config Check'],
        'webhookhealth' => ['group' => 'settings', 'title' => 'Webhook Health'],
    ];

    /**
     * Canonical list of RunLog `tool` keys for every audit/scan flow, with a
     * human label, the page that triggers it, a grouping area, and its API
     * dependency. Single source of truth shared by the Shopify Flow Health
     * dashboard (ManageSettingsPageLoader) and the per-tool Email Rules
     * (EmailRules) so both always agree on which tools exist.
     *
     * @var array<string, array{label: string, page: string, area: string, dependency: string}>
     */
    private const TRIGGER_CATALOG = [
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
        'scan_catalogquality'   => ['label' => 'Catalog quality', 'page' => 'catalogquality', 'area' => 'Inventory', 'dependency' => 'Shopify'],
        'scan_giftcards'        => ['label' => 'Gift card expiry / unused balance', 'page' => 'giftcards', 'area' => 'Inventory', 'dependency' => 'Shopify'],
        'scan_taxaudit'         => ['label' => 'Zero-tax paid orders', 'page' => 'taxaudit', 'area' => 'Risk', 'dependency' => 'Shopify'],
        'scan_consentaudit'     => ['label' => 'Marketing consent audit', 'page' => 'consentaudit', 'area' => 'Risk', 'dependency' => 'Shopify'],
        'scan_riskreport'       => ['label' => 'Fraud risk report', 'page' => 'riskreport', 'area' => 'Risk', 'dependency' => 'Shopify'],
        'scan_sameip'           => ['label' => 'Same IP, different emails', 'page' => 'sameip', 'area' => 'Risk', 'dependency' => 'Shopify'],
        'scan_disputes'         => ['label' => 'Chargeback / dispute tracker', 'page' => 'disputes', 'area' => 'Risk', 'dependency' => 'Shopify'],
    ];

    /**
     * @return array<string, array{label: string, page: string, area: string, dependency: string}>
     */
    public static function triggerCatalog(): array
    {
        return self::TRIGGER_CATALOG;
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    public static function hubSections(string $group): array
    {
        return self::HUBS[$group]['sections'] ?? [];
    }

    /**
     * @return array<string, array{label: string, href: string}>
     */
    public static function groupMeta(): array
    {
        return [
            'audit'    => ['label' => self::HUBS['audit']['label'],  'href' => self::HUBS['audit']['href']],
            'search'   => ['label' => self::HUBS['search']['label'], 'href' => self::HUBS['search']['href']],
            'manage'   => ['label' => 'Manage',   'href' => '?page=ignored'],
            'settings' => ['label' => 'Settings', 'href' => '?page=settings'],
        ];
    }

    /**
     * @return string[]
     */
    public static function allowedPages(): array
    {
        return array_values(array_unique(array_merge(array_keys(self::STANDALONE), array_keys(self::toolMap()))));
    }

    public static function normalizePage(string $page, string $fallback = 'hub-audit'): string
    {
        return in_array($page, self::allowedPages(), true) ? $page : $fallback;
    }

    public static function groupOf(string $page): string
    {
        if (isset(self::STANDALONE[$page])) {
            return self::STANDALONE[$page]['group'];
        }
        return self::toolMap()[$page]['group'] ?? 'settings';
    }

    public static function title(string $page): string
    {
        if (isset(self::STANDALONE[$page])) {
            return self::STANDALONE[$page]['title'];
        }
        $tool = self::toolMap()[$page] ?? null;
        return $tool ? (string) ($tool['title'] ?? $tool['name']) : $page;
    }

    /**
     * @return array<string, string>
     */
    public static function titles(): array
    {
        $titles = [];
        foreach (self::allowedPages() as $page) {
            $titles[$page] = self::title($page);
        }
        return $titles;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function toolMap(): array
    {
        if (self::$toolMapCache !== null) {
            return self::$toolMapCache;
        }
        $tools = [];
        foreach (self::HUBS as $group => $hub) {
            foreach (($hub['sections'] ?? []) as $sectionTools) {
                foreach ($sectionTools as $tool) {
                    $tool['group'] = $group;
                    $tools[(string) $tool['page']] = $tool;
                }
            }
        }
        return self::$toolMapCache = $tools;
    }
}
