<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/Shopify.php';
require_once __DIR__ . '/../../src/GiftCardPageLoader.php';
require_once __DIR__ . '/support/TmpDir.php';

use PHPUnit\Framework\TestCase;

class GiftCardPageLoaderTest extends TestCase
{
    private string $tmpDir;
    private array $previousGet;
    private array $previousPost;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/giftcard_loader_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        RunLog::setDataDir($this->tmpDir);

        $this->previousGet = $_GET;
        $this->previousPost = $_POST;
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        TmpDir::remove($this->tmpDir);
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;
    }

    public function testInitialLoadHasNoResult(): void
    {
        $data = GiftCardPageLoader::load('giftcards', '', $this->ctx());

        $this->assertNull($data['gcResult']);
        $this->assertSame('', $data['gcError']);
        $this->assertSame(30, $data['gcDays']);
    }

    public function testMissingShopifyCredentials(): void
    {
        $_POST = ['gc_days' => '14'];

        $data = GiftCardPageLoader::load('giftcards', 'scan_giftcards', $this->ctx(['shopifyToken' => '', 'shopifyStore' => 'N/A']));

        $this->assertNull($data['gcResult']);
        $this->assertSame('SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.', $data['gcError']);
        $this->assertSame(14, $data['gcDays']);
        $this->assertSame('config_error', RunLog::all()[0]['status']);
    }

    public function testUnknownPageReturnsEmptyData(): void
    {
        $this->assertSame([], GiftCardPageLoader::load('unknown', '', $this->ctx()));
    }

    private function ctx(array $overrides = []): array
    {
        return $overrides + [
            'shopifyToken' => 'tok_test',
            'shopifyStore' => 'test.myshopify.com',
            'cacheObj'     => null,
        ];
    }
}
