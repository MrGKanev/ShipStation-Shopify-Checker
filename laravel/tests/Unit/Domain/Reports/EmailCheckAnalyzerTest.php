<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Reports\EmailCheckAnalyzer;
use PHPUnit\Framework\TestCase;

class EmailCheckAnalyzerTest extends TestCase
{
    public function test_critical_rules_and_legitimate_email(): void
    {
        $rows = $this->analyzer()->analyze([$this->order(''), $this->order('not-an-email'), $this->order('user@mailinator.com'), $this->order('jane.doe@gmail.com')]);

        $this->assertCount(3, $rows);
        $this->assertSame(['No email address on order', 'Invalid email format', 'Disposable / temporary email domain (mailinator.com)'], array_map(fn (array $row): string => $row['issues'][0]['message'], $rows));
        $this->assertSame(['critical', 'critical', 'critical'], array_column($rows, 'severity'));
    }

    public function test_warning_rules_preserve_boundaries(): void
    {
        $rows = $this->analyzer()->analyze([$this->order('ab@example.com'), $this->order('abc@example.com'), $this->order('test@example.com'), $this->order('aaaab@example.com'), $this->order('aaaaab@example.com')]);

        $this->assertCount(3, $rows);
        $this->assertSame(['ab@example.com', 'test@example.com', 'aaaaab@example.com'], array_column($rows, 'email'));
        $this->assertSame(['Very short local part - may be a test address', 'Email looks like a placeholder', 'Email has repeated characters - may be keyboard mashing'], array_map(fn (array $row): string => $row['issues'][0]['message'], $rows));
    }

    public function test_critical_rows_sort_before_warnings(): void
    {
        $rows = $this->analyzer()->analyze([$this->order('ab@example.com'), $this->order('user@yopmail.com')]);

        $this->assertSame(['critical', 'warning'], array_column($rows, 'severity'));
    }

    private function analyzer(): EmailCheckAnalyzer
    {
        return new EmailCheckAnalyzer;
    }

    /** @return array<string, mixed> */
    private function order(string $email): array
    {
        return ['id' => '42', 'name' => '#1001', 'created_at' => '2026-09-02T12:00:00Z', 'email' => $email];
    }
}
