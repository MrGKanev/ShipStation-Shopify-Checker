<?php

namespace App\Domain\Reports;

class DisputeAnalyzer
{
    /** @param list<array<string, mixed>> $disputes @return list<array<string, mixed>> */
    public function analyze(array $disputes, int $now): array
    {
        $rows = [];
        foreach ($disputes as $dispute) {
            $due = is_scalar($dispute['evidence_due_by'] ?? null) ? trim((string) $dispute['evidence_due_by']) : '';
            $timestamp = $due === '' ? false : strtotime($due);
            $rows[] = $dispute + ['days_until_due' => $timestamp === false ? null : (int) ceil(($timestamp - $now) / 86400)];
        }
        usort($rows, fn (array $a, array $b): int => ($a['days_until_due'] ?? PHP_INT_MAX) <=> ($b['days_until_due'] ?? PHP_INT_MAX));

        return $rows;
    }
}
