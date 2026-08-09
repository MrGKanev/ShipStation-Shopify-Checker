<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/DiscordNotifier.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DiscordNotifier - mirrors SlackNotifierTest.php/EmailNotifierTest.php
 * coverage, which DiscordNotifier lacked entirely despite being the same shape
 * (payload builders + webhook send).
 */
class DiscordNotifierTest extends TestCase
{
    private string|false $previousWebhook;

    protected function setUp(): void
    {
        $this->previousWebhook = getenv('DISCORD_WEBHOOK_URL');
        putenv('DISCORD_WEBHOOK_URL');
    }

    protected function tearDown(): void
    {
        if ($this->previousWebhook === false) {
            putenv('DISCORD_WEBHOOK_URL');
        } else {
            putenv('DISCORD_WEBHOOK_URL=' . $this->previousWebhook);
        }
    }

    // ── isConfigured / fromEnvironment ──────────────────────────────────────

    public function testIsConfiguredFalseWithoutEnvVar(): void
    {
        $this->assertFalse(DiscordNotifier::isConfigured());
        $this->assertNull(DiscordNotifier::fromEnvironment());
    }

    public function testIsConfiguredTrueWithEnvVar(): void
    {
        putenv('DISCORD_WEBHOOK_URL=https://discord.test/webhooks/1/abc');

        $this->assertTrue(DiscordNotifier::isConfigured());
        $this->assertInstanceOf(DiscordNotifier::class, DiscordNotifier::fromEnvironment());
    }

    public function testConstructorRejectsEmptyWebhookUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DiscordNotifier('   ');
    }

    // ── auditPayload ─────────────────────────────────────────────────────────

    public function testAuditPayloadWithMissingOrdersUsesRedColorAndPluralWording(): void
    {
        $payload = DiscordNotifier::auditPayload([
            'store' => 'example.myshopify.com', 'start' => '2026-06-01', 'end' => '2026-06-19',
            'missing_count' => 3, 'found' => 20, 'skipped' => 1, 'ignored' => 0, 'total_ss' => 24,
            'missing_orders' => [],
        ]);

        $this->assertSame('Shopify Ops audit for **example.myshopify.com**: 3 missing orders', $payload['content']);
        $this->assertSame(14427686, $payload['embeds'][0]['color']);
    }

    public function testAuditPayloadWithNoMissingUsesGreenColorAndSingularWording(): void
    {
        $payload = DiscordNotifier::auditPayload([
            'store' => 'x', 'missing_count' => 0, 'found' => 5, 'skipped' => 0, 'ignored' => 0, 'total_ss' => 5,
        ]);

        $this->assertSame('Shopify Ops audit for **x**: No missing orders', $payload['content']);
        $this->assertSame(1483594, $payload['embeds'][0]['color']);
    }

    public function testAuditPayloadSingularForExactlyOneMissing(): void
    {
        $payload = DiscordNotifier::auditPayload(['store' => 'x', 'missing_count' => 1]);

        $this->assertSame('Shopify Ops audit: 1 missing order', $payload['embeds'][0]['title']);
    }

    public function testAuditPayloadListsUpToTenOrdersWithMoreCount(): void
    {
        $orders = [];
        for ($i = 1; $i <= 15; $i++) {
            $orders[] = ['name' => "#{$i}", 'total_price' => '10.00'];
        }

        $payload = DiscordNotifier::auditPayload([
            'store' => 'x', 'missing_count' => 15, 'missing_orders' => $orders,
        ]);

        $description = $payload['embeds'][0]['description'];
        $this->assertSame(10, substr_count($description, '- #'));
        $this->assertStringContainsString('and 5 more', $description);
    }

    public function testAuditPayloadOmitsDescriptionWhenNoMissingOrders(): void
    {
        $payload = DiscordNotifier::auditPayload(['store' => 'x', 'missing_count' => 0, 'missing_orders' => []]);

        $this->assertArrayNotHasKey('description', $payload['embeds'][0]);
    }

    public function testAuditPayloadIncludesDurationFieldWhenPresent(): void
    {
        $payload = DiscordNotifier::auditPayload(['store' => 'x', 'duration' => 4.2]);

        $fieldNames = array_column($payload['embeds'][0]['fields'], 'name');
        $this->assertContains('Duration', $fieldNames);
    }

    public function testAuditPayloadOmitsDurationFieldWhenAbsent(): void
    {
        $payload = DiscordNotifier::auditPayload(['store' => 'x']);

        $fieldNames = array_column($payload['embeds'][0]['fields'], 'name');
        $this->assertNotContains('Duration', $fieldNames);
    }

    // ── scanPayload ──────────────────────────────────────────────────────────

    public function testScanPayloadPluralAndSingularWording(): void
    {
        $plural   = DiscordNotifier::scanPayload(['tool' => 'scan_sla', 'rows_found' => 3]);
        $singular = DiscordNotifier::scanPayload(['tool' => 'scan_sla', 'rows_found' => 1]);

        $this->assertSame('Shopify Ops scan **scan_sla**: 3 rows found', $plural['content']);
        $this->assertSame('Shopify Ops scan **scan_sla**: 1 row found', $singular['content']);
    }

    public function testScanPayloadOmitsScannedFieldWhenNull(): void
    {
        $payload = DiscordNotifier::scanPayload(['tool' => 'x', 'rows_found' => 0]);

        $fieldNames = array_column($payload['embeds'][0]['fields'], 'name');
        $this->assertNotContains('Scanned', $fieldNames);
    }

    public function testScanPayloadIncludesScannedFieldWhenPresent(): void
    {
        $payload = DiscordNotifier::scanPayload(['tool' => 'x', 'rows_found' => 0, 'scanned' => 42]);

        $fieldNames = array_column($payload['embeds'][0]['fields'], 'name');
        $this->assertContains('Scanned', $fieldNames);
    }

    // ── send ─────────────────────────────────────────────────────────────────

    public function testSendPostsJsonPayload(): void
    {
        $history = [];
        $mock    = new MockHandler([new Response(200, [], 'ok')]);
        $stack   = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $notifier = new DiscordNotifier('https://discord.test/webhooks/1/abc', $stack);
        $notifier->send(['content' => 'hello']);

        $this->assertCount(1, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Content-Type'));
        $this->assertSame(['content' => 'hello'], json_decode((string) $history[0]['request']->getBody(), true));
    }

    public function testSendThrowsOnDiscordError(): void
    {
        $mock     = new MockHandler([new Response(500, [], 'bad')]);
        $notifier = new DiscordNotifier('https://discord.test/webhooks/1/abc', HandlerStack::create($mock));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Discord webhook error 500');

        $notifier->send(['content' => 'hello']);
    }

    public function testNotifyAuditSafelyReturnsFalseOnFailureWithoutThrowing(): void
    {
        $mock     = new MockHandler([new Response(500, [], 'bad')]);
        $notifier = new DiscordNotifier('https://discord.test/webhooks/1/abc', HandlerStack::create($mock));

        $result = $notifier->notifyAuditSafely(['store' => 'x']);

        $this->assertFalse($result);
    }

    public function testNotifyAuditSafelyReturnsTrueOnSuccess(): void
    {
        $mock     = new MockHandler([new Response(200, [], 'ok')]);
        $notifier = new DiscordNotifier('https://discord.test/webhooks/1/abc', HandlerStack::create($mock));

        $result = $notifier->notifyAuditSafely(['store' => 'x']);

        $this->assertTrue($result);
    }
}
