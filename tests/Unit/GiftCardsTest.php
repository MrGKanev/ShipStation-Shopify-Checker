<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/RunLog.php';
require_once __DIR__ . '/../../src/GiftCardPageLoader.php';

/**
 * Tests for GiftCardPageLoader::buildGiftCardRows() via reflection (private method).
 */
class GiftCardsTest extends TestCase
{
    private static \ReflectionMethod $method;
    private const NOW = 1780000000; // fixed reference instant

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(GiftCardPageLoader::class);
        self::$method = $ref->getMethod('buildGiftCardRows');
    }

    private function giftCard(array $overrides = []): array
    {
        return array_merge([
            'id'             => 'gid://shopify/GiftCard/1',
            'masked_code'    => '****1234',
            'balance'        => 25.0,
            'initial_value'  => 50.0,
            'currency'       => 'USD',
            'expires_on'     => null,
            'enabled'        => true,
            'created_at'     => '2026-01-01T00:00:00Z',
            'customer_email' => 'a@example.com',
        ], $overrides);
    }

    private function buildRows(array $giftCards, int $days = 30): array
    {
        return self::$method->invoke(null, $giftCards, $days, self::NOW);
    }

    public function testDisabledCardIsExcluded(): void
    {
        $rows = $this->buildRows([$this->giftCard(['enabled' => false, 'expires_on' => date('Y-m-d', self::NOW + 86400)])]);

        $this->assertSame([], $rows);
    }

    public function testZeroBalanceCardIsExcluded(): void
    {
        $rows = $this->buildRows([$this->giftCard(['balance' => 0])]);

        $this->assertSame([], $rows);
    }

    public function testCardExpiringWithinWindowIsFlagged(): void
    {
        $rows = $this->buildRows([$this->giftCard([
            'expires_on' => date('Y-m-d', self::NOW + (10 * 86400)),
        ])], 30);

        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('Expiring in', $rows[0]['reasons'][0]);
    }

    public function testCardExpiringOutsideWindowAndAlreadyPartiallyRedeemedIsExcluded(): void
    {
        $rows = $this->buildRows([$this->giftCard([
            'balance'     => 25.0,
            'expires_on'  => date('Y-m-d', self::NOW + (90 * 86400)),
        ])], 30);

        $this->assertSame([], $rows);
    }

    public function testExpiredCardIsFlaggedAsExpired(): void
    {
        $rows = $this->buildRows([$this->giftCard([
            'expires_on' => date('Y-m-d', self::NOW - (5 * 86400)),
        ])], 30);

        $this->assertSame('Expired', $rows[0]['reasons'][0]);
    }

    public function testNeverRedeemedCardIsFlagged(): void
    {
        $rows = $this->buildRows([$this->giftCard([
            'balance'       => 50.0,
            'initial_value' => 50.0,
        ])]);

        $this->assertSame(['Never redeemed'], $rows[0]['reasons']);
    }

    public function testCardWithBothReasonsListsBoth(): void
    {
        $rows = $this->buildRows([$this->giftCard([
            'balance'       => 50.0,
            'initial_value' => 50.0,
            'expires_on'    => date('Y-m-d', self::NOW + (5 * 86400)),
        ])], 30);

        $this->assertCount(2, $rows[0]['reasons']);
    }

    public function testSortedByBalanceDescending(): void
    {
        $rows = $this->buildRows([
            $this->giftCard(['masked_code' => '****1111', 'balance' => 10.0, 'initial_value' => 10.0]),
            $this->giftCard(['masked_code' => '****2222', 'balance' => 90.0, 'initial_value' => 90.0]),
        ]);

        $this->assertSame('****2222', $rows[0]['masked_code']);
        $this->assertSame('****1111', $rows[1]['masked_code']);
    }
}
