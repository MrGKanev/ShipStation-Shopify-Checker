<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/PackingSlipPageLoader.php';

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class PackingSlipPageLoaderTest extends TestCase
{
    private array $previousGet;
    private array $previousPost;

    protected function setUp(): void
    {
        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    public function testInitialStateUsesOrderPrefillWhenCredentialsExist(): void
    {
        $_GET['order'] = '#1001';

        $data = PackingSlipPageLoader::load('', $this->ctx(['ssKey' => 'key', 'ssSecret' => 'secret']));

        $this->assertNull($data['slipOrder']);
        $this->assertSame('#1001', $data['slipInput']);
        $this->assertSame('', $data['slipError']);
    }

    public function testMissingCredentialsReturnErrorBeforeLookup(): void
    {
        $_GET['order'] = '#1001';
        $_POST['order_number'] = '#2002';

        $data = PackingSlipPageLoader::load('packingslip', $this->ctx());

        $this->assertNull($data['slipOrder']);
        $this->assertSame('#1001', $data['slipInput']);
        $this->assertSame('SS_API_KEY / SS_API_SECRET not set in .env.', $data['slipError']);
    }

    public function testSubmittedBlankOrderShowsValidationError(): void
    {
        $_POST['order_number'] = '  #  ';

        $data = PackingSlipPageLoader::load('packingslip', $this->ctx(['ssKey' => 'key', 'ssSecret' => 'secret']));

        $this->assertNull($data['slipOrder']);
        $this->assertSame('#', $data['slipInput']);
        $this->assertSame('Enter an order number.', $data['slipError']);
    }

    public function testSurfacesErrorWhenShipStationThrows(): void
    {
        $_POST['order_number'] = '#1001';
        $stack = HandlerStack::create(new MockHandler([new Response(500, [], 'Internal Server Error')]));

        $data = PackingSlipPageLoader::load('packingslip', $this->ctx(['ssKey' => 'key', 'ssSecret' => 'secret', 'httpStack' => $stack]));

        $this->assertNull($data['slipOrder']);
        $this->assertStringContainsString('Error:', $data['slipError']);
    }

    public function testSuccessReturnsFirstMatchingOrder(): void
    {
        $_POST['order_number'] = '#1001';
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'orders' => [['orderId' => 5, 'orderNumber' => '1001']],
            ])),
        ]));

        $data = PackingSlipPageLoader::load('packingslip', $this->ctx(['ssKey' => 'key', 'ssSecret' => 'secret', 'httpStack' => $stack]));

        $this->assertSame('', $data['slipError']);
        $this->assertSame(5, $data['slipOrder']['orderId']);
    }

    public function testSurfacesErrorWhenOrderNotFoundInShipStation(): void
    {
        $_POST['order_number'] = '#9999';
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['orders' => []])),
        ]));

        $data = PackingSlipPageLoader::load('packingslip', $this->ctx(['ssKey' => 'key', 'ssSecret' => 'secret', 'httpStack' => $stack]));

        $this->assertNull($data['slipOrder']);
        $this->assertSame('Order #9999 not found in ShipStation.', $data['slipError']);
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'ssKey'    => '',
            'ssSecret' => '',
        ];
    }
}
