<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\DisputeLookup;

/**
 * Tests for DisputeLookup::fetchDisputes() - the default open-status
 * filter, pagination, and dispute normalization, previously untested (new
 * class backing the Chargebacks / Disputes Tracker).
 */
class DisputeLookupTest extends TestCase
{
    private array $history = [];

    private function lookup(array $responses): DisputeLookup
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client('https://test.myshopify.com/admin/api/2026-07', 'tok_test', $stack);
        return new DisputeLookup($client);
    }

    private function disputesPage(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): Response
    {
        return new Response(200, [], json_encode([
            'data' => ['disputes' => [
                'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                'edges'    => array_map(fn($n) => ['node' => $n], $nodes),
            ]],
        ]));
    }

    private function disputeNode(array $overrides = []): array
    {
        return array_replace_recursive([
            'id'               => 'gid://shopify/ShopifyPaymentsDispute/1',
            'legacyResourceId' => '1',
            'status'           => 'NEEDS_RESPONSE',
            'initiatedAt'      => '2026-06-01T00:00:00Z',
            'evidenceDueBy'    => '2026-06-10T00:00:00Z',
            'amount'           => ['amount' => '50.00', 'currencyCode' => 'USD'],
            'reasonDetails'    => ['reason' => 'FRAUDULENT', 'networkReasonCode' => '10.4'],
            'order'            => ['id' => 'gid://shopify/Order/1001', 'legacyResourceId' => '1001', 'name' => '#1001'],
        ], $overrides);
    }

    public function testDefaultFilterRequestsNeedsResponseOrUnderReview(): void
    {
        $lookup = $this->lookup([$this->disputesPage([])]);

        $lookup->fetchDisputes();

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('status:NEEDS_RESPONSE OR status:UNDER_REVIEW', $body['variables']['query']);
    }

    public function testNormalizesDisputeFields(): void
    {
        $lookup = $this->lookup([$this->disputesPage([$this->disputeNode()])]);

        $result = $lookup->fetchDisputes();

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('needs_response', $result[0]['status']);
        $this->assertSame('fraudulent', $result[0]['reason']);
        $this->assertSame('10.4', $result[0]['network_reason_code']);
        $this->assertSame('50.00', $result[0]['amount']);
        $this->assertSame('USD', $result[0]['currency']);
        $this->assertSame(1001, $result[0]['order_id']);
        $this->assertSame('#1001', $result[0]['order_name']);
    }

    public function testFollowsPaginationCursor(): void
    {
        $lookup = $this->lookup([
            $this->disputesPage([$this->disputeNode(['legacyResourceId' => '1'])], true, 'cursor-1'),
            $this->disputesPage([$this->disputeNode(['legacyResourceId' => '2'])], false, null),
        ]);

        $result = $lookup->fetchDisputes();

        $this->assertCount(2, $result);
        $secondBody = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('cursor-1', $secondBody['variables']['after']);
    }

    public function testHandlesDisputeWithoutOrder(): void
    {
        $lookup = $this->lookup([$this->disputesPage([$this->disputeNode(['order' => null])])]);

        $result = $lookup->fetchDisputes();

        $this->assertSame('', $result[0]['order_name']);
    }
}
