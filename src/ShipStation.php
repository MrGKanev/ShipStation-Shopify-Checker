<?php
/**
 * ShipStation API client.
 *
 * Fetches orders (paginated) and supports look-ups by order number.
 * API docs: https://www.shipstation.com/docs/api/orders/list-orders/
 */
class ShipStation
{
    private const BASE_URL  = 'https://ssapi.shipstation.com';
    private const PAGE_SIZE = 500;   // max allowed by the API

    private string $auth;
    private ?Cache $cache;

    public function __construct(string $apiKey, string $apiSecret, ?Cache $cache = null)
    {
        $this->auth  = base64_encode("{$apiKey}:{$apiSecret}");
        $this->cache = $cache;
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
        $fetch = function () use ($startDate, $endDate): array {
            $all  = [];
            $page = 1;

            echo "  Fetching ShipStation orders";

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
                $all   = array_merge($all, $batch);

                $pages = $data['pages'] ?? 1;
                echo '.';
                $page++;
            } while ($page <= $pages);

            echo " done (" . count($all) . " orders)\n";
            return $all;
        };

        if ($this->cache) {
            $orders = $this->cache->remember('ss', "{$startDate}|{$endDate}", function () use ($fetch, $startDate, $endDate) {
                $orders = $fetch();
                echo "  [cache] ShipStation orders stored ({$startDate} → {$endDate})\n";
                return $orders;
            });

            // If served from cache the closure above never ran — announce it
            static $announced = [];
            $key = "ss|{$startDate}|{$endDate}";
            if (!isset($announced[$key])) {
                $announced[$key] = true;
            }

            return $orders;
        }

        return $fetch();
    }

    /**
     * Look up orders in ShipStation that match a given Shopify order number.
     * (Not cached — targeted lookup, always live.)
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

    // ── Private ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $url = self::BASE_URL . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("ShipStation cURL error: {$err}");
        }

        // ShipStation rate-limits at 40 req/min — back off on 429
        if ($code === 429) {
            $retryAfter = 60;
            echo "\n  [ShipStation] Rate limited — waiting {$retryAfter}s ...\n";
            sleep($retryAfter);
            return $this->get($path); // one retry
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("ShipStation API error {$code}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("ShipStation returned non-JSON: {$body}");
        }

        return $decoded;
    }
}
