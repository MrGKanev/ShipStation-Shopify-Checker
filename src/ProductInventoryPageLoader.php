<?php
declare(strict_types=1);

/**
 * Loads product catalog and inventory report pages.
 */
class ProductInventoryPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'bundlecheck'        => self::loadBundleCheck($action, $ctx),
            'productcheck'       => self::loadProductCheck($action, $ctx),
            'skudupes'           => self::loadSkuDupes($action, $ctx),
            'inventoryoversell'  => self::loadInventoryOversell($action, $ctx),
            'zombieproducts'     => self::loadZombieProducts($action, $ctx),
            'inventoryaging'     => self::loadInventoryAging($action, $ctx),
            'inventoryforecast'  => self::loadInventoryForecast($action, $ctx),
            'catalogquality'     => self::loadCatalogQuality($action, $ctx),
            default              => [],
        };
    }

    private static function loadBundleCheck(string $action, array $ctx): array
    {
        $bcResult = null;
        $bcError  = '';
        [$bcStart, $bcEnd] = DateRange::fromRequest('bc', 30);

        ['result' => $bcResult, 'error' => $bcError, 'start' => $bcStart, 'end' => $bcEnd] =
            ScanRunner::run($action, 'scan_bundle', $ctx, 'bc', function ($ctx, $start, $end) {
                self::setLimits(300);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchAllOrders($start, $end));

                $rows = self::buildBundleCheckRows($orders);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end];
            }, 30);

        $bcConfig = Comparator::getOrderTypesConfig();
        return compact('bcResult', 'bcError', 'bcStart', 'bcEnd', 'bcConfig');
    }

    /**
     * Bundle Check rows: orders with a missing required component, per
     * Comparator::findMissingRequired(). Skip logic is reimplemented here
     * rather than reusing Comparator::compare()'s - notably it does NOT
     * exclude fulfilled/restocked orders, so a fulfilled order missing a
     * bundle component is still flagged (the documented "covers fulfilled
     * orders too" behavior).
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildBundleCheckRows(array $orders): array
    {
        $rows = [];
        foreach ($orders as $o) {
            if (!empty($o['cancelled_at'])) continue;
            $fin = $o['financial_status'] ?? '';
            if (in_array($fin, ['pending', 'voided', 'refunded', 'partially_refunded'], true)) continue;
            if ((float)($o['total_price'] ?? 0) == 0) continue;
            if (($o['shipping_lines'] ?? []) === []) continue;

            $missingReq = Comparator::findMissingRequired($o);
            if (empty($missingReq)) continue;

            $missingParts = [];
            foreach ($missingReq as $typeName => $items) {
                $missingParts[] = (count($missingReq) > 1 ? "{$typeName}: " : '') . implode(', ', $items);
            }
            $rows[] = [
                'shopify_id'         => $o['id']                 ?? '',
                'order_number'       => $o['name']               ?? '',
                'created_at'         => self::dateOnly($o['created_at']         ?? ''),
                'email'              => $o['email']              ?? '',
                'financial_status'   => $o['financial_status']   ?? '',
                'fulfillment_status' => $o['fulfillment_status'] ?? '',
                'total'              => $o['total_price']        ?? 0,
                'order_type'         => Comparator::classifyOrder($o),
                'missing_required'   => $missingReq,
                'missing_text'       => implode('; ', $missingParts),
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    private static function loadProductCheck(string $action, array $ctx): array
    {
        $pcResult = null;
        $pcError  = '';

        if ($action === 'scan_products') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);

            if ($err = self::requireShopify($ctx)) {
                $pcError = $err;
                self::appendRunLog('scan_products', 'config_error', $runStartedAt, $t0, $pcError);
            } else {
                try {
                    self::setLimits(120);
                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts());
                    $scanned  = count($products);
                    $rows     = self::buildProductCheckRows($products);

                    $pcResult = [
                        'rows'     => $rows,
                        'scanned'  => $scanned,
                        'critical' => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
                        'warnings' => count(array_filter($rows, fn($r) => $r['severity'] === 'warning')),
                    ];
                    self::appendRunLog(
                        'scan_products',
                        count($rows) > 0 ? 'issues_found' : 'ok',
                        $runStartedAt,
                        $t0,
                        scanned: $scanned,
                        rowsFound: count($rows)
                    );
                } catch (Throwable $e) {
                    $pcError = $e->getMessage();
                    self::appendRunLog('scan_products', 'error', $runStartedAt, $t0, $pcError);
                }
            }
        }

        return compact('pcResult', 'pcError');
    }

    /**
     * Product Completeness rows: products with a missing-SKU variant
     * (critical), no images, or no description (warning). A product with
     * both issue types is classified 'critical' overall.
     *
     * @param  array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private static function buildProductCheckRows(array $products): array
    {
        $rows = [];
        foreach ($products as $p) {
            $issues = [];

            if (empty($p['images'])) {
                $issues[] = ['level' => 'warning', 'message' => 'No product images'];
            }

            $desc = trim(strip_tags($p['body_html'] ?? ''));
            if ($desc === '') {
                $issues[] = ['level' => 'warning', 'message' => 'No description'];
            }

            $variantCount = count($p['variants'] ?? []);
            $missingSkuCount = 0;
            foreach ($p['variants'] ?? [] as $v) {
                if (trim($v['sku'] ?? '') === '') {
                    $missingSkuCount++;
                }
            }
            if ($missingSkuCount > 0) {
                $label = $missingSkuCount . ' of ' . $variantCount . ' variant' . ($variantCount !== 1 ? 's' : '') . ' missing SKU';
                $issues[] = ['level' => 'critical', 'message' => $label];
            }

            if (!empty($issues)) {
                $rows[] = [
                    'id'       => (string)($p['id'] ?? ''),
                    'title'    => $p['title']        ?? '',
                    'vendor'   => $p['vendor']       ?? '',
                    'type'     => $p['product_type'] ?? '',
                    'status'   => $p['status']       ?? '',
                    'images'   => count($p['images']   ?? []),
                    'variants' => $variantCount,
                    'issues'   => $issues,
                    'severity' => in_array('critical', array_column($issues, 'level')) ? 'critical' : 'warning',
                ];
            }
        }
        return $rows;
    }

    private static function loadSkuDupes(string $action, array $ctx): array
    {
        $sdResult = null;
        $sdError  = '';

        if ($action === 'scan_skudupes') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);

            if ($err = self::requireShopify($ctx)) {
                $sdError = $err;
                self::appendRunLog('scan_skudupes', 'config_error', $runStartedAt, $t0, $sdError);
            } else {
                try {
                    self::setLimits(120);
                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts('any'));

                    [$rows, $totalVariants] = self::buildSkuDupeRows($products);

                    $sdResult = [
                        'rows'     => $rows,
                        'scanned'  => count($products),
                        'variants' => $totalVariants,
                    ];
                    self::appendRunLog(
                        'scan_skudupes',
                        count($rows) > 0 ? 'issues_found' : 'ok',
                        $runStartedAt,
                        $t0,
                        scanned: count($products),
                        rowsFound: count($rows),
                        meta: ['variants' => $totalVariants]
                    );
                } catch (Throwable $e) {
                    $sdError = $e->getMessage();
                    self::appendRunLog('scan_skudupes', 'error', $runStartedAt, $t0, $sdError);
                }
            }
        }

        return compact('sdResult', 'sdError');
    }

    /**
     * SKU Duplicates rows: SKUs shared by more than one variant, sorted by
     * count descending. Scans whatever product set is passed in (the caller
     * fetches active + draft + archived via fetchAllProducts('any')).
     * Variants with a blank SKU are ignored, per the documented rule.
     *
     * @param  array<int, array<string, mixed>> $products
     * @return array{0: array<int, array<string, mixed>>, 1: int} [rows, totalVariants]
     */
    private static function buildSkuDupeRows(array $products): array
    {
        $skuMap = [];
        $totalVariants = 0;
        foreach ($products as $p) {
            foreach ($p['variants'] ?? [] as $v) {
                $totalVariants++;
                $sku = trim($v['sku'] ?? '');
                if ($sku === '') continue;
                $skuMap[$sku][] = [
                    'product_id'     => (string)($p['id'] ?? ''),
                    'product_title'  => $p['title'] ?? '',
                    'product_status' => $p['status'] ?? '',
                    'variant_title'  => $v['title'] ?? '',
                ];
            }
        }

        $rows = [];
        foreach ($skuMap as $sku => $variants) {
            if (count($variants) > 1) {
                $rows[] = [
                    'sku'      => $sku,
                    'count'    => count($variants),
                    'variants' => $variants,
                ];
            }
        }

        usort($rows, fn($a, $b) => $b['count'] - $a['count']);

        return [$rows, $totalVariants];
    }

    private static function loadInventoryOversell(string $action, array $ctx): array
    {
        $ioResult = null;
        $ioError  = '';

        if ($action === 'scan_inventory') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);

            if ($err = self::requireShopify($ctx)) {
                $ioError = $err;
                self::appendRunLog('scan_inventory', 'config_error', $runStartedAt, $t0, $ioError);
            } elseif ($err = self::requireSS($ctx)) {
                $ioError = $err;
                self::appendRunLog('scan_inventory', 'config_error', $runStartedAt, $t0, $ioError);
            } else {
                try {
                    self::setLimits(300);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $ss      = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);

                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts('active'));
                    $ssOrders = self::suppressOutput(fn() => $ss->fetchAwaitingOrders());

                    $rows = self::buildOversellRows($products, $ssOrders);

                    $ioResult = [
                        'rows'             => $rows,
                        'products_scanned' => count($products),
                        'ss_orders'        => count($ssOrders),
                    ];
                    self::appendRunLog(
                        'scan_inventory',
                        count($rows) > 0 ? 'issues_found' : 'ok',
                        $runStartedAt,
                        $t0,
                        scanned: count($products),
                        rowsFound: count($rows),
                        meta: ['shipstation_orders' => count($ssOrders)]
                    );
                } catch (Throwable $e) {
                    $ioError = $e->getMessage();
                    self::appendRunLog('scan_inventory', 'error', $runStartedAt, $t0, $ioError);
                }
            }
        }

        return compact('ioResult', 'ioError');
    }

    /**
     * Compares Shopify stock against ShipStation awaiting-shipment demand per SKU.
     * When a SKU maps to more than one Shopify product (a data problem the
     * skudupes checker flags separately), stock is still summed across all of
     * them - but the product/variant identity is left ambiguous rather than
     * silently pointing at whichever one happened to be seen last.
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<int, array<string, mixed>> $ssOrders
     * @return array<int, array<string, mixed>>
     */
    private static function buildOversellRows(array $products, array $ssOrders): array
    {
        $skuStock = [];
        $skuInfo  = [];
        foreach ($products as $p) {
            foreach ($p['variants'] ?? [] as $v) {
                $sku = trim($v['sku'] ?? '');
                if ($sku === '') continue;
                if (($v['inventory_management'] ?? '') === '') continue;
                if (($v['inventory_policy'] ?? 'deny') === 'continue') continue;
                $qty = (int)($v['inventory_quantity'] ?? 0);
                $skuStock[$sku]  = ($skuStock[$sku] ?? 0) + $qty;
                $skuInfo[$sku][] = [
                    'product_id'    => (string)($p['id'] ?? ''),
                    'product_title' => $p['title'] ?? '',
                    'variant_title' => $v['title'] ?? '',
                ];
            }
        }

        $skuAwaiting = [];
        foreach ($ssOrders as $o) {
            foreach ($o['items'] ?? [] as $item) {
                $sku = trim($item['sku'] ?? '');
                if ($sku === '') continue;
                $skuAwaiting[$sku] = ($skuAwaiting[$sku] ?? 0) + (int)($item['quantity'] ?? 1);
            }
        }

        $rows = [];
        foreach ($skuAwaiting as $sku => $awaitingQty) {
            if (!isset($skuStock[$sku])) continue;
            $stock = $skuStock[$sku];
            $shortfall = $awaitingQty - $stock;
            if ($shortfall <= 0) continue;

            $matches   = $skuInfo[$sku] ?? [];
            $duplicate = count($matches) > 1;
            $info      = $matches[0] ?? [];

            $rows[] = [
                'sku'           => $sku,
                'product_id'    => $duplicate ? '' : ($info['product_id']    ?? ''),
                'product_title' => $duplicate
                    ? count($matches) . ' products share this SKU'
                    : ($info['product_title'] ?? '(unknown)'),
                'variant_title' => $duplicate ? '' : ($info['variant_title'] ?? ''),
                'stock'         => $stock,
                'awaiting'      => $awaitingQty,
                'shortfall'     => $shortfall,
                'duplicate_sku' => $duplicate,
            ];
        }
        usort($rows, fn($a, $b) => $b['shortfall'] <=> $a['shortfall']);

        return $rows;
    }

    private static function loadZombieProducts(string $action, array $ctx): array
    {
        $zpResult = null;
        $zpError  = '';

        if ($action === 'scan_zombieproducts') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);

            if ($err = self::requireShopify($ctx)) {
                $zpError = $err;
                self::appendRunLog('scan_zombieproducts', 'config_error', $runStartedAt, $t0, $zpError);
            } else {
                try {
                    self::setLimits(120);
                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts('active'));

                    $rows = self::buildZombieProductRows($products);

                    $zpResult = ['rows' => $rows, 'scanned' => count($products)];
                    self::appendRunLog(
                        'scan_zombieproducts',
                        count($rows) > 0 ? 'issues_found' : 'ok',
                        $runStartedAt,
                        $t0,
                        scanned: count($products),
                        rowsFound: count($rows)
                    );
                } catch (Throwable $e) {
                    $zpError = $e->getMessage();
                    self::appendRunLog('scan_zombieproducts', 'error', $runStartedAt, $t0, $zpError);
                }
            }
        }

        return compact('zpResult', 'zpError');
    }

    /**
     * Zombie Products rows: active products with either no variants at all,
     * or where every tracked (non-'continue'-policy) variant is at zero/
     * negative stock. Untracked variants don't count toward trackedCount, so
     * a product with only untracked variants is never flagged as zero_stock.
     *
     * @param  array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private static function buildZombieProductRows(array $products): array
    {
        $rows = [];
        foreach ($products as $p) {
            $variants = $p['variants'] ?? [];
            if (empty($variants)) {
                $rows[] = [
                    'id'     => (string)($p['id'] ?? ''),
                    'title'  => $p['title']        ?? '',
                    'vendor' => $p['vendor']       ?? '',
                    'type'   => $p['product_type'] ?? '',
                    'reason' => 'no_variants',
                    'detail' => 'No variants defined',
                    'stock'  => null,
                ];
                continue;
            }

            $trackedCount = 0;
            $zeroStockCount = 0;
            $totalStock = 0;
            foreach ($variants as $v) {
                if (($v['inventory_management'] ?? '') === '') continue;
                if (($v['inventory_policy'] ?? 'deny') === 'continue') continue;
                $trackedCount++;
                $qty = (int)($v['inventory_quantity'] ?? 0);
                $totalStock += $qty;
                if ($qty <= 0) $zeroStockCount++;
            }

            if ($trackedCount > 0 && $trackedCount === $zeroStockCount) {
                $rows[] = [
                    'id'     => (string)($p['id'] ?? ''),
                    'title'  => $p['title']        ?? '',
                    'vendor' => $p['vendor']       ?? '',
                    'type'   => $p['product_type'] ?? '',
                    'reason' => 'zero_stock',
                    'detail' => "{$trackedCount} tracked variant" . ($trackedCount !== 1 ? 's' : '') . ', all at 0',
                    'stock'  => $totalStock,
                ];
            }
        }
        return $rows;
    }

    private static function loadInventoryAging(string $action, array $ctx): array
    {
        ['result' => $iaResult, 'error' => $iaError, 'start' => $iaStart, 'end' => $iaEnd] =
            ScanRunner::run($action, 'scan_inventoryaging', $ctx, 'ia', function ($ctx, $start, $end) {
                self::setLimits(240);
                [$products, $orders] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    return [
                        $shopify->fetchAllProducts('active'),
                        $shopify->fetchAllOrders($start, $end),
                    ];
                });

                [$rows, $variantCount] = self::buildInventoryAgingRows($products, $orders);
                return ['rows' => $rows, 'products' => count($products), 'variants' => $variantCount, 'orders' => count($orders), 'start' => $start, 'end' => $end];
            }, 30);

        return compact('iaResult', 'iaError', 'iaStart', 'iaEnd');
    }

    /**
     * Inventory Aging rows: tracked, non-'continue'-policy variants with
     * zero/negative stock that still had recent sales, sorted by recent_qty
     * descending. Untracked variants (blank inventory_management) and
     * variants with no recent sales are excluded.
     *
     * @param  array<int, array<string, mixed>> $products
     * @param  array<int, array<string, mixed>> $orders
     * @return array{0: array<int, array<string, mixed>>, 1: int} [rows, variantCount]
     */
    private static function buildInventoryAgingRows(array $products, array $orders): array
    {
        $sales = [];
        foreach ($orders as $o) {
            foreach ($o['line_items'] ?? [] as $li) {
                $sku = trim((string)($li['sku'] ?? ''));
                if ($sku === '') continue;
                if (!isset($sales[$sku])) {
                    $sales[$sku] = ['qty' => 0, 'last_order' => '', 'last_date' => ''];
                }
                $sales[$sku]['qty'] += (int)($li['quantity'] ?? 1);
                $date = self::dateOnly($o['created_at'] ?? '');
                if ($date > $sales[$sku]['last_date']) {
                    $sales[$sku]['last_date'] = $date;
                    $sales[$sku]['last_order'] = $o['name'] ?? '';
                }
            }
        }

        $rows = [];
        $variantCount = 0;
        foreach ($products as $p) {
            foreach ($p['variants'] ?? [] as $v) {
                $variantCount++;
                $sku = trim((string)($v['sku'] ?? ''));
                if ($sku === '' || !isset($sales[$sku])) continue;
                if (($v['inventory_management'] ?? '') === '') continue;
                if (($v['inventory_policy'] ?? 'deny') === 'continue') continue;
                $stock = (int)($v['inventory_quantity'] ?? 0);
                if ($stock > 0) continue;
                $rows[] = [
                    'product_id'    => (string)($p['id'] ?? ''),
                    'product_title' => $p['title'] ?? '',
                    'variant_title' => $v['title'] ?? '',
                    'sku'           => $sku,
                    'stock'         => $stock,
                    'recent_qty'    => $sales[$sku]['qty'],
                    'last_order'    => $sales[$sku]['last_order'],
                    'last_date'     => $sales[$sku]['last_date'],
                ];
            }
        }
        usort($rows, fn($a, $b) => $b['recent_qty'] <=> $a['recent_qty']);
        return [$rows, $variantCount];
    }

    private static function loadInventoryForecast(string $action, array $ctx): array
    {
        $ifResult = null;
        $ifError  = '';

        if ($action === 'scan_inventoryforecast') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);

            if ($err = self::requireShopify($ctx)) {
                $ifError = $err;
                self::appendRunLog('scan_inventoryforecast', 'config_error', $runStartedAt, $t0, $ifError);
            } else {
                try {
                    self::setLimits(300);

                    $end   = date('Y-m-d');
                    $start = date('Y-m-d', strtotime('-30 days'));

                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    [$products, $orders] = self::suppressOutput(function () use ($shopify, $start, $end) {
                        return [
                            $shopify->fetchAllProducts('active'),
                            $shopify->fetchAllOrders($start, $end),
                        ];
                    });

                    [$rows, $variantCount] = self::buildInventoryForecastRows($products, $orders);

                    $ifResult = [
                        'rows'      => $rows,
                        'products'  => count($products),
                        'variants'  => $variantCount,
                        'orders'    => count($orders),
                        'start'     => $start,
                        'end'       => $end,
                        'critical'  => count(array_filter($rows, fn($r) => $r['days_to_zero'] !== null && $r['days_to_zero'] < 7)),
                        'warning'   => count(array_filter($rows, fn($r) => $r['days_to_zero'] !== null && $r['days_to_zero'] >= 7 && $r['days_to_zero'] < 14)),
                    ];

                    self::appendRunLog(
                        'scan_inventoryforecast',
                        count($rows) > 0 ? 'issues_found' : 'ok',
                        $runStartedAt,
                        $t0,
                        scanned: count($products),
                        rowsFound: count($rows),
                        meta: ['orders' => count($orders)]
                    );
                } catch (Throwable $e) {
                    $ifError = $e->getMessage();
                    self::appendRunLog('scan_inventoryforecast', 'error', $runStartedAt, $t0, $ifError);
                }
            }
        }

        return compact('ifResult', 'ifError');
    }

    /**
     * Inventory Forecast rows: tracked, non-'continue'-policy variants with
     * either recent (30-day) sales or low stock (<=30), projecting days to
     * zero from the 30-day daily sell-through rate. A variant with no sales
     * and stock <=30 but a stock of 0 already has daily_rate=0 so days_to_zero
     * stays null - it's excluded too since there's nothing to forecast.
     * Sorted ascending by days_to_zero, with null (no depletion risk) last.
     *
     * @param  array<int, array<string, mixed>> $products
     * @param  array<int, array<string, mixed>> $orders
     * @return array{0: array<int, array<string, mixed>>, 1: int} [rows, variantCount]
     */
    private static function buildInventoryForecastRows(array $products, array $orders): array
    {
        $skuSales = [];
        foreach ($orders as $o) {
            if (!empty($o['cancelled_at'])) continue;
            foreach ($o['line_items'] ?? [] as $li) {
                $sku = trim((string)($li['sku'] ?? ''));
                if ($sku === '') continue;
                $skuSales[$sku] = ($skuSales[$sku] ?? 0) + (int)($li['quantity'] ?? 1);
            }
        }

        $rows        = [];
        $variantCount = 0;
        foreach ($products as $p) {
            foreach ($p['variants'] ?? [] as $v) {
                $variantCount++;
                if (($v['inventory_management'] ?? '') === '') continue;
                if (($v['inventory_policy'] ?? 'deny') === 'continue') continue;

                $sku   = trim((string)($v['sku'] ?? ''));
                $stock = (int)($v['inventory_quantity'] ?? 0);
                $sold  = (int)($skuSales[$sku] ?? 0);

                if ($sold === 0 && $stock > 30) continue;

                $dailyRate   = round($sold / 30, 3);
                $daysToZero  = ($dailyRate > 0 && $stock > 0)
                    ? (int) ceil($stock / $dailyRate)
                    : null;

                if ($daysToZero === null && $sold === 0) continue;

                $rows[] = [
                    'product_id'    => (string)($p['id'] ?? ''),
                    'product_title' => $p['title'] ?? '',
                    'variant_title' => $v['title'] ?? '',
                    'sku'           => $sku,
                    'stock'         => $stock,
                    'sold_30d'      => $sold,
                    'daily_rate'    => $dailyRate,
                    'days_to_zero'  => $daysToZero,
                ];
            }
        }

        usort($rows, function ($a, $b) {
            $az = $a['days_to_zero'];
            $bz = $b['days_to_zero'];
            if ($az === null && $bz === null) return 0;
            if ($az === null) return 1;
            if ($bz === null) return -1;
            return $az <=> $bz;
        });

        return [$rows, $variantCount];
    }

    private static function loadCatalogQuality(string $action, array $ctx): array
    {
        $cqResult = null;
        $cqError  = '';

        if ($action === 'scan_catalogquality') {
            $runStartedAt = date('Y-m-d H:i:s');
            $t0 = microtime(true);

            if ($err = self::requireShopify($ctx)) {
                $cqError = $err;
                self::appendRunLog('scan_catalogquality', 'config_error', $runStartedAt, $t0, $cqError);
            } else {
                try {
                    self::setLimits(120);
                    $shopify  = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], $ctx['cacheObj']);
                    $products = self::suppressOutput(fn() => $shopify->fetchAllProducts('active'));
                    $scanned  = count($products);
                    $rows     = self::buildCatalogQualityRows($products);

                    $cqResult = ['rows' => $rows, 'scanned' => $scanned];
                    self::appendRunLog(
                        'scan_catalogquality',
                        count($rows) > 0 ? 'issues_found' : 'ok',
                        $runStartedAt,
                        $t0,
                        scanned: $scanned,
                        rowsFound: count($rows)
                    );
                } catch (Throwable $e) {
                    $cqError = $e->getMessage();
                    self::appendRunLog('scan_catalogquality', 'error', $runStartedAt, $t0, $cqError);
                }
            }
        }

        return compact('cqResult', 'cqError');
    }

    /**
     * Catalog Quality rows: active products not published to the Online
     * Store channel, missing an SEO title/description, or not assigned to
     * any collection. A product can carry multiple issues at once.
     *
     * @param  array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private static function buildCatalogQualityRows(array $products): array
    {
        $rows = [];
        foreach ($products as $p) {
            $issues = [];

            if (empty($p['published'])) {
                $issues[] = 'Not published to Online Store';
            }
            if (trim((string)($p['seo_title'] ?? '')) === '') {
                $issues[] = 'Missing SEO title';
            }
            if (trim((string)($p['seo_description'] ?? '')) === '') {
                $issues[] = 'Missing SEO description';
            }
            if ((int)($p['collection_count'] ?? 0) === 0) {
                $issues[] = 'Not in any collection';
            }

            if (empty($issues)) continue;
            $rows[] = [
                'id'       => (string)($p['id'] ?? ''),
                'title'    => $p['title']        ?? '',
                'vendor'   => $p['vendor']       ?? '',
                'type'     => $p['product_type'] ?? '',
                'issues'   => $issues,
            ];
        }
        return $rows;
    }

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
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

    private static function appendRunLog(
        string $tool,
        string $status,
        string $createdAt,
        float $startedAt,
        string $error = '',
        ?int $scanned = null,
        ?int $rowsFound = null,
        array $meta = []
    ): void {
        RunLog::append([
            'tool'       => $tool,
            'status'     => $status,
            'created_at' => $createdAt,
            'duration'   => round(microtime(true) - $startedAt, 2),
            'scanned'    => $scanned,
            'rows_found' => $rowsFound,
            'error'      => $error,
            'meta'       => ['api_version' => Shopify::API_VERSION] + $meta,
        ]);
    }

    private static function suppressOutput(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }
}
