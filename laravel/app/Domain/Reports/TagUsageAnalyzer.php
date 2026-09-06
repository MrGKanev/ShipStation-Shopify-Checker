<?php

namespace App\Domain\Reports;

class TagUsageAnalyzer
{
    /** @param list<array<string, mixed>> $orders @return list<array{tag: string, count: int, last_order: string, last_date: string, orphan: bool}> */
    public function analyze(array $orders, string $orphanCutoff): array
    {
        $tags = [];

        foreach ($orders as $order) {
            $orderTags = is_array($order['tags'] ?? null) ? $order['tags'] : [];
            $orderName = $this->text($order['name'] ?? '');
            $createdAt = $this->date($order['createdAt'] ?? '');

            foreach (array_unique($orderTags, SORT_REGULAR) as $value) {
                $tag = $this->text($value);

                if ($tag === '') {
                    continue;
                }

                $tags[$tag] ??= ['tag' => $tag, 'count' => 0, 'last_order' => '', 'last_date' => '', 'orphan' => false];
                $tags[$tag]['count']++;

                if ($createdAt > $tags[$tag]['last_date']) {
                    $tags[$tag]['last_date'] = $createdAt;
                    $tags[$tag]['last_order'] = $orderName;
                }
            }
        }

        $rows = array_values($tags);

        foreach ($rows as &$row) {
            $row['orphan'] = $row['count'] === 1 && $row['last_date'] !== '' && $row['last_date'] < $orphanCutoff;
        }
        unset($row);

        usort($rows, fn (array $left, array $right): int => [$right['count'], $right['last_date'], $left['tag']] <=> [$left['count'], $left['last_date'], $right['tag']]);

        return $rows;
    }

    private function date(mixed $value): string
    {
        $date = substr($this->text($value), 0, 10);

        return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1 ? $date : '';
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
