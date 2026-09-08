<?php

namespace Tests\Unit;

use App\Domain\Reports\RepeatRefundAnalyzer;
use Tests\TestCase;

class RepeatRefundAnalyzerTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_groups_successful_refunds_by_email_and_applies_minimum(): void
    {
        $orders = [['email' => 'A@X.com', 'name' => '#1', 'refunds' => [['transactions' => [['kind' => 'refund', 'status' => 'success', 'amount' => 10], ['kind' => 'refund', 'status' => 'failure', 'amount' => 999]]]]], ['email' => 'a@x.com', 'name' => '#2', 'refunds' => [['transactions' => [['kind' => 'refund', 'status' => 'success', 'amount' => 20]]]]], ['email' => 'b@x.com', 'name' => '#3', 'refunds' => []]];
        $rows = (new RepeatRefundAnalyzer)->analyze($orders, 2);
        $this->assertCount(1, $rows);
        $this->assertSame('a@x.com', $rows[0]['email']);
        $this->assertSame(30.0, $rows[0]['total_refunded']);
    }
}
