<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/OrderAnomalyPageLoader.php';

/**
 * Tests for OrderAnomalyPageLoader::buildRepeatRefundRows() via reflection
 * (private method). See "Repeat Refunds" gap in
 * docs/audit-test-coverage-gaps.md.
 */
class RepeatRefundsTest extends TestCase
{
    private static \ReflectionMethod $method;

    public static function setUpBeforeClass(): void
    {
        $ref = new \ReflectionClass(OrderAnomalyPageLoader::class);
        self::$method = $ref->getMethod('buildRepeatRefundRows');
    }

    private function refundedOrder(string $email, array $transactions, array $overrides = []): array
    {
        return array_merge([
            'id'         => 1,
            'name'       => '#1001',
            'email'      => $email,
            'created_at' => '2026-06-01T10:00:00Z',
            'refunds'    => [['transactions' => $transactions]],
        ], $overrides);
    }

    private function successfulRefund(float $amount): array
    {
        return ['kind' => 'refund', 'status' => 'success', 'amount' => (string) $amount];
    }

    private function build(array $orders, int $minCount): array
    {
        return self::$method->invoke(null, $orders, $minCount);
    }

    // ── min_count boundary ───────────────────────────────────────────────────

    public function testExactlyMinCountRefundsIsFlagged(): void
    {
        $orders = [
            $this->refundedOrder('a@b.com', [$this->successfulRefund(10)], ['name' => '#1']),
            $this->refundedOrder('a@b.com', [$this->successfulRefund(10)], ['name' => '#2']),
        ];

        $rows = $this->build($orders, 2);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['refund_count']);
    }

    public function testBelowMinCountIsNotFlagged(): void
    {
        $orders = [
            $this->refundedOrder('a@b.com', [$this->successfulRefund(10)], ['name' => '#1']),
        ];

        $rows = $this->build($orders, 2);

        $this->assertSame([], $rows);
    }

    // ── refunded_amt only sums successful refund transactions ──────────────────

    public function testFailedRefundTransactionExcludedFromTotal(): void
    {
        $orders = [
            $this->refundedOrder('a@b.com', [
                $this->successfulRefund(10),
                ['kind' => 'refund', 'status' => 'failure', 'amount' => '999'],
            ], ['name' => '#1']),
            $this->refundedOrder('a@b.com', [$this->successfulRefund(20)], ['name' => '#2']),
        ];

        $rows = $this->build($orders, 2);

        $this->assertCount(1, $rows);
        $this->assertSame(30.0, $rows[0]['total_refunded']);
    }

    public function testPendingRefundTransactionExcludedFromTotal(): void
    {
        $orders = [
            $this->refundedOrder('a@b.com', [
                ['kind' => 'refund', 'status' => 'pending', 'amount' => '999'],
            ], ['name' => '#1']),
            $this->refundedOrder('a@b.com', [$this->successfulRefund(15)], ['name' => '#2']),
        ];

        $rows = $this->build($orders, 2);

        $this->assertSame(15.0, $rows[0]['total_refunded']);
    }

    public function testNonRefundKindTransactionExcludedFromTotal(): void
    {
        $orders = [
            $this->refundedOrder('a@b.com', [
                ['kind' => 'sale', 'status' => 'success', 'amount' => '999'],
                $this->successfulRefund(5),
            ], ['name' => '#1']),
            $this->refundedOrder('a@b.com', [$this->successfulRefund(5)], ['name' => '#2']),
        ];

        $rows = $this->build($orders, 2);

        $this->assertSame(10.0, $rows[0]['total_refunded']);
    }

    // ── grouping / misc ──────────────────────────────────────────────────────

    public function testOrdersWithoutEmailAreIgnored(): void
    {
        $orders = [
            $this->refundedOrder('', [$this->successfulRefund(10)], ['name' => '#1']),
            $this->refundedOrder('', [$this->successfulRefund(10)], ['name' => '#2']),
        ];

        $rows = $this->build($orders, 2);

        $this->assertSame([], $rows);
    }

    public function testDifferentEmailsAreNotGroupedTogether(): void
    {
        $orders = [
            $this->refundedOrder('a@b.com', [$this->successfulRefund(10)], ['name' => '#1']),
            $this->refundedOrder('c@d.com', [$this->successfulRefund(10)], ['name' => '#2']),
        ];

        $rows = $this->build($orders, 2);

        $this->assertSame([], $rows);
    }

    public function testRowsSortedByRefundCountDescending(): void
    {
        $orders = [
            $this->refundedOrder('a@b.com', [$this->successfulRefund(10)], ['name' => '#1']),
            $this->refundedOrder('a@b.com', [$this->successfulRefund(10)], ['name' => '#2']),
            $this->refundedOrder('c@d.com', [$this->successfulRefund(10)], ['name' => '#3']),
            $this->refundedOrder('c@d.com', [$this->successfulRefund(10)], ['name' => '#4']),
            $this->refundedOrder('c@d.com', [$this->successfulRefund(10)], ['name' => '#5']),
        ];

        $rows = $this->build($orders, 2);

        $this->assertSame(['c@d.com', 'a@b.com'], array_column($rows, 'email'));
    }
}
