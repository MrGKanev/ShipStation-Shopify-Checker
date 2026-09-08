<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\ZombieProductsAnalyzer;
use PHPUnit\Framework\TestCase;

class ZombieProductsAnalyzerTest extends TestCase
{
    public function test_reports_product_without_variants(): void
    {
        $rows = (new ZombieProductsAnalyzer)->analyze([$this->product([])]);

        $this->assertSame('no_variants', $rows[0]['reason']);
        $this->assertSame('No variants defined', $rows[0]['detail']);
        $this->assertNull($rows[0]['stock']);
    }

    public function test_reports_all_tracked_variants_at_zero_or_negative_stock(): void
    {
        $rows = (new ZombieProductsAnalyzer)->analyze([$this->product([$this->variant(0), $this->variant(-2)])]);

        $this->assertSame('zero_stock', $rows[0]['reason']);
        $this->assertSame('2 tracked variants, all at 0', $rows[0]['detail']);
        $this->assertSame(-2, $rows[0]['stock']);
    }

    public function test_uses_singular_detail_for_one_variant(): void
    {
        $rows = (new ZombieProductsAnalyzer)->analyze([$this->product([$this->variant(0)])]);

        $this->assertSame('1 tracked variant, all at 0', $rows[0]['detail']);
    }

    public function test_does_not_report_positive_stock_untracked_or_continue_only_products(): void
    {
        $rows = (new ZombieProductsAnalyzer)->analyze([
            $this->product([$this->variant(0), $this->variant(1)]),
            $this->product([$this->variant(0, false)]),
            $this->product([$this->variant(0, true, 'CONTINUE')]),
        ]);

        $this->assertSame([], $rows);
    }

    public function test_ignores_malformed_variants_and_rejects_unsafe_product_ids(): void
    {
        $rows = (new ZombieProductsAnalyzer)->analyze([['legacyResourceId' => 'javascript:1', 'title' => '<script>x</script>', 'vendor' => '<img>', 'productType' => '<b>', 'variants' => [null, $this->variant(0)]]]);

        $this->assertSame('', $rows[0]['id']);
        $this->assertSame('<script>x</script>', $rows[0]['title']);
    }

    private function product(array $variants): array
    {
        return ['legacyResourceId' => '42', 'title' => 'Widget', 'vendor' => 'Acme', 'productType' => 'Gadget', 'variants' => $variants];
    }

    private function variant(int $stock, bool $tracked = true, string $policy = 'DENY'): array
    {
        return ['inventoryQuantity' => $stock, 'inventoryPolicy' => $policy, 'inventoryItem' => ['tracked' => $tracked]];
    }
}
