<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    public function testFromRequestPrefersPostOverGet(): void
    {
        $range = DateRange::fromRequest(
            'scan',
            30,
            ['scan_start' => '2026-06-01', 'scan_end' => '2026-06-10'],
            ['scan_start' => '2026-05-01', 'scan_end' => '2026-05-10']
        );

        $this->assertSame(['2026-06-01', '2026-06-10'], $range);
    }

    public function testFromRequestFallsBackToGet(): void
    {
        $range = DateRange::fromRequest(
            'scan',
            30,
            [],
            ['scan_start' => '2026-05-01', 'scan_end' => '2026-05-10']
        );

        $this->assertSame(['2026-05-01', '2026-05-10'], $range);
    }

    public function testValidateAcceptsChronologicalIsoDates(): void
    {
        $this->assertNull(DateRange::validate('2026-06-01', '2026-06-10'));
    }

    public function testValidateRejectsInvalidDateFormat(): void
    {
        $this->assertSame(
            'Invalid date format. Use YYYY-MM-DD.',
            DateRange::validate('06/01/2026', '2026-06-10')
        );
    }

    public function testValidateRejectsInvalidCalendarDate(): void
    {
        $this->assertSame(
            'Invalid date format. Use YYYY-MM-DD.',
            DateRange::validate('2026-02-31', '2026-06-10')
        );
    }

    public function testValidateRejectsStartAfterEnd(): void
    {
        $this->assertSame(
            'Start date must be before end date.',
            DateRange::validate('2026-06-10', '2026-06-01')
        );
    }

    // ── addDays ──────────────────────────────────────────────────────────────
    // Covers the "7-day ShipStation fetch buffer" bullet in
    // docs/audit-test-coverage-gaps.md - audit.php extends its SS end date
    // by 7 days via this helper so sub-orders created a few days after the
    // Shopify order are still caught.

    public function testAddDaysExtendsForward(): void
    {
        $this->assertSame('2026-06-27', DateRange::addDays('2026-06-20', 7));
    }

    public function testAddDaysCrossesMonthBoundary(): void
    {
        $this->assertSame('2026-07-02', DateRange::addDays('2026-06-25', 7));
    }

    public function testAddDaysWithZeroReturnsSameDate(): void
    {
        $this->assertSame('2026-06-20', DateRange::addDays('2026-06-20', 0));
    }

    public function testAddDaysWithNegativeSubtractsDays(): void
    {
        $this->assertSame('2026-06-13', DateRange::addDays('2026-06-20', -7));
    }
}
