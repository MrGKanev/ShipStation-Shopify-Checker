<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/SlackNotifier.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SlackNotifierTest extends TestCase
{
    private string|false $previousWebhook;

    protected function setUp(): void
    {
        $this->previousWebhook = getenv('SLACK_WEBHOOK_URL');
        putenv('SLACK_WEBHOOK_URL');
    }

    protected function tearDown(): void
    {
        if ($this->previousWebhook === false) {
            putenv('SLACK_WEBHOOK_URL');
        } else {
            putenv('SLACK_WEBHOOK_URL=' . $this->previousWebhook);
        }
    }

    // ── isConfigured / fromEnvironment ──────────────────────────────────────

    public function testIsConfiguredFalseWithoutEnvVar(): void
    {
        $this->assertFalse(SlackNotifier::isConfigured());
        $this->assertNull(SlackNotifier::fromEnvironment());
    }

    public function testIsConfiguredTrueWithEnvVar(): void
    {
        putenv('SLACK_WEBHOOK_URL=https://hooks.slack.test/services/x/y/z');

        $this->assertTrue(SlackNotifier::isConfigured());
        $this->assertInstanceOf(SlackNotifier::class, SlackNotifier::fromEnvironment());
    }

    public function testConstructorRejectsEmptyWebhookUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SlackNotifier('   ');
    }

    // ── notifyAuditSafely ────────────────────────────────────────────────────

    public function testNotifyAuditSafelyReturnsFalseOnFailureWithoutThrowing(): void
    {
        $mock     = new MockHandler([new Response(500, [], 'bad')]);
        $notifier = new SlackNotifier('https://hooks.slack.test/services/x/y/z', HandlerStack::create($mock));

        $this->assertFalse($notifier->notifyAuditSafely(['store' => 'x']));
    }

    public function testNotifyAuditSafelyReturnsTrueOnSuccess(): void
    {
        $mock     = new MockHandler([new Response(200, [], 'ok')]);
        $notifier = new SlackNotifier('https://hooks.slack.test/services/x/y/z', HandlerStack::create($mock));

        $this->assertTrue($notifier->notifyAuditSafely(['store' => 'x']));
    }

    // ── auditPayload ─────────────────────────────────────────────────────────

    public function testAuditPayloadWithNoMissingUsesSingularFreeWording(): void
    {
        $payload = SlackNotifier::auditPayload(['store' => 'x', 'missing_count' => 0]);

        $this->assertSame('Shopify Ops audit for x: No missing orders', $payload['text']);
    }

    public function testAuditPayloadListsUpToTenOrdersWithMoreCount(): void
    {
        $orders = [];
        for ($i = 1; $i <= 15; $i++) {
            $orders[] = ['name' => "#{$i}", 'total_price' => '10.00'];
        }

        $payload = SlackNotifier::auditPayload(['store' => 'x', 'missing_count' => 15, 'missing_orders' => $orders]);

        $ordersBlockText = $payload['blocks'][2]['text']['text'];
        $this->assertSame(10, substr_count($ordersBlockText, '- #'));
        $this->assertStringContainsString('and 5 more', $ordersBlockText);
    }

    public function testAuditPayloadOmitsOrdersBlockWhenNoMissingOrders(): void
    {
        $payload = SlackNotifier::auditPayload(['store' => 'x', 'missing_count' => 0, 'missing_orders' => []]);

        $this->assertCount(2, $payload['blocks']);
    }

    public function testAuditPayloadOmitsDurationFieldWhenAbsent(): void
    {
        $payload = SlackNotifier::auditPayload(['store' => 'x']);

        $fieldTexts = array_column($payload['blocks'][1]['fields'], 'text');
        $this->assertFalse((bool) array_filter($fieldTexts, fn($t) => str_contains($t, '*Duration*')));
    }

    // ── scanPayload ──────────────────────────────────────────────────────────

    public function testScanPayloadOmitsScannedAndPeriodWhenAbsent(): void
    {
        $payload = SlackNotifier::scanPayload(['tool' => 'x', 'rows_found' => 0]);

        $fieldTexts = array_column($payload['blocks'][1]['fields'], 'text');
        $this->assertFalse((bool) array_filter($fieldTexts, fn($t) => str_contains($t, '*Scanned*')));
        $this->assertFalse((bool) array_filter($fieldTexts, fn($t) => str_contains($t, '*Period*')));
    }

    public function testScanPayloadSingularRowWording(): void
    {
        $payload = SlackNotifier::scanPayload(['tool' => 'x', 'rows_found' => 1]);

        $this->assertSame('Shopify Ops scan x: 1 row found', $payload['text']);
    }

    public function testAuditPayloadIncludesSummaryFields(): void
    {
        $payload = SlackNotifier::auditPayload([
            'store'          => 'example.myshopify.com',
            'start'          => '2026-06-01',
            'end'            => '2026-06-19',
            'missing_count'  => 1,
            'missing_orders' => [['name' => '#1001', 'total_price' => '49.95']],
            'found'          => 20,
            'skipped'        => 3,
            'ignored'        => 2,
            'total_ss'       => 25,
            'duration'       => 4.2,
        ]);

        $this->assertSame('Shopify Ops audit for example.myshopify.com: 1 missing order', $payload['text']);
        $this->assertSame('blocks', array_key_last($payload));
        $this->assertStringContainsString('Shopify Ops audit: 1 missing order', $payload['blocks'][0]['text']['text']);
        $this->assertStringContainsString('#1001', $payload['blocks'][2]['text']['text']);
    }

    public function testSendPostsJsonPayload(): void
    {
        $history = [];
        $mock    = new MockHandler([new Response(200, [], 'ok')]);
        $stack   = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $notifier = new SlackNotifier('https://hooks.slack.test/services/x/y/z', $stack);
        $notifier->send(['text' => 'hello']);

        $this->assertCount(1, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Content-Type'));
        $this->assertSame(['text' => 'hello'], json_decode((string) $history[0]['request']->getBody(), true));
    }

    public function testScanPayloadIncludesRowsFound(): void
    {
        $payload = SlackNotifier::scanPayload([
            'tool' => 'scan_sla',
            'rows_found' => 3,
            'scanned' => 50,
            'start' => '2026-06-01',
            'end' => '2026-06-19',
        ]);

        $this->assertSame('Shopify Ops scan scan_sla: 3 rows found', $payload['text']);
        $this->assertStringContainsString('scan_sla', $payload['blocks'][0]['text']['text']);
    }

    public function testSendThrowsOnSlackError(): void
    {
        $mock     = new MockHandler([new Response(500, [], 'bad')]);
        $notifier = new SlackNotifier('https://hooks.slack.test/services/x/y/z', HandlerStack::create($mock));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Slack webhook error 500');

        $notifier->send(['text' => 'hello']);
    }
}
