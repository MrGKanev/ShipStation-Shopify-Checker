<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Actions.php - the 504-line POST action dispatcher (0 tests
 * before this). Every handler ends in header()+exit(), which makes the
 * dispatch() entry point itself unsafe to call directly from PHPUnit (exit
 * would kill the test process). Instead, the security/data-integrity-critical
 * logic behind ignore_order bulk actions, add_user validation, and the
 * csvDownload path-traversal guard was extracted into pure public static
 * methods and is tested directly here. redirectBack() and connectionResults()'
 * no-credentials branches were already callable without exit().
 */
class ActionsTest extends TestCase
{
    // ── buildBulkIgnoreEntries ───────────────────────────────────────────────

    public function testBuildBulkIgnoreEntriesNormalisesOrderNumbers(): void
    {
        $entries = Actions::buildBulkIgnoreEntries(['#1001', '1002-B2'], 'holiday cleanup');

        $this->assertSame(
            [['number' => '1001', 'reason' => 'holiday cleanup'], ['number' => '10022', 'reason' => 'holiday cleanup']],
            $entries
        );
    }

    public function testBuildBulkIgnoreEntriesDropsBlankNormalisedNumbers(): void
    {
        $entries = Actions::buildBulkIgnoreEntries(['', 'abc', '1001'], 'x');

        $this->assertSame(['1001'], array_column($entries, 'number'));
    }

    // ── normaliseOrderNumbers ────────────────────────────────────────────────

    public function testNormaliseOrderNumbersFiltersBlanks(): void
    {
        $this->assertSame(['1001', '1002'], Actions::normaliseOrderNumbers(['#1001', '', 'abc', '1002']));
    }

    // ── validateNewUser ──────────────────────────────────────────────────────

    private function existingUser(string $name): array
    {
        return ['name' => $name, 'password_hash' => 'x', 'role' => 'viewer'];
    }

    public function testValidateNewUserRejectsInvalidRole(): void
    {
        $err = Actions::validateNewUser([], 'jane', 'secret123', 'superadmin');
        $this->assertSame('Invalid role.', $err);
    }

    public function testValidateNewUserRejectsBlankUsername(): void
    {
        $err = Actions::validateNewUser([], '', 'secret123', 'viewer');
        $this->assertSame('Username and password are required.', $err);
    }

    public function testValidateNewUserRejectsBlankPassword(): void
    {
        $err = Actions::validateNewUser([], 'jane', '', 'viewer');
        $this->assertSame('Username and password are required.', $err);
    }

    public function testValidateNewUserRejectsDuplicateUsername(): void
    {
        $err = Actions::validateNewUser([$this->existingUser('jane')], 'jane', 'secret123', 'operator');
        $this->assertSame('A user with that username already exists.', $err);
    }

    public function testValidateNewUserAcceptsValidSubmission(): void
    {
        $err = Actions::validateNewUser([$this->existingUser('bob')], 'jane', 'secret123', 'admin');
        $this->assertNull($err);
    }

    public function testValidateNewUserAcceptsEachAllowedRole(): void
    {
        foreach (['viewer', 'operator', 'admin'] as $role) {
            $this->assertNull(Actions::validateNewUser([], 'jane', 'secret123', $role), "role '{$role}' should be accepted");
        }
    }

    // ── isValidReportDate ────────────────────────────────────────────────────

    public function testIsValidReportDateAcceptsWellFormedDate(): void
    {
        $this->assertTrue(Actions::isValidReportDate('2026-06-20'));
    }

    public function testIsValidReportDateRejectsPathTraversal(): void
    {
        $this->assertFalse(Actions::isValidReportDate('../../../etc/passwd'));
        $this->assertFalse(Actions::isValidReportDate('2026-01-01/../../secret'));
    }

    public function testIsValidReportDateRejectsMalformedStrings(): void
    {
        $this->assertFalse(Actions::isValidReportDate('2026-6-20'));
        $this->assertFalse(Actions::isValidReportDate('20260620'));
        $this->assertFalse(Actions::isValidReportDate(''));
        $this->assertFalse(Actions::isValidReportDate('2026-06-20-extra'));
    }

    // ── redirectBack (already public, no exit involved) ─────────────────────

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testRedirectBackUsesDefaultPageWhenNotSubmitted(): void
    {
        $_POST = [];
        $this->assertSame('?page=reports', Actions::redirectBack());
        $this->assertSame('?page=run', Actions::redirectBack('run'));
    }

    public function testRedirectBackUsesSubmittedPageAndDate(): void
    {
        $_POST = ['redirect_page' => 'orphans', 'redirect_date' => '2026-06-20'];
        $this->assertSame('?page=orphans&date=2026-06-20', Actions::redirectBack());
    }

    public function testRedirectBackUrlEncodesValues(): void
    {
        $_POST = ['redirect_page' => 'a b', 'redirect_date' => ''];
        $this->assertSame('?page=a+b', Actions::redirectBack());
    }

    // ── connectionResults: no-credentials branches (no network calls) ───────

    private function invokeConnectionResults(array $ctx): array
    {
        $ref = new \ReflectionClass(Actions::class);
        $method = $ref->getMethod('connectionResults');
        return $method->invoke(null, $ctx);
    }

