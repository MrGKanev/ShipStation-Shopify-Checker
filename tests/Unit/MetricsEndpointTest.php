<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MetricsEndpointTest extends TestCase
{
    public function testMetricsEndpointIsDisabledWithoutTokenByDefault(): void
    {
        $output = $this->runMetrics([
            'METRICS_TOKEN' => '',
            'METRICS_ALLOW_PUBLIC' => '',
        ]);

        $this->assertStringContainsString('Metrics endpoint disabled', $output);
        $this->assertStringNotContainsString('shopify_ops_missing_orders_total', $output);
    }

    public function testMetricsEndpointCanBeExplicitlyPublic(): void
    {
        $output = $this->runMetrics([
            'METRICS_TOKEN' => '',
            'METRICS_ALLOW_PUBLIC' => '1',
        ]);

        $this->assertStringContainsString('shopify_ops_missing_orders_total', $output);
    }

    /**
     * @param array<string, string> $env
     */
    private function runMetrics(array $env): string
    {
        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= $key . '=' . escapeshellarg($value) . ' ';
        }

        $script = escapeshellarg(dirname(__DIR__, 2) . '/metrics.php');
        $output = [];
        exec($prefix . PHP_BINARY . ' ' . $script . ' 2>&1', $output);

        return implode("\n", $output);
    }
}
