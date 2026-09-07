<?php

namespace App\Application\Reports;

readonly class InventoryForecastResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public string $startDate, public string $endDate, public int $products, public int $variants, public int $orders, public array $rows, public int $critical, public int $warning, public int $productPages, public int $orderPages, public bool $productsTruncated, public bool $ordersTruncated) {}
}
