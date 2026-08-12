<?php
declare(strict_types=1);

require_once __DIR__ . '/RiskScorer.php';

/**
 * Loads search and lookup pages with narrow request/response state.
 */
class SearchLookupPageLoader
{
    public static function load(string $page, string $action, array $ctx, array $globalData = []): array
    {
        return match ($page) {
            'globalsearch' => self::loadGlobalSearch($ctx, $globalData),
            'spotcheck'    => self::loadSpotCheck($action, $ctx),
            'metafields'   => self::loadMetafields($action, $ctx),
            'tagsearch'    => self::loadTagSearch($action, $ctx),
            'customer'     => self::loadCustomer($action, $ctx),
            'tracking'     => self::loadTracking($action, $ctx),
            default        => [],
        };
    }

    private static function loadGlobalSearch(array $ctx, array $globalData): array
    {
        $q         = trim($_GET['q'] ?? '');
        $gsResults = null;

        if ($q !== '') {
            $norm = Comparator::normalise($q);
            $gsResults = ['query' => $q, 'reports' => [], 'push' => [], 'ignored' => []];

            foreach (($globalData['orderHistory'] ?? []) as $num => $entry) {
                $num = (string) $num;
                if ($norm && (str_contains($num, $norm) || str_contains($norm, $num))) {
                    $gsResults['reports'][] = ['number' => $num] + $entry;
                }
            }

            foreach (($globalData['pushLog'] ?? []) as $entry) {
                $entryNorm = Comparator::normalise($entry['order_number'] ?? '');
                if ($norm && ($entryNorm === $norm || str_contains($entryNorm, $norm) || str_contains($norm, $entryNorm))) {
                    $gsResults['push'][] = $entry;
                }
            }

            foreach (($ctx['ignoredOrders'] ?? []) as $num => $entry) {
                $num = (string) $num;
                if ($norm && (str_contains($num, $norm) || str_contains($norm, $num))) {
                    $gsResults['ignored'][] = ['number' => $num] + $entry;
                }
            }
        }

        return compact('gsResults');
    }

