<?php
/**
 * Shopify Admin REST API client.
 *
 * Fetches all orders in a date range using cursor-based pagination
 * (Link header) — required for stores with > 250 orders per page.
 *
 * API docs: https://shopify.dev/docs/api/admin-rest/latest/resources/order
 */
class Shopify
{
    private const PAGE_SIZE = 250; // max allowed by Shopify

    private string $baseUrl;
    private string $token;
    private ?Cache $cache;

    public function __construct(string $store, string $accessToken, ?Cache $cache = null)
    {
        $host = str_contains($store, '.') ? $store : "{$store}.myshopify.com";
        $this->baseUrl = "https://{$host}/admin/api/2024-01";
        $this->token   = $accessToken;
        $this->cache   = $cache;
    }

    // ── Public ────────────────────────────────────────────────────────

    /**
     * Returns every order created between $start and $end (inclusive).
     * Includes total_price and shipping_lines for filtering logic.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllOrders(string $startDate, string $endDate): array
    {
        $fetch = function () use ($startDate, $endDate): array {
            $all = [];

            $params = http_build_query([
                'status'         => 'any',
                'created_at_min' => $startDate . 'T00:00:00-00:00',
                'created_at_max' => $endDate   . 'T23:59:59-00:00',
                'limit'          => self::PAGE_SIZE,
                'fields'         => 'id,order_number,name,financial_status,fulfillment_status,cancelled_at,created_at,email,total_price,shipping_lines',
            ]);

            echo "  Fetching Shopify orders";

            $nextUrl = "{$this->baseUrl}/orders.json?{$params}";

            while ($nextUrl) {
                [$orders, $nextUrl] = $this->getPage($nextUrl);
                $all = array_merge($all, $orders);
                echo '.';
            }

            echo " done (" . count($all) . " orders)\n";
            return $all;
        };

        if ($this->cache) {
            return $this->cache->remember('shopify', "{$startDate}|{$endDate}", function () use ($fetch, $startDate, $endDate) {
                $orders = $fetch();
                echo "  [cache] Shopify orders stored ({$startDate} → {$endDate})\n";
                return $orders;
            });
        }

        return $fetch();
    }

    /**
     * Returns true if any fulfillment order for this Shopify order ID has
     * status 'on_hold'. Uses the Fulfillment Orders API (separate endpoint
     * from orders.json — hold state is not exposed on the order object itself).
     *
     * Results are cached per order ID to avoid redundant calls during
     * large historical audits.
     */
    public function isOnHold(string $orderId): bool
    {
        $check = function () use ($orderId): bool {
            $url  = "{$this->baseUrl}/orders/{$orderId}/fulfillment_orders.json";
            $data = $this->get($url);

            foreach ($data['fulfillment_orders'] ?? [] as $fo) {
                if (($fo['status'] ?? '') === 'on_hold') {
                    return true;
                }
            }
            return false;
        };

        if ($this->cache) {
            return $this->cache->remember('shopify_hold', $orderId, $check);
        }

        return $check();
    }

    // ── Private ───────────────────────────────────────────────────────

    /**
     * Single GET request — returns decoded JSON body as array.
     * Handles rate limiting (HTTP 429) with automatic retry.
     *
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw        = curl_exec($ch);
        $code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err        = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($err) throw new RuntimeException("Shopify cURL error: {$err}");

        if ($code === 429) {
            $headers    = substr($raw, 0, $headerSize);
            $retryAfter = 10;
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $retryAfter = (int) $m[1];
            }
            echo "\n  [Shopify] Rate limited — waiting {$retryAfter}s ...\n";
            sleep($retryAfter);
            return $this->get($url);
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Shopify API error {$code}: " . substr($raw, $headerSize));
        }

        $body    = substr($raw, $headerSize);
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: string|null} */
    private function getPage(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw        = curl_exec($ch);
        $code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err        = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($err) throw new RuntimeException("Shopify cURL error: {$err}");

        if ($code === 429) {
            $headers    = substr($raw, 0, $headerSize);
            $retryAfter = 10;
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $retryAfter = (int) $m[1];
            }
            echo "\n  [Shopify] Rate limited — waiting {$retryAfter}s ...\n";
            sleep($retryAfter);
            return $this->getPage($url);
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("Shopify API error {$code}: " . substr($raw, $headerSize));
        }

        $headers = substr($raw, 0, $headerSize);
        $body    = substr($raw, $headerSize);
        $decoded = json_decode($body, true);

        if (!isset($decoded['orders'])) {
            throw new RuntimeException("Shopify unexpected response: {$body}");
        }

        $nextUrl = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/i', $headers, $m)) {
            $nextUrl = $m[1];
        }

        return [$decoded['orders'], $nextUrl];
    }
}
