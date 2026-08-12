<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/ToolRegistry.php';
require_once __DIR__ . '/../../src/EmailRules.php';
require_once __DIR__ . '/../../src/EmailDigest.php';

class EmailDigestTest extends TestCase
{
    private function rule(array $overrides = []): array
    {
        return array_merge(['mode' => 'off', 'threshold' => 1, 'include_zero' => false, 'email' => ''], $overrides);
    }

    private function entry(string $tool, array $overrides = []): array
    {
        return array_merge(['tool' => $tool, 'created_at' => '2026-06-20 09:00:00', 'rows_found' => 5], $overrides);
    }

    public function testIncludesDigestToolWithQualifyingRunToday(): void
    {
        $rules  = ['scan_addresses' => $this->rule(['mode' => 'digest', 'threshold' => 1])];
        $runLog = [$this->entry('scan_addresses', ['created_at' => '2026-06-20 09:00:00', 'rows_found' => 3])];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertSame(3, $sections[''][0]['count']);
        $this->assertSame('scan_addresses', $sections[''][0]['tool']);
        $this->assertSame('Address validation', $sections[''][0]['label']);
    }

    public function testExcludesToolWithoutAnyRunToday(): void
    {
        $rules  = ['scan_addresses' => $this->rule(['mode' => 'digest'])];
        $runLog = [$this->entry('scan_addresses', ['created_at' => '2026-06-19 09:00:00'])];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertSame([], $sections);
    }

    public function testExcludesToolThatDidNotRunAtAll(): void
    {
        $rules = ['scan_addresses' => $this->rule(['mode' => 'digest'])];

        $sections = EmailDigest::buildSections($rules, [], '2026-06-20');

        $this->assertSame([], $sections);
    }

    public function testExcludesRunBelowThreshold(): void
    {
        $rules  = ['scan_addresses' => $this->rule(['mode' => 'digest', 'threshold' => 5])];
        $runLog = [$this->entry('scan_addresses', ['created_at' => '2026-06-20 09:00:00', 'rows_found' => 2])];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertSame([], $sections);
    }

    public function testExcludesToolsNotInDigestMode(): void
    {
        $rules = [
            'scan_addresses' => $this->rule(['mode' => 'immediate']),
            'scan_bundle'    => $this->rule(['mode' => 'off']),
        ];
        $runLog = [
            $this->entry('scan_addresses'),
            $this->entry('scan_bundle'),
        ];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertSame([], $sections);
    }

    public function testGroupsMultipleDigestToolsByCustomRecipient(): void
    {
        $rules = [
            'scan_addresses' => $this->rule(['mode' => 'digest', 'email' => 'risk@example.com']),
            'scan_bundle'    => $this->rule(['mode' => 'digest', 'email' => 'risk@example.com']),
        ];
        $runLog = [
            $this->entry('scan_addresses'),
            $this->entry('scan_bundle'),
        ];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertCount(2, $sections['risk@example.com']);
        $this->assertArrayNotHasKey('', $sections);
    }

    public function testSeparatesSectionsByDifferentRecipients(): void
    {
        $rules = [
            'scan_addresses' => $this->rule(['mode' => 'digest', 'email' => 'risk@example.com']),
            'scan_bundle'    => $this->rule(['mode' => 'digest', 'email' => '']),
        ];
        $runLog = [
            $this->entry('scan_addresses'),
            $this->entry('scan_bundle'),
        ];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertCount(1, $sections['risk@example.com']);
        $this->assertCount(1, $sections['']);
    }

    public function testUsesOnlyTheNewestRunLogEntryPerTool(): void
    {
        $rules = ['scan_addresses' => $this->rule(['mode' => 'digest', 'threshold' => 1])];
        // RunLog::all() is newest-first, so the first matching entry wins.
        $runLog = [
            $this->entry('scan_addresses', ['created_at' => '2026-06-20 15:00:00', 'rows_found' => 9]),
            $this->entry('scan_addresses', ['created_at' => '2026-06-20 09:00:00', 'rows_found' => 1]),
        ];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertSame(9, $sections[''][0]['count']);
    }

    public function testIncludeZeroAllowsZeroCountRun(): void
    {
        $rules  = ['run_audit' => $this->rule(['mode' => 'digest', 'threshold' => 0, 'include_zero' => true])];
        $runLog = [$this->entry('run_audit', ['created_at' => '2026-06-20 09:00:00', 'rows_found' => 0])];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertSame(0, $sections[''][0]['count']);
    }

    public function testMissingRowsFoundTreatedAsZero(): void
    {
        $rules  = ['scan_addresses' => $this->rule(['mode' => 'digest', 'threshold' => 1])];
        $runLog = [$this->entry('scan_addresses', ['created_at' => '2026-06-20 09:00:00', 'rows_found' => null])];

        $sections = EmailDigest::buildSections($rules, $runLog, '2026-06-20');

        $this->assertSame([], $sections);
    }
}
