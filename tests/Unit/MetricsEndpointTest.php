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

    public function testMetricsEndpointIncludesCacheEntriesGauge(): void
    {
        $output = $this->runMetrics([
            'METRICS_TOKEN' => '',
            'METRICS_ALLOW_PUBLIC' => '1',
        ]);

        $this->assertStringContainsString('# TYPE shopify_ops_cache_entries gauge', $output);
        $this->assertMatchesRegularExpression('/shopify_ops_cache_entries\{store="[^"]*"\} \d+/', $output);
    }

    public function testMetricsEndpointIncludesAuditDurationOnlyWhenAnAuditHasRun(): void
    {
        $output = $this->runMetrics([
            'METRICS_TOKEN' => '',
            'METRICS_ALLOW_PUBLIC' => '1',
        ]);

        $runLog = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/data/run_log.json'), true) ?: [];
        $hasAuditRun = false;
        foreach ($runLog as $entry) {
            if (in_array($entry['tool'] ?? '', ['cli_audit', 'run_audit'], true)) {
                $hasAuditRun = true;
                break;
            }
        }

        if ($hasAuditRun) {
            $this->assertStringContainsString('shopify_ops_audit_duration_seconds', $output);
        } else {
            $this->assertStringNotContainsString('shopify_ops_audit_duration_seconds', $output);
        }
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
