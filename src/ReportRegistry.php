<?php
declare(strict_types=1);

/**
 * Static metadata (label, icon, page, date-range param prefix) for every audit
 * that runs through ScanRunner::run(). Drives the grouped-by-instrument sidebar
 * history and the "reopen this saved run" links.
 */
final class ReportRegistry
{
    /**
     * tool (ScanRunner $trigger) => [label, icon, page slug, DateRange param prefix]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const array TOOLS = [
        'scan_fulfilleditems'  => ['Fulfilled Items',          '✅', 'fulfilleditems',  'fi'],
        'scan_returneditems'   => ['Returned Items',           '↩️', 'returneditems',   'ri'],
        'scan_notracking'      => ['No Tracking',              '📦', 'notracking',      'nt'],
        'scan_itemmismatch'    => ['Item Mismatch',            '⚠️', 'itemmismatch',    'im'],
        'scan_shipmargin'      => ['Shipping Margin',          '💸', 'shipmargin',      'sm'],
        'scan_carrierperf'     => ['Carrier Performance',      '🚛', 'carrierperf',     'cp'],
        'scan_sla'             => ['SLA Breaches',             '⏱️', 'slabreaches',     'sla'],
        'scan_onhold'          => ['On-Hold Stall',            '⏸️', 'onholdstall',     'oh'],
        'scan_postshipaddr'    => ['Post-Ship Address Change', '📮', 'postshipaddr',    'ps'],
        'scan_ssshipped'       => ['SS Shipped, Unfulfilled',  '🚚', 'ssshipped',       'ssu'],
        'scan_returns'         => ['Returns',                  '🔄', 'returns',         'rt'],
        'scan_addresses'       => ['Address Check',            '📍', 'addrcheck',       'addr'],
        'scan_addr_changes'    => ['Address Changes',          '✏️', 'addrchanges',     'ac'],
        'scan_addrdupes'       => ['Address Duplicates',       '👥', 'addrdupes',       'ad'],
        'find_refunds'         => ['Refunds',                  '💰', 'refunds',         'refunds'],
        'scan_repeat_refunds'  => ['Repeat Refunds',           '🔁', 'repeatrefunds',   'rr'],
        'find_orphans'         => ['Orphan Orders',            '👻', 'orphans',         'orphan'],
        'scan_noteflags'       => ['Note Flags',                '🚩', 'noteflags',       'nf'],
        'scan_activess'        => ['Active in ShipStation',    '🟢', 'activess',        'as'],
        'scan_discountabuse'   => ['Discount Abuse',           '🎟️', 'discountabuse',   'da'],
        'scan_tagpolicy'       => ['Tag Policy',                '🏷️', 'tagpolicy',       'tp'],
        'scan_bundle'          => ['Bundle Check',              '🧩', 'bundlecheck',     'bc'],
        'scan_inventoryaging'  => ['Inventory Aging',           '📉', 'inventoryaging',  'ia'],
        'tag_audit'            => ['Tag Audit',                 '🔖', 'tagaudit',        'ta'],
        'scan_emails'          => ['Email Check',               '✉️', 'emailcheck',      'email'],
        'scan_hvorders'        => ['High-Value Orders',         '💎', 'hvorders',        'hv'],
        'scan_country_mismatch'=> ['Country Mismatch',          '🌍', 'countrymismatch', 'cm'],
        'scan_partial_fulfill' => ['Partial Fulfillment',       '◐',  'partialfulfill',  'pf'],
    ];

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    public static function get(string $tool): ?array
    {
        return self::TOOLS[$tool] ?? null;
    }

    public static function label(string $tool): string
    {
        return self::TOOLS[$tool][0] ?? $tool;
    }

    public static function icon(string $tool): string
    {
        return self::TOOLS[$tool][1] ?? '🧾';
    }

    public static function page(string $tool): ?string
    {
        return self::TOOLS[$tool][2] ?? null;
    }

    public static function prefix(string $tool): ?string
    {
        return self::TOOLS[$tool][3] ?? null;
    }

    /**
     * Reverse lookup used by the shared page header to show a "History" link
     * without every view needing to know its own tool name.
     */
    public static function toolForPage(string $page): ?string
    {
        foreach (self::TOOLS as $tool => $fields) {
            if ($fields[2] === $page) {
                return $tool;
            }
        }
        return null;
    }
}
