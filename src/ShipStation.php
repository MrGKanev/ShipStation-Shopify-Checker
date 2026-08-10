<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;

/**
 * ShipStation API client.
 *
 * Fetches orders (paginated) and supports look-ups by order number.
 * API docs: https://www.shipstation.com/docs/api/orders/list-orders/
 */
class ShipStation
{
    private const string BASE_URL  = 'https://ssapi.shipstation.com';
    private const int    PAGE_SIZE = 500;   // max allowed by the API

    private readonly string $auth;
    private readonly ?Cache $cache;
    private readonly Client $http;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        ?Cache $cache = null,
        ?HandlerStack $stack = null
    ) {
        $this->auth  = base64_encode("{$apiKey}:{$apiSecret}");
        $this->cache = $cache;
        $stack ??= HandlerStack::create();
        $stack->push(Middleware::retry(
            function (int $retries, $req, ?ResponseInterface $res = null) {
                if ($res?->getStatusCode() !== 429 || $retries >= 5) return false;
                $h    = $res->getHeaderLine('Retry-After');
                $wait = $h !== '' ? (int)$h : 60;
                $this->logWarning('ShipStation rate limited; retrying after {seconds}s', ['seconds' => $wait]);
                return true;
            },
            function ($retries, $res) {
                $h = $res?->getHeaderLine('Retry-After') ?? '';
                return ($h !== '' ? (int)$h : 60) * 1000;
            }
        ));
        $this->http = new Client(['handler' => $stack]);
    }

    // ── Public ────────────────────────────────────────────────────────

    /**
     * Returns every order created between $start and $end (inclusive).
     * Results are served from cache when available and fresh.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllOrders(string $startDate, string $endDate): array
    {
        if ($this->cache === null || $this->cache->getTtl() <= 0) {
            return $this->fetchAllOrderPages($startDate, $endDate, null);
        }

        $cacheKey = "{$startDate}|{$endDate}";
        $cpDir   = $this->cache->checkpointDir('ss', $cacheKey);
        $metaFile = $cpDir . '/_meta.json';
        $staleAvailable = false;

        // Cache hit: all pages already on disk and still fresh
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if (is_array($meta) && ($meta['expires_at'] ?? 0) > time()) {
                $this->logInfo('ShipStation orders loaded from checkpoint ({start} -> {end})', [
                    'start' => $startDate,
                    'end'   => $endDate,
                ]);
                return $this->mergePageFiles($cpDir);
            }

            $staleAvailable = $this->hasCheckpointPages($cpDir);
        }

        if ($staleAvailable) {
            $tmpDir = $this->temporaryCheckpointDir($cpDir);
            try {
                mkdir($tmpDir, 0755, true);
                $this->fetchAllOrderPages($startDate, $endDate, $tmpDir);
                AtomicFile::writeJson($tmpDir . '/_meta.json', ['expires_at' => time() + $this->cache->getTtl()], 0);
                $this->replaceCheckpoint($tmpDir, $cpDir);

                $all = $this->mergePageFiles($cpDir);
                $this->logInfo('ShipStation orders refreshed ({start} -> {end}); {count} orders', [
                    'start' => $startDate,
                    'end'   => $endDate,
                    'count' => count($all),
                ]);
                return $all;
            } catch (Throwable $e) {
                $this->removeCheckpoint($tmpDir);
                $this->logWarning('ShipStation refresh failed ({start} -> {end}); stale checkpoint left in place: {message}', [
                    'start'   => $startDate,
                    'end'     => $endDate,
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        if (!is_dir($cpDir)) {
            mkdir($cpDir, 0755, true);
        }

        // Find last completed page so we can resume after a crash
        $existingPages = glob($cpDir . '/page_*.json') ?: [];
        $startPage     = count($existingPages) + 1;

        if ($startPage > 1) {
            $this->logInfo('Resuming ShipStation fetch from page {page}', ['page' => $startPage]);
        }

        $this->fetchAllOrderPages($startDate, $endDate, $cpDir, $startPage);

        // Write meta (TTL marker) - page files ARE the cache now
        AtomicFile::writeJson($metaFile, ['expires_at' => time() + $this->cache->getTtl()], 0);

        $all = $this->mergePageFiles($cpDir);
        $this->logInfo('ShipStation orders stored ({start} -> {end}); {count} orders', [
            'start' => $startDate,
            'end'   => $endDate,
            'count' => count($all),
        ]);

        return $all;
    }

    private function hasCheckpointPages(string $cpDir): bool
    {
        return (glob($cpDir . '/page_*.json') ?: []) !== [];
    }

    private function temporaryCheckpointDir(string $cpDir): string
    {
        return dirname($cpDir) . '/.' . basename($cpDir) . '.tmp_' . bin2hex(random_bytes(4));
    }

    private function fetchAllOrderPages(
        string $startDate,
        string $endDate,
        ?string $checkpointDir,
        int $startPage = 1
    ): array {
        $page       = $startPage;
        $totalPages = null;
        $all        = [];

        do {
            $params = http_build_query([
                'createDateStart' => $startDate . ' 00:00:00',
                'createDateEnd'   => $endDate   . ' 23:59:59',
                'pageSize'        => self::PAGE_SIZE,
                'page'            => $page,
                'sortBy'          => 'OrderDate',
                'sortDir'         => 'ASC',
            ]);

            $data  = $this->get("/orders?{$params}");
            $batch = $data['orders'] ?? [];

            $totalPages = $data['pages'] ?? $totalPages ?? 1;

            if ($checkpointDir !== null) {
                AtomicFile::writeJson($checkpointDir . '/page_' . $page . '.json', $batch, 0);
            } else {
                array_push($all, ...$batch);
            }

            unset($batch, $data);
            $page++;
        } while ($page <= $totalPages);

        return $all;
    }

    /** Read and merge page files from a checkpoint dir without keeping all in memory at once. */
    private function mergePageFiles(string $cpDir): array
    {
        $files = glob($cpDir . '/page_*.json') ?: [];
        usort($files, fn($a, $b) =>
            (int) sscanf(basename($a), 'page_%d.json')[0] <=>
            (int) sscanf(basename($b), 'page_%d.json')[0]
        );

        $all = [];
        foreach ($files as $f) {
            $batch = json_decode(file_get_contents($f), true) ?? [];
            foreach ($batch as $order) {
                $all[] = $order;
            }
            unset($batch);
        }
        return $all;
    }

    private function removeCheckpoint(string $cpDir): void
    {
        if (!is_dir($cpDir)) {
            return;
        }

        foreach (scandir($cpDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $cpDir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeCheckpoint($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($cpDir);
    }

    private function replaceCheckpoint(string $tmpDir, string $cpDir): void
    {
        $backupDir = '';
        if (is_dir($cpDir)) {
            $backupDir = dirname($cpDir) . '/.' . basename($cpDir) . '.bak_' . bin2hex(random_bytes(4));
            if (!@rename($cpDir, $backupDir)) {
                throw new RuntimeException('Could not move stale ShipStation checkpoint aside.');
            }
        }

        if (!@rename($tmpDir, $cpDir)) {
            if ($backupDir !== '' && is_dir($backupDir)) {
                @rename($backupDir, $cpDir);
            }
            throw new RuntimeException('Could not activate refreshed ShipStation checkpoint.');
        }

        if ($backupDir !== '') {
            $this->removeCheckpoint($backupDir);
        }
    }

    /**
     * Look up orders in ShipStation that match a given Shopify order number.
     * (Not cached - targeted lookup, always live.)
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderNumber(string $orderNumber): array
    {
        $params = http_build_query([
            'orderNumber' => $orderNumber,
            'pageSize'    => 50,
        ]);
        $data = $this->get("/orders?{$params}");
        return $data['orders'] ?? [];
    }

    /**
     * Creates an order in ShipStation from a full Shopify order array.
     * Maps Shopify fields → ShipStation createorder payload.
     *
     * @param  array<string, mixed> $shopifyOrder
     * @return array<string, mixed> Created ShipStation order
     */
    public function createOrder(array $shopifyOrder): array
    {
        return $this->post('/orders/createorder', $this->buildPayload($shopifyOrder));
    }

    /**
     * Builds the ShipStation createorder payload from a Shopify order without sending it.
     * Used by the dry-run preview feature.
     *
     * @param  array<string, mixed> $shopifyOrder
     * @return array<string, mixed>
     */
    public function buildPayload(array $shopifyOrder): array
    {
        $addr = function (array $a): array {
            $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
            return [
                'name'       => $name ?: ($a['name'] ?? ''),
                'company'    => $a['company']  ?? null,
                'street1'    => $a['address1'] ?? null,
                'street2'    => $a['address2'] ?? null,
                'city'       => $a['city']     ?? null,
                'state'      => $a['province_code'] ?? $a['province'] ?? null,
                'postalCode' => $a['zip']      ?? null,
                'country'    => $a['country_code'] ?? $a['country'] ?? null,
                'phone'      => $a['phone']    ?? null,
            ];
        };

        $items = [];
        foreach ($shopifyOrder['line_items'] ?? [] as $li) {
            $items[] = [
                'lineItemKey' => (string) ($li['id'] ?? ''),
                'name'        => $li['title'] ?? '',
                'sku'         => $li['sku']   ?? null,
                'quantity'    => (int)   ($li['quantity'] ?? 1),
                'unitPrice'   => (float) ($li['price']    ?? 0),
            ];
        }

        $shippingAmount = 0.0;
        foreach ($shopifyOrder['shipping_lines'] ?? [] as $sl) {
            $shippingAmount += (float) ($sl['price'] ?? 0);
        }

        return [
            'orderNumber'      => (string) ($shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? ''),
            'orderDate'        => $shopifyOrder['created_at'] ?? date('c'),
            'orderStatus'      => 'awaiting_shipment',
            'customerEmail'    => $shopifyOrder['email']            ?? null,
            'customerUsername' => $shopifyOrder['email']            ?? null,
            'billTo'           => $addr($shopifyOrder['billing_address']  ?? []),
            'shipTo'           => $addr($shopifyOrder['shipping_address'] ?? $shopifyOrder['billing_address'] ?? []),
            'items'            => $items,
            'amountPaid'       => (float) ($shopifyOrder['total_price']   ?? 0),
            'taxAmount'        => (float) ($shopifyOrder['total_tax']     ?? 0),
            'shippingAmount'   => $shippingAmount,
        ];
    }

    public function fetchVoidedShipments(string $startDate, string $endDate): array
    {
        $all  = [];
        $page = 1;
        do {
            $params = http_build_query([
                'voidDate_start' => $startDate . ' 00:00:00',
                'voidDate_end'   => $endDate   . ' 23:59:59',
                'pageSize'       => 500,
                'page'           => $page,
            ]);
            $result = $this->get("/shipments?{$params}");
            $items  = $result['shipments'] ?? [];
            if (empty($items)) break;
            array_push($all, ...$items);
            $total = $result['total'] ?? 0;
            $page++;
        } while (count($all) < $total);
        return $all;
    }

    /**
     * Fetches all shipments created in a date range.
     * Each shipment includes carrierCode, serviceCode, shipDate, deliveryDate, trackingNumber.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchShipmentsByDate(string $startDate, string $endDate): array
    {
        $all  = [];
        $page = 1;
        do {
            $params = http_build_query([
                'shipDate_start' => $startDate . ' 00:00:00',
                'shipDate_end'   => $endDate   . ' 23:59:59',
                'pageSize'       => 500,
                'page'           => $page,
            ]);
            $result = $this->get("/shipments?{$params}");
            $items  = $result['shipments'] ?? [];
            if (empty($items)) break;
            array_push($all, ...$items);
            $total = $result['total'] ?? 0;
            $page++;
        } while (count($all) < $total);
        return $all;
    }

    /**
     * Fetches all orders currently in awaiting_shipment status (no date filter).
     * Used for oversell risk checks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAwaitingOrders(): array
    {
        $all  = [];
        $page = 1;
        do {
            $params = http_build_query([
                'orderStatus' => 'awaiting_shipment',
                'pageSize'    => self::PAGE_SIZE,
                'page'        => $page,
            ]);
            $data  = $this->get("/orders?{$params}");
            $batch = $data['orders'] ?? [];
            array_push($all, ...$batch);
            $totalPages = $data['pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);
        return $all;
    }

    /**
     * Fetches orders in ShipStation statuses that still require operational action.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchActiveOrders(): array
    {
        $all = [];
        foreach (['awaiting_payment', 'awaiting_shipment', 'on_hold'] as $status) {
            array_push($all, ...$this->fetchOrdersByStatus($status));
        }
        return $all;
    }

    /**
     * Fetches all shipments for a given order number (not cached - live lookup).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrderShipments(string $orderNumber): array
    {
        $params = http_build_query(['orderNumber' => $orderNumber, 'pageSize' => 100]);
        $data   = $this->get("/shipments?{$params}");
        return $data['shipments'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrdersByStatus(string $status): array
    {
        $all  = [];
        $page = 1;
        do {
            $params = http_build_query([
                'orderStatus' => $status,
                'pageSize'    => self::PAGE_SIZE,
                'page'        => $page,
            ]);
            $data  = $this->get("/orders?{$params}");
            $batch = $data['orders'] ?? [];
            array_push($all, ...$batch);
            $totalPages = $data['pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);
        return $all;
    }

    // ── Private ───────────────────────────────────────────────────────

    /**
     * Sends a request with auth headers.
     * ShipStation rate-limits at 40 req/min - backs off 60s on 429.
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['http_errors']                  = false;
        $options['timeout']                      = $options['timeout'] ?? 30;
        $options['headers']['Authorization']     = 'Basic ' . $this->auth;
        $options['headers']['Content-Type']    ??= 'application/json';

        return $this->http->request($method, self::BASE_URL . $path, $options);
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body): array
    {
        $response = $this->request('POST', $path, ['json' => $body]);
        $code     = $response->getStatusCode();
        $raw      = (string) $response->getBody();

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("ShipStation API error {$code}: {$raw}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("ShipStation returned non-JSON: {$raw}");
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $response = $this->request('GET', $path);
        $code     = $response->getStatusCode();
        $raw      = (string) $response->getBody();

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("ShipStation API error {$code}: {$raw}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("ShipStation returned non-JSON: {$raw}");
        }

        return $decoded;
    }

    private function logInfo(string $message, array $context = []): void
    {
        if (class_exists(Logger::class)) {
            Logger::getInstance()->info($message, $context);
        }
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (class_exists(Logger::class)) {
            Logger::getInstance()->warning($message, $context);
        }
    }
}
