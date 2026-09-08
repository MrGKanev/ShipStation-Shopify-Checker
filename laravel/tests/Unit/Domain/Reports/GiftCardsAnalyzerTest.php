<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\GiftCardsAnalyzer;
use PHPUnit\Framework\TestCase;

class GiftCardsAnalyzerTest extends TestCase
{
    private const NOW = 1780000000;

    public function test_excludes_disabled_and_zero_balance_cards(): void
    {
        $rows = $this->analyze([$this->card(['enabled' => false, 'balance' => 50, 'initial_value' => 50]), $this->card(['balance' => 0, 'initial_value' => 0])]);

        $this->assertSame([], $rows);
    }

    public function test_flags_expiring_expired_and_never_redeemed_cards(): void
    {
        $rows = $this->analyze([
            $this->card(['masked_code' => '****1', 'balance' => 50, 'initial_value' => 50, 'expires_on' => date('Y-m-d', self::NOW + 5 * 86400)]),
            $this->card(['masked_code' => '****2', 'expires_on' => date('Y-m-d', self::NOW - 5 * 86400)]),
        ]);

        $this->assertCount(2, $rows[0]['reasons']);
        $this->assertStringStartsWith('Expiring in', $rows[0]['reasons'][0]);
        $this->assertSame('Never redeemed', $rows[0]['reasons'][1]);
        $this->assertSame('Expired', $rows[1]['reasons'][0]);
    }

    public function test_excludes_partially_redeemed_card_outside_window(): void
    {
        $this->assertSame([], $this->analyze([$this->card(['expires_on' => date('Y-m-d', self::NOW + 90 * 86400)])]));
    }

    public function test_sorts_by_balance_descending(): void
    {
        $rows = $this->analyze([$this->card(['masked_code' => '****1', 'balance' => 10, 'initial_value' => 10]), $this->card(['masked_code' => '****2', 'balance' => 90, 'initial_value' => 90])]);

        $this->assertSame(['****2', '****1'], array_column($rows, 'masked_code'));
    }

    public function test_malformed_scalar_values_are_normalized_safely(): void
    {
        $rows = $this->analyze([$this->card(['masked_code' => ['bad'], 'customer_email' => '<script>x</script>', 'created_at' => ['bad'], 'balance' => 5, 'initial_value' => 5])]);

        $this->assertSame('', $rows[0]['masked_code']);
        $this->assertSame('', $rows[0]['created_at']);
        $this->assertSame('<script>x</script>', $rows[0]['customer_email']);
    }

    /** @param list<array<string, mixed>> $cards */
    private function analyze(array $cards): array
    {
        return (new GiftCardsAnalyzer)->analyze($cards, 30, self::NOW);
    }

    private function card(array $overrides = []): array
    {
        return array_replace(['id' => 'gid://shopify/GiftCard/1', 'masked_code' => '****1234', 'balance' => 25.0, 'initial_value' => 50.0, 'currency' => 'USD', 'expires_on' => null, 'enabled' => true, 'created_at' => '2026-01-01T00:00:00Z', 'customer_email' => 'a@example.com'], $overrides);
    }
}
