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

    public function __construct(string $store, string $accessToken)
    {
        // Accept either "mystore" or "mystore.myshopify.com"
        $host = str_contains($store, '.') ? $store : "{$store}.myshopify.com";
        $this->baseUrl = "https://{$host}/admin/api/2024-01";
        $this->token   = $accessToken;
    }

    // ── Public ────────────────────────────────────────────────────────

    /**
     * Returns every order created between $start and $end (inclusive).
     * Cancelled orders are included so we can detect them and skip them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllOrders(string $startDate, string $endDate): array
    {
        $all = [];

        $params = http_build_query([
            'status'           => 'any',
            'created_at_min'   => $startDate . 'T00:00:00-00:00',
            'created_at_max'   => $endDate   . 'T23:59:59-00:00',
            'limit'            => self::PAGE_SIZE,
            'fields'           => 'id,order_number,name,financial_status,fulfillment_status,cancelled_at,created_at,email',
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
    }

    // ── Private ───────────────────────────────────────────────────────

    /**
     * Fetch one page; return [orders[], nextPageUrl|null].
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string|null}
     */
    private function getPage(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,           // need Link header
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$this->token}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Shopify cURL error: {$err}");
        }

        // Shopify rate-limits: 429 with Retry-After header
        if ($code === 429) {
            $headers    = substr($raw, 0, $headerSize);
            $retryAfter = 10;
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $retryAfter = (int) $m[1];
            }
            echo "\n  [Shopify] Rate limited — waiting {$retryAfter}s ...\n";
            sleep($retryAfter);
            return $this->getPage($url); // one retry
        }

        if ($code < 200 || $code >= 300) {
            $body = substr($raw, $headerSize);
            throw new RuntimeException("Shopify API error {$code}: {$body}");
        }

        $headers = substr($raw, 0, $headerSize);
        $body    = substr($raw, $headerSize);

        $decoded = json_decode($body, true);
        if (!isset($decoded['orders'])) {
            throw new RuntimeException("Shopify unexpected response: {$body}");
        }

        // Follow cursor pagination via Link: <url>; rel="next"
        $nextUrl = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/i', $headers, $m)) {
            $nextUrl = $m[1];
        }

        return [$decoded['orders'], $nextUrl];
    }
}
