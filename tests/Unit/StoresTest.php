<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Stores.php';

use PHPUnit\Framework\TestCase;

class StoresTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/stores_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // Start with no stores.json and reset static state
        Stores::init($this->tmpDir);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);

        $_SESSION = [];
    }

    public function testIsMultiStoreReturnsFalseWhenNoFile(): void
    {
        $this->assertFalse(Stores::isMultiStore());
    }

    public function testIsMultiStoreReturnsTrueWhenFileExists(): void
    {
        file_put_contents($this->tmpDir . '/stores.json', '[]');
        Stores::init($this->tmpDir);

        $this->assertTrue(Stores::isMultiStore());
    }

    public function testAllReturnsEmptyArrayWhenNoFile(): void
    {
        $this->assertSame([], Stores::all());
    }

    public function testAllReturnsParsedArrayWhenFileExists(): void
    {
        $stores = [
            ['id' => 'store-a', 'name' => 'Store A'],
            ['id' => 'store-b', 'name' => 'Store B'],
        ];
        file_put_contents($this->tmpDir . '/stores.json', json_encode($stores));
        Stores::init($this->tmpDir);

        $this->assertSame($stores, Stores::all());
    }

    public function testGetActiveReturnsFirstStoreWhenNoSessionMatch(): void
    {
        $stores = [
            ['id' => 'store-a', 'name' => 'Store A'],
            ['id' => 'store-b', 'name' => 'Store B'],
        ];
        file_put_contents($this->tmpDir . '/stores.json', json_encode($stores));
        Stores::init($this->tmpDir);

        // No store_id in session — should fall back to first store
        $active = Stores::getActive();
        $this->assertSame('store-a', $active['id']);
    }

    public function testGetActiveReturnsMatchingStoreFromSession(): void
    {
        $stores = [
            ['id' => 'store-a', 'name' => 'Store A'],
            ['id' => 'store-b', 'name' => 'Store B'],
        ];
        file_put_contents($this->tmpDir . '/stores.json', json_encode($stores));
        Stores::init($this->tmpDir);

        $_SESSION['store_id'] = 'store-b';
        $active = Stores::getActive();
        $this->assertSame('store-b', $active['id']);
    }

    public function testSetActiveSetsSessionStoreId(): void
    {
        Stores::setActive('store-x');
        $this->assertSame('store-x', $_SESSION['store_id']);
    }
}
