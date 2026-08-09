<?php
declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\CustomDataLookups;

/**
 * Tests for CustomDataLookups::searchOrdersByMetafield() - the sample-value
 * capping and value-matching logic behind the "metafields" search feature,
 * previously untested.
 */
class CustomDataLookupsTest extends TestCase
{
    private function lookups(array $responses): CustomDataLookups
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $client = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        return new CustomDataLookups($client);
    }

    private function json(array $edges): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges'    => array_map(fn($n) => ['node' => $n], $edges),
            ]],
        ]));
    }

    private function orderNode(string $name, ?string $mfValue): array
    {
        return [
            'id' => 'gid://shopify/Order/1', 'legacyResourceId' => '1', 'name' => $name,
            'email' => 'jane@example.com', 'createdAt' => '2026-06-01T00:00:00Z',
            'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'USD']],
            'metafield' => $mfValue !== null ? ['value' => $mfValue, 'type' => 'single_line_text_field'] : null,
        ];
    }

    public function testEmptySearchValueMatchesAnyOrderWithTheMetafieldSet(): void
    {
        $lookups = $this->lookups([$this->json([
            $this->orderNode('#1', 'anything'),
            $this->orderNode('#2', null),
        ])]);

        $result = $lookups->searchOrdersByMetafield('custom', 'gift_note', '');

        $this->assertCount(1, $result['matches']);
        $this->assertSame('#1', $result['matches'][0]['name']);
    }

    public function testNonEmptySearchValueMatchesCaseInsensitiveSubstring(): void
    {
        $lookups = $this->lookups([$this->json([
            $this->orderNode('#1', 'Happy Birthday!'),
            $this->orderNode('#2', 'Congrats'),
        ])]);

        $result = $lookups->searchOrdersByMetafield('custom', 'gift_note', 'birthday');

        $this->assertCount(1, $result['matches']);
        $this->assertSame('#1', $result['matches'][0]['name']);
    }

    public function testScannedAndWithMfCountsAreCorrect(): void
    {
        $lookups = $this->lookups([$this->json([
            $this->orderNode('#1', 'x'),
            $this->orderNode('#2', null),
            $this->orderNode('#3', 'y'),
        ])]);

        $result = $lookups->searchOrdersByMetafield('custom', 'k', '');

        $this->assertSame(3, $result['scanned']);
        $this->assertSame(2, $result['with_mf']);
    }

    public function testSampleValuesCappedAtFiveUniqueValues(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 8; $i++) {
            $nodes[] = $this->orderNode("#{$i}", "value-{$i}");
        }
        $lookups = $this->lookups([$this->json($nodes)]);

        $result = $lookups->searchOrdersByMetafield('custom', 'k', '');

        $this->assertCount(5, $result['sample_values']);
    }

    public function testSampleValuesDeduplicated(): void
    {
        $lookups = $this->lookups([$this->json([
            $this->orderNode('#1', 'dup'),
            $this->orderNode('#2', 'dup'),
            $this->orderNode('#3', 'unique'),
        ])]);

        $result = $lookups->searchOrdersByMetafield('custom', 'k', '');

        $this->assertSame(['dup', 'unique'], $result['sample_values']);
    }

    public function testNamespaceAndKeyAreEscapedInQuery(): void
    {
        $history = [];
        $mock  = new MockHandler([$this->json([])]);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client  = new Client('https://test.myshopify.com/admin/api/2026-04', 'tok_test', $stack);
        $lookups = new CustomDataLookups($client);

        $lookups->searchOrdersByMetafield('a"b', 'c"d', '');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertStringContainsString('metafield(namespace: "a\"b", key: "c\"d")', $body['query']);
    }
}
