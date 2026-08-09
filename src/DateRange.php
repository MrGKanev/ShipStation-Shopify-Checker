<?php
declare(strict_types=1);

/**
 * Shared date-range request parsing and validation for scan-style workflows.
 */
final class DateRange
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function fromRequest(
        string $prefix,
        int $defaultDays = 30,
        ?array $post = null,
        ?array $get = null
    ): array {
        $post ??= $_POST;
        $get  ??= $_GET;

        $start = $post["{$prefix}_start"]
            ?? $get["{$prefix}_start"]
            ?? date('Y-m-d', strtotime("-{$defaultDays} days"));
        $end = $post["{$prefix}_end"]
            ?? $get["{$prefix}_end"]
            ?? date('Y-m-d');

        return [trim((string) $start), trim((string) $end)];
    }

    /** Adds (or subtracts, for negative $days) whole days to a Y-m-d date string. */
    public static function addDays(string $date, int $days): string
    {
        return date('Y-m-d', strtotime($date . ' ' . $days . ' days'));
    }

    public static function validate(string $start, string $end): ?string
    {
        if (!self::isDateString($start) || !self::isDateString($end)) {
            return 'Invalid date format. Use YYYY-MM-DD.';
        }
        if ($start > $end) {
            return 'Start date must be before end date.';
        }
        return null;
    }

    private static function isDateString(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