    public function testConnectionResultsReportsMissingShipStationCredentials(): void
    {
        $results = $this->invokeConnectionResults(['ssKey' => '', 'ssSecret' => '', 'shopifyToken' => '', 'shopifyStore' => 'N/A']);

        $this->assertFalse($results['ss']['ok']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env', $results['ss']['error']);
    }

    public function testConnectionResultsReportsMissingShopifyCredentials(): void
    {
        $results = $this->invokeConnectionResults(['ssKey' => '', 'ssSecret' => '', 'shopifyToken' => '', 'shopifyStore' => 'N/A']);

        $this->assertFalse($results['shopify']['ok']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env', $results['shopify']['error']);
    }

    // ── performPush / buildPushPreview ──────────────────────────────────────

    private function shopifyStack(array $responses, array &$history = []): \GuzzleHttp\HandlerStack
    {
        $mock  = new \GuzzleHttp\Handler\MockHandler($responses);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        return $stack;
    }

    private function shopifyJson(array $data): \GuzzleHttp\Psr7\Response
    {
        return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($data));
    }

    private function shopifyOrderNodeResponse(?array $order): \GuzzleHttp\Psr7\Response
    {
        return $this->shopifyJson(['data' => ['order' => $order]]);
    }

    private function shopify(array $responses): Shopify
    {
        return new Shopify('test.myshopify.com', 'tok_test', null, $this->shopifyStack($responses));
    }

    private function orderNode(): array
    {
        return [
            'id' => 'gid://shopify/Order/1', 'legacyResourceId' => '1', 'name' => '#1001',
            'createdAt' => '2026-06-01T10:00:00Z', 'cancelledAt' => null,
            'email' => 'jane@example.com', 'note' => '', 'tags' => [],
            'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'USD']],
            'totalTaxSet' => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'USD']],
            'shippingAddress' => null, 'billingAddress' => null,
            'lineItems' => ['nodes' => []], 'shippingLines' => ['nodes' => []],
        ];
    }

    public function testPerformPushThrowsWhenShopifyOrderNotFound(): void
    {
        $shopify = $this->shopify([$this->shopifyOrderNodeResponse(null)]);
        $ss      = new ShipStation('key', 'secret', null, $this->shopifyStack([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Order 999 not found in Shopify.');

        Actions::performPush($shopify, $ss, '999');
    }

    public function testPerformPushReturnsOrderNumberFromShipStation(): void
    {
        $shopify = $this->shopify([$this->shopifyOrderNodeResponse($this->orderNode())]);
        $ss      = new ShipStation('key', 'secret', null, $this->shopifyStack([
            $this->shopifyJson(['orderId' => 555, 'orderNumber' => '1001']),
        ]));

        $result = Actions::performPush($shopify, $ss, '1');

        $this->assertSame('1001', $result['order_number']);
        $this->assertSame('1', $result['shopify_id']);
        $this->assertSame(555, $result['ss_order_id']);
    }

    public function testPerformPushFallsBackToShopifyIdWhenShipStationOmitsOrderNumber(): void
    {
        $shopify = $this->shopify([$this->shopifyOrderNodeResponse($this->orderNode())]);
        $ss      = new ShipStation('key', 'secret', null, $this->shopifyStack([
            $this->shopifyJson(['orderId' => 555]),
        ]));

        $result = Actions::performPush($shopify, $ss, '1');

        $this->assertSame('1', $result['order_number']);
    }

    public function testBuildPushPreviewThrowsWhenShopifyOrderNotFound(): void
    {
        $shopify = $this->shopify([$this->shopifyOrderNodeResponse(null)]);
        $ss      = new ShipStation('preview', 'preview');

        $this->expectException(RuntimeException::class);
        Actions::buildPushPreview($shopify, $ss, '999');
    }

    public function testBuildPushPreviewReturnsShipStationPayloadWithoutCreatingAnOrder(): void
    {
        $history = [];
        $shopify = $this->shopify([$this->shopifyOrderNodeResponse($this->orderNode())]);
        $ss      = new ShipStation('preview', 'preview', null, $this->shopifyStack([], $history));

        $payload = Actions::buildPushPreview($shopify, $ss, '1');

        $this->assertSame('1001', $payload['orderNumber']);
        $this->assertSame([], $history, 'preview must not make any ShipStation HTTP calls');
    }

    // ── validateSaveOrderNoteRequest ─────────────────────────────────────────

    public function testValidateSaveOrderNoteRequestRejectsMissingOrderId(): void
    {
        $err = Actions::validateSaveOrderNoteRequest('', ['shopifyToken' => 't', 'shopifyStore' => 'x']);
        $this->assertSame('Missing order ID.', $err);
    }

    public function testValidateSaveOrderNoteRequestRejectsMissingCredentials(): void
    {
        $err = Actions::validateSaveOrderNoteRequest('123', ['shopifyToken' => '', 'shopifyStore' => 'x']);
        $this->assertSame('Shopify credentials not configured.', $err);
    }

    public function testValidateSaveOrderNoteRequestRejectsPlaceholderStore(): void
    {
        $err = Actions::validateSaveOrderNoteRequest('123', ['shopifyToken' => 't', 'shopifyStore' => 'N/A']);
        $this->assertSame('Shopify credentials not configured.', $err);
    }

    public function testValidateSaveOrderNoteRequestAcceptsValidRequest(): void
    {
        $err = Actions::validateSaveOrderNoteRequest('123', ['shopifyToken' => 't', 'shopifyStore' => 'x.myshopify.com']);
        $this->assertNull($err);
    }
}
