<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Renders each view added for the Fraud Risk Report, Same IP, and
 * Chargebacks/Disputes features directly (bypassing routing/auth), in
 * both the empty (initial GET) and populated (scan result) states.
 *
 * View templates have no dedicated test coverage anywhere else in this
 * suite - PageLoader-level tests only exercise the data-building logic,
 * never the .php template that consumes it. A wrong variable name or
 * helper call in a view was only caught here by manually including the
 * file during development; this formalizes that check as a permanent
 * regression test. phpunit.xml's failOnWarning turns any undefined-
 * variable/array-key notice during require into a test failure.
 */
class ViewSmokeTest extends TestCase
{
    private string $viewsDir;

    protected function setUp(): void
    {
        $this->viewsDir = dirname(__DIR__, 2) . '/views';
    }

    /** @param array<string, mixed> $vars */
    private function render(string $view, array $vars): string
    {
        extract($vars);
        $shopifyAdminBase ??= 'https://admin.shopify.com/store/test/orders';
        ob_start();
        require $this->viewsDir . '/' . $view . '.php';
        return ob_get_clean();
    }

    public function testRiskReportEmptyState(): void
    {
        $html = $this->render('riskreport', [
            'frResult' => null, 'frError' => '', 'frStart' => '2026-06-01', 'frEnd' => '2026-06-20',
        ]);

        $this->assertStringContainsString('Fraud Risk Report', $html);
    }

    public function testRiskReportPopulatedState(): void
    {
        $html = $this->render('riskreport', [
            'frResult' => [
                'scanned' => 5, 'start' => '2026-06-01', 'end' => '2026-06-20',
                'rows' => [[
                    'shopify_id' => '1001', 'order_number' => '#1001', 'created_at' => '2026-06-01',
                    'email' => 'a@example.com', 'total' => '99.00', 'financial' => 'paid',
                    'risk' => ['score' => 40, 'level' => 'medium', 'signals' => [['label' => 'PO Box address', 'points' => 10]]],
                ]],
            ],
            'frError' => '', 'frStart' => '2026-06-01', 'frEnd' => '2026-06-20',
        ]);

        $this->assertStringContainsString('#1001', $html);
        $this->assertStringContainsString('badge-warn', $html);
    }

    public function testSameIpEmptyState(): void
    {
        $html = $this->render('sameip', [
            'siResult' => null, 'siError' => '', 'siStart' => '2026-06-01', 'siEnd' => '2026-06-20',
        ]);

        $this->assertStringContainsString('Same IP, Different Emails', $html);
    }

    public function testSameIpPopulatedState(): void
    {
        $html = $this->render('sameip', [
            'siResult' => [
                'scanned' => 5, 'start' => '2026-06-01', 'end' => '2026-06-20',
                'rows' => [[
                    'ip' => '203.0.113.5', 'email_count' => 2, 'order_count' => 2,
                    'emails' => ['a@example.com', 'b@example.com'],
                    'orders' => [
                        ['shopify_id' => '1001', 'order_number' => '#1001', 'created_at' => '2026-06-01', 'email' => 'a@example.com', 'total' => '10.00', 'fulfillment' => null],
                        ['shopify_id' => '1002', 'order_number' => '#1002', 'created_at' => '2026-06-02', 'email' => 'b@example.com', 'total' => '20.00', 'fulfillment' => 'partial'],
                    ],
                ]],
            ],
            'siError' => '', 'siStart' => '2026-06-01', 'siEnd' => '2026-06-20',
        ]);

        $this->assertStringContainsString('203.0.113.5', $html);
        $this->assertStringContainsString('#1001', $html);
    }

    public function testDisputesEmptyState(): void
    {
        $html = $this->render('disputes', ['dpResult' => null, 'dpError' => '']);

        $this->assertStringContainsString('Chargebacks / Disputes', $html);
    }

    public function testDisputesPopulatedState(): void
    {
        $html = $this->render('disputes', [
            'dpResult' => [
                'scanned' => 2,
                'rows' => [
                    ['id' => 1, 'status' => 'needs_response', 'reason' => 'fraudulent', 'network_reason_code' => '10.4',
                     'initiated_at' => '2026-06-01T00:00:00Z', 'evidence_due_by' => '2026-06-10T00:00:00Z',
                     'amount' => '50.00', 'currency' => 'USD', 'order_id' => 1001, 'order_name' => '#1001', 'days_until_due' => 3],
                    ['id' => 2, 'status' => 'under_review', 'reason' => 'product_not_received', 'network_reason_code' => null,
                     'initiated_at' => '2026-05-01T00:00:00Z', 'evidence_due_by' => null,
                     'amount' => '20.00', 'currency' => 'USD', 'order_id' => 1002, 'order_name' => '#1002', 'days_until_due' => null],
                ],
            ],
            'dpError' => '',
        ]);

        $this->assertStringContainsString('#1001', $html);
        $this->assertStringContainsString('fraudulent', $html);
        $this->assertStringContainsString('#1002', $html);
    }
}
