<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends concise operational summaries to Slack via Incoming Webhooks.
 */
class SlackNotifier
{
    private readonly Client $http;

    public function __construct(
        private readonly string $webhookUrl,
        ?HandlerStack $stack = null
    ) {
        if (trim($webhookUrl) === '') {
            throw new InvalidArgumentException('Slack webhook URL is empty.');
        }

        $this->http = new Client([
            'handler' => $stack ?? HandlerStack::create(),
            'timeout' => 8,
        ]);
    }

    public static function isConfigured(): bool
    {
        return trim((string) getenv('SLACK_WEBHOOK_URL')) !== '';
    }

    public static function fromEnvironment(?HandlerStack $stack = null): ?self
    {
        $url = trim((string) getenv('SLACK_WEBHOOK_URL'));
        return $url !== '' ? new self($url, $stack) : null;
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyAudit(array $summary): void
    {
        $this->send(self::auditPayload($summary));
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyScan(array $summary): void
    {
        $this->send(self::scanPayload($summary));
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyAuditSafely(array $summary, ?LoggerInterface $logger = null): bool
    {
        try {
            $this->notifyAudit($summary);
            return true;
        } catch (Throwable $e) {
            $logger?->warning('Slack audit notification failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public static function auditPayload(array $summary): array
    {
        $store     = (string) ($summary['store'] ?? 'Unknown store');
        $start     = (string) ($summary['start'] ?? '');
        $end       = (string) ($summary['end'] ?? '');
        $missing   = (int) ($summary['missing_count'] ?? 0);
        $found     = (int) ($summary['found'] ?? 0);
        $skipped   = (int) ($summary['skipped'] ?? 0);
        $ignored   = (int) ($summary['ignored'] ?? 0);
        $totalSs   = (int) ($summary['total_ss'] ?? 0);
        $duration  = isset($summary['duration']) ? (float) $summary['duration'] : null;
        $orders    = array_slice((array) ($summary['missing_orders'] ?? []), 0, 10);
        $moreCount = max(0, $missing - count($orders));

        $statusText = $missing > 0
            ? "{$missing} missing order" . ($missing === 1 ? '' : 's')
            : 'No missing orders';

        $mentions = (string) ($summary['mentions'] ?? '');

        $fields = [
            ['type' => 'mrkdwn', 'text' => "*Store*\n{$store}"],
            ['type' => 'mrkdwn', 'text' => "*Period*\n{$start} -> {$end}"],
            ['type' => 'mrkdwn', 'text' => "*Missing*\n{$missing}"],
            ['type' => 'mrkdwn', 'text' => "*Matched*\n{$found}"],
            ['type' => 'mrkdwn', 'text' => "*Skipped*\n{$skipped}"],
            ['type' => 'mrkdwn', 'text' => "*Ignored*\n{$ignored}"],
            ['type' => 'mrkdwn', 'text' => "*ShipStation total*\n{$totalSs}"],
        ];

        if ($duration !== null) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Duration*\n{$duration}s"];
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => "Shopify Ops audit: {$statusText}"],
            ],
        ];
        if ($mentions !== '') {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => trim($mentions)]];
        }
        $blocks[] = ['type' => 'section', 'fields' => $fields];

        if ($orders !== []) {
            $lines = [];
            foreach ($orders as $order) {
                if (!is_array($order)) continue;
                $label = (string) ($order['name'] ?? $order['order_number'] ?? '?');
                $total = isset($order['total_price']) ? '$' . number_format((float) $order['total_price'], 2) : '';
                $lines[] = trim("- {$label} {$total}");
            }
            if ($moreCount > 0) {
                $lines[] = "- ...and {$moreCount} more";
            }
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)]];
        }

        return [
            'text'   => "{$mentions}Shopify Ops audit for {$store}: {$statusText}",
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public static function scanPayload(array $summary): array
    {
        $tool = (string)($summary['tool'] ?? 'scan');
        $rows = (int)($summary['rows_found'] ?? 0);
        $scanned = $summary['scanned'] ?? null;
        $start = (string)($summary['start'] ?? '');
        $end = (string)($summary['end'] ?? '');
        $mentions = (string) ($summary['mentions'] ?? '');

        $fields = [
            ['type' => 'mrkdwn', 'text' => "*Tool*\n{$tool}"],
            ['type' => 'mrkdwn', 'text' => "*Rows found*\n{$rows}"],
        ];
        if ($scanned !== null) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Scanned*\n{$scanned}"];
        }
        if ($start || $end) {
            $fields[] = ['type' => 'mrkdwn', 'text' => "*Period*\n{$start} -> {$end}"];
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => "Shopify Ops scan: {$tool}"],
            ],
        ];
        if ($mentions !== '') {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => trim($mentions)]];
        }
        $blocks[] = ['type' => 'section', 'fields' => $fields];

        return [
            'text' => "{$mentions}Shopify Ops scan {$tool}: {$rows} row" . ($rows === 1 ? '' : 's') . ' found',
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): void
    {
        $response = $this->http->request('POST', $this->webhookUrl, [
            'http_errors' => false,
            'headers'     => ['Content-Type' => 'application/json'],
            'json'        => $payload,
        ]);

        $this->assertOk($response);
    }

    private function assertOk(ResponseInterface $response): void
    {
        $code = $response->getStatusCode();
        if ($code < 200 || $code >= 300) {
            $raw = (string) $response->getBody();
            throw new RuntimeException("Slack webhook error {$code}: {$raw}");
        }
    }
}
