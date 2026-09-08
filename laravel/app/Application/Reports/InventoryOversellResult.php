<?php

namespace App\Application\Reports;

readonly class InventoryOversellResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(public int $products, public int $awaitingOrders, public array $rows, public int $productPages, public bool $productsTruncated) {}
}
