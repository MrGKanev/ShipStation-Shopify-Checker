<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ApiHealth.php';

use PHPUnit\Framework\TestCase;

class ApiHealthTest extends TestCase
{
    public function testCheckShopifyUsesGraphQLForShopAndScopes(): void
    {
        $requests = [];
        $result = ApiHealth::checkShopify('example.myshopify.com', 'tok_test', function (string $url, array $headers, ?array $jsonBody = null) use (&$requests): array {
            $requests[] = compact('url', 'headers', 'jsonBody');

            return [
                'ok' => true,
                'code' => 200,
                'ms' => 12,
                'error' => '',
                'headers' => ['x-shopify-api-version' => Shopify::API_VERSION],
                'json' => [
                    'data' => [
                        'shop' => ['name' => 'Example Shop'],
                        'currentAppInstallation' => [
                            'accessScopes' => [
                                ['handle' => 'read_orders'],
                                ['handle' => 'read_fulfillments'],
                            ],
                        ],
                    ],
                ],
            ];
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('Example Shop', $result['shop_name']);
        $this->assertSame(Shopify::API_VERSION, $result['returned_version']);
        $this->assertSame(['read_orders', 'read_fulfillments'], $result['scopes']);
        $this->assertSame([], $result['missing_scopes']);
        $this->assertArrayHasKey('graphql', $result['checks']);

        $this->assertCount(1, $requests);
        $this->assertSame(
            'https://example.myshopify.com/admin/api/' . Shopify::API_VERSION . '/graphql.json',
            $requests[0]['url']
        );
        $this->assertContains('X-Shopify-Access-Token: tok_test', $requests[0]['headers']);
        $this->assertStringContainsString('shop { name }', $requests[0]['jsonBody']['query']);
        $this->assertStringContainsString('currentAppInstallation', $requests[0]['jsonBody']['query']);
        $this->assertStringContainsString('accessScopes { handle }', $requests[0]['jsonBody']['query']);
    }

    public function testCheckShopifyReportsMissingScopesFromGraphQLScopes(): void
    {
        $result = ApiHealth::checkShopify('example.myshopify.com', 'tok_test', fn() => [
            'ok' => true,
            'code' => 200,
            'ms' => 10,
            'error' => '',
            'headers' => ['x-shopify-api-version' => Shopify::API_VERSION],
            'json' => [
                'data' => [
                    'shop' => ['name' => 'Example Shop'],
                    'currentAppInstallation' => [
                        'accessScopes' => [
                            ['handle' => 'read_orders'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(['read_fulfillments'], $result['missing_scopes']);
    }

    public function testCheckShopifyReturnsUnconfiguredWhenTokenMissing(): void
    {
        $result = ApiHealth::checkShopify('example.myshopify.com', '', fn() => $this->fail('should not make a request'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SHOPIFY_ACCESS_TOKEN', $result['error']);
        $this->assertSame([], $result['checks']);
    }

    public function testCheckShopifyReturnsUnconfiguredWhenStoreIsNA(): void
    {
        $result = ApiHealth::checkShopify('N/A', 'tok_test', fn() => $this->fail('should not make a request'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SHOPIFY_STORE', $result['error']);
    }

    public function testCheckShopifyMarksNotOkWhenGraphQLReturnsErrors(): void
    {
        $result = ApiHealth::checkShopify('example.myshopify.com', 'tok_test', fn() => [
            'ok' => true,
            'code' => 200,
            'ms' => 10,
            'error' => '',
            'headers' => [],
            'json' => [
                'errors' => [['message' => 'Access denied']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['checks']['graphql']['ok']);
        $this->assertStringContainsString('Access denied', $result['checks']['graphql']['error']);
        $this->assertSame([], $result['missing_scopes']);
    }

    public function testCheckShipStationReturnsUnconfiguredWhenCredentialsMissing(): void
    {
        $result = ApiHealth::checkShipStation('', '', fn() => $this->fail('should not make a request'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SS_API_KEY', $result['error']);
        $this->assertSame([], $result['checks']);
    }

    public function testCheckShipStationSendsBasicAuthAndReportsSuccess(): void
    {
        $requests = [];
        $result = ApiHealth::checkShipStation('key123', 'secret456', function (string $url, array $headers) use (&$requests): array {
            $requests[] = compact('url', 'headers');
            return ['ok' => true, 'code' => 200, 'ms' => 5, 'error' => '', 'headers' => [], 'json' => ['orders' => []]];
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['error']);
        $this->assertCount(1, $requests);
        $this->assertSame('https://ssapi.shipstation.com/orders?pageSize=1', $requests[0]['url']);
        $this->assertContains('Authorization: Basic ' . base64_encode('key123:secret456'), $requests[0]['headers']);
    }

    public function testCheckShipStationReportsFailureFromRequest(): void
    {
        $result = ApiHealth::checkShipStation('key123', 'secret456', fn() => [
            'ok' => false, 'code' => 401, 'ms' => 5, 'error' => 'Unauthorized', 'headers' => [], 'json' => [],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('Unauthorized', $result['error']);
        $this->assertFalse($result['checks']['orders']['ok']);
    }
}