    private static function loadSpotCheck(string $action, array $ctx): array
    {
        $spotResults         = null;
        $spotError           = '';
        $spotInput           = trim($_GET['prefill'] ?? '');
        $spotcheckRiskScores = [];

        $noteTemplates     = [];
        $noteTemplatesPath = __DIR__ . '/../data/note_templates.json';
        if (file_exists($noteTemplatesPath)) {
            $decoded = json_decode((string) file_get_contents($noteTemplatesPath), true);
            if (is_array($decoded)) {
                $noteTemplates = $decoded;
            }
        }

        if ($action === 'spotcheck') {
            $spotInput = trim($_POST['orders'] ?? '');
            $numbers   = array_filter(array_map('trim', preg_split('/[\s,]+/', $spotInput)));

            if (empty($numbers)) {
                $spotError = 'Enter at least one order number.';
            } elseif (count($numbers) > 50) {
                $spotError = 'Maximum 50 order numbers at once.';
            } else {
                $spotMode = $_POST['spotcheck_mode'] ?? 'both';
                $checkSS  = in_array($spotMode, ['both', 'ss'], true);
                $checkSh  = in_array($spotMode, ['both', 'shopify'], true)
                    && $ctx['shopifyToken'] && $ctx['shopifyStore'] !== 'N/A';

                if ($checkSS && ($err = self::requireSS($ctx))) {
                    $spotError = $err;
                } else {
                    try {
                        $ss      = $checkSS ? new ShipStation($ctx['ssKey'], $ctx['ssSecret']) : null;
                        $shopify = $checkSh ? new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']) : null;

                        $spotResults = [];
                        foreach ($numbers as $num) {
                            $clean    = ltrim(trim($num), '#');
                            $ssOrders = $ss      ? $ss->findByOrderNumber($clean)     : null;
                            $shOrders = $shopify ? $shopify->findByOrderNumber($clean) : null;

                            $spotResults[] = [
                                'input'          => $num,
                                'number'         => $clean,
                                'mode'           => $spotMode,
                                'ss_orders'      => $ssOrders,
                                'ss_found'       => !empty($ssOrders),
                                'shopify_orders' => $shOrders,
                                'shopify_found'  => !empty($shOrders),
                                'orders'         => $ssOrders ?? [],
                                'found'          => !empty($ssOrders),
                            ];
                        }

                        // Compute composite risk scores for all Shopify orders found.
                        foreach ($spotResults as $r) {
                            foreach ($r['shopify_orders'] ?? [] as $o) {
                                $id = (string) ($o['id'] ?? '');
                                if ($id !== '') {
                                    $spotcheckRiskScores[$id] = RiskScorer::score($o);
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        $spotError = 'Error: ' . $e->getMessage();
                    }
                }
            }
        }

        return compact('spotResults', 'spotInput', 'spotError', 'spotcheckRiskScores', 'noteTemplates');
    }

    private static function loadMetafields(string $action, array $ctx): array
    {
        $metafieldDefs        = null;
        $metafieldOrders      = null;
        $metafieldInput       = '';
        $metafieldError       = '';
        $metafieldFilter      = '';
        $metafieldSearch      = null;
        $metafieldSearchError = '';

        if ($err = self::requireShopify($ctx)) {
            $metafieldError = $err;
            return compact('metafieldDefs', 'metafieldOrders', 'metafieldInput', 'metafieldError',
                           'metafieldFilter', 'metafieldSearch', 'metafieldSearchError');
        }

        $shopifyMeta = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);

        try {
            $metafieldDefs = $shopifyMeta->fetchMetafieldDefinitions('ORDER');
        } catch (Throwable $e) {
            $metafieldError = 'Could not load metafield definitions: ' . $e->getMessage();
        }

        if ($action === 'metafield_search') {
            $mfNs    = trim($_POST['mf_ns']    ?? '');
            $mfKey   = trim($_POST['mf_key']   ?? '');
            $mfVal   = trim($_POST['mf_value'] ?? '');
            $mfStart = trim($_POST['mf_start'] ?? '');
            $mfEnd   = trim($_POST['mf_end']   ?? '');

            if (!$mfNs || !$mfKey) {
                $metafieldSearchError = 'Namespace and key are required.';
            } else {
                try {
                    self::setLimits(120);
                    $result = $shopifyMeta->searchOrdersByMetafield($mfNs, $mfKey, $mfVal, $mfStart, $mfEnd);
                    $metafieldSearch = [
                        'namespace'     => $mfNs,
                        'key'           => $mfKey,
                        'value'         => $mfVal,
                        'start'         => $mfStart,
                        'end'           => $mfEnd,
                        'orders'        => $result['matches'],
                        'scanned'       => $result['scanned'],
                        'with_mf'       => $result['with_mf'],
                        'sample_values' => $result['sample_values'],
                        'pages'         => $result['pages'],
                        'truncated'     => $result['truncated'],
                    ];
                } catch (Throwable $e) {
                    $metafieldSearchError = $e->getMessage();
                }
            }
        }

        if ($action === 'metafield_lookup') {
            $metafieldInput  = trim($_POST['mf_orders'] ?? '');
            $metafieldFilter = trim($_POST['mf_filter'] ?? '');
            $numbers         = array_filter(array_map('trim', preg_split('/[\s,]+/', $metafieldInput)));

            if (empty($numbers)) {
                $metafieldError = 'Enter at least one order number.';
            } elseif (count($numbers) > 20) {
                $metafieldError = 'Maximum 20 order numbers at once.';
            } else {
                $metafieldOrders = [];
                foreach ($numbers as $num) {
                    $clean    = ltrim($num, '#');
                    $shOrders = $shopifyMeta->findByOrderNumber($clean);

                    if (empty($shOrders)) {
                        $metafieldOrders[] = ['number' => $clean, 'shopify_id' => null, 'metafields' => [], 'found' => false];
                        continue;
                    }

                    foreach ($shOrders as $shOrder) {
                        $oid = (string) ($shOrder['id'] ?? '');
                        $mfs = $oid ? $shopifyMeta->getOrderMetafields($oid) : [];

                        if ($metafieldFilter !== '') {
                            $mfs = self::filterMetafields($mfs, $metafieldFilter);
                        }

                        $metafieldOrders[] = [
                            'number'     => $clean,
                            'shopify_id' => $oid,
                            'name'       => $shOrder['name'] ?? ('#' . $clean),
                            'metafields' => $mfs,
                            'found'      => true,
                        ];
                    }
                }
            }
        }

        return compact('metafieldDefs', 'metafieldOrders', 'metafieldInput', 'metafieldError',
                       'metafieldFilter', 'metafieldSearch', 'metafieldSearchError');
    }

    /**
     * Case-insensitive substring match against "namespace.key" or the value.
     *
     * @param  array<int, array<string, mixed>> $mfs
     * @return array<int, array<string, mixed>>
     */
    private static function filterMetafields(array $mfs, string $filter): array
    {
        return array_values(array_filter($mfs, function ($mf) use ($filter) {
            $nk = ($mf['namespace'] ?? '') . '.' . ($mf['key'] ?? '');
            return stripos($nk, $filter) !== false
                || stripos((string) ($mf['value'] ?? ''), $filter) !== false;
        }));
    }

    private static function loadTagSearch(string $action, array $ctx): array
    {
        $tagSearch      = null;
        $tagSearchError = '';
        $tagInput       = '';
        $tagStart       = '';
        $tagEnd         = '';

        if ($action === 'tag_search') {
            $tagInput = trim($_POST['tag_input'] ?? '');
            $tagStart = trim($_POST['tag_start'] ?? '');
            $tagEnd   = trim($_POST['tag_end']   ?? '');

            if (!$tagInput) {
                $tagSearchError = 'Enter at least one tag.';
            } elseif ($err = self::requireShopify($ctx)) {
                $tagSearchError = $err;
            } else {
                try {
                    self::setLimits(120);
                    $shopifyTag = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $tagResult  = $shopifyTag->searchOrdersByTag($tagInput, $tagStart, $tagEnd);
                    $tagSearch  = array_merge($tagResult, [
                        'tag'   => $tagInput,
                        'start' => $tagStart,
                        'end'   => $tagEnd,
                    ]);
                } catch (Throwable $e) {
                    $tagSearchError = $e->getMessage();
                }
            }
        }

        return compact('tagSearch', 'tagSearchError', 'tagInput', 'tagStart', 'tagEnd');
    }

    private static function loadCustomer(string $action, array $ctx): array
    {
        $customerResult = null;
        $customerError  = '';
        $customerEmail  = trim($_GET['email'] ?? $_POST['customer_email'] ?? '');

        if ($action === 'customer_lookup') {
            $customerEmail = trim($_POST['customer_email'] ?? '');

            if (!$customerEmail || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                $customerError = 'Enter a valid email address.';
            } elseif ($err = self::requireShopify($ctx)) {
                $customerError = $err;
            } else {
                try {
                    self::setLimits(120);
                    $shopify        = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $customerResult = $shopify->lookupCustomer($customerEmail);
                    $customerResult['email'] = $customerEmail;

                    // Compute composite risk scores keyed by legacyResourceId.
                    $riskScores = [];
                    foreach ($customerResult['orders'] ?? [] as $o) {
                        $legacyId = (string) ($o['legacyResourceId'] ?? '');
                        if ($legacyId !== '') {
                            $riskScores[$legacyId] = RiskScorer::score($o);
                        }
                    }
                    $customerResult['riskScores'] = $riskScores;
                } catch (Throwable $e) {
                    $customerError = $e->getMessage();
                }
            }
        }

        return compact('customerResult', 'customerError', 'customerEmail');
    }

    private static function loadTracking(string $action, array $ctx): array
    {
        $trackingResults = null;
        $trackingError   = '';
        $trackingInput   = trim($_GET['prefill'] ?? '');

        if ($action === 'lookup_tracking') {
            $trackingInput = trim($_POST['tracking_orders'] ?? '');
            $numbers = array_filter(array_map('trim', preg_split('/[\s,]+/', $trackingInput)));

            if (empty($numbers)) {
                $trackingError = 'Enter at least one order number.';
            } elseif (count($numbers) > 30) {
                $trackingError = 'Maximum 30 order numbers at once.';
            } elseif ($err = self::requireSS($ctx)) {
                $trackingError = $err;
            } else {
                try {
                    $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                    $trackingResults = [];

                    foreach ($numbers as $num) {
                        $clean    = ltrim(trim($num), '#');
                        $ssOrders = $ss->findByOrderNumber($clean);

                        $trackingResults[] = self::buildTrackingResult($clean, $ssOrders);
                    }
                } catch (Throwable $e) {
                    $trackingError = $e->getMessage();
                }
            }
        }

        return compact('trackingResults', 'trackingError', 'trackingInput');
    }

    /**
     * Tracking Feed result for a single order number: the carrier-specific
     * public tracking URL (looked up from a fixed carrier-code map) plus a
     * direct ShipStation order-details link. Carriers not in the map, or a
     * shipment with no tracking number yet, get a null trackingUrl.
     *
     * @param  array<int, array<string, mixed>> $ssOrders
     * @return array{number: string, found: bool, shipments: array<int, array<string, mixed>>}
     */
    private static function buildTrackingResult(string $clean, array $ssOrders): array
    {
        if (empty($ssOrders)) {
            return ['number' => $clean, 'found' => false, 'shipments' => []];
        }

        $carrierUrls = [
            'usps'       => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
            'fedex'      => 'https://www.fedex.com/fedextrack/?tracknumbers=',
            'ups'        => 'https://www.ups.com/track?tracknum=',
            'dhl'        => 'https://www.dhl.com/en/express/tracking.html?AWB=',
            'stamps_com' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
            'ontrac'     => 'https://www.ontrac.com/tracking/?number=',
            'lasership'  => 'https://www.lasership.com/track/',
        ];

        $shipments = [];
        foreach ($ssOrders as $o) {
            $carrier  = strtolower($o['carrierCode'] ?? '');
            $tracking = $o['trackingNumber'] ?? '';
            $baseUrl  = $carrierUrls[$carrier] ?? null;
            $shipments[] = [
                'orderId'        => $o['orderId']        ?? '',
                'orderStatus'    => $o['orderStatus']    ?? '',
                'carrierCode'    => $o['carrierCode']    ?? '',
                'serviceCode'    => $o['serviceCode']    ?? '',
                'trackingNumber' => $tracking,
                'shipDate'       => $o['shipDate']       ?? '',
                'trackingUrl'    => ($baseUrl && $tracking) ? $baseUrl . urlencode($tracking) : null,
                'ssUrl'          => $o['orderId'] ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode($o['orderId']) : null,
            ];
        }

        return ['number' => $clean, 'found' => true, 'shipments' => $shipments];
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
    }

    private static function requireShopify(array $ctx): ?string
    {
        return (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A')
            ? 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.'
            : null;
    }

    private static function requireSS(array $ctx): ?string
    {
        return (!$ctx['ssKey'] || !$ctx['ssSecret'])
            ? 'SS_API_KEY / SS_API_SECRET not set in .env.'
            : null;
    }
}
