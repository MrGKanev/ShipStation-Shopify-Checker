<?php

namespace App\Domain\Reports;

class OrderEditAnalyzer
{
    /** @param list<array<string, mixed>> $events @return array<string, array{latest_at: string, summary: list<string>}> */
    public function group(array $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            if (($event['verb'] ?? '') !== 'edit_complete') {
                continue;
            }
            $id = $this->text($event['subject_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $at = $this->text($event['created_at'] ?? '');
            $message = ucfirst($this->text($event['message'] ?? ''));
            $groups[$id] ??= ['latest_at' => $at, 'summary' => []];
            if ($at > $groups[$id]['latest_at']) {
                $groups[$id]['latest_at'] = $at;
            }
            if ($message !== '' && count($groups[$id]['summary']) < 4 && ! in_array($message, $groups[$id]['summary'], true)) {
                $groups[$id]['summary'][] = $message;
            }
        }

        return $groups;
    }

    /** @param array<string, array<string, mixed>> $orders @param array<string, array{latest_at: string, summary: list<string>}> $groups @return list<array<string, mixed>> */
    public function rows(array $orders, array $groups): array
    {
        $rows = [];
        foreach ($orders as $id => $order) {
            $event = $groups[$id] ?? null;
            if ($event === null) {
                continue;
            }
            $created = strtotime($this->text($order['created_at'] ?? ''));
            $edited = strtotime($event['latest_at']);
            $rows[] = ['shopify_id' => ctype_digit($id) ? $id : '', 'order_number' => $this->text($order['name'] ?? ''), 'created_at' => substr($this->text($order['created_at'] ?? ''), 0, 10), 'edited_at' => substr($event['latest_at'], 0, 16), 'diff_mins' => $created !== false && $edited !== false ? max(0, (int) (($edited - $created) / 60)) : 0, 'email' => $this->text($order['email'] ?? ''), 'total' => is_numeric($order['total_price'] ?? null) ? (float) $order['total_price'] : 0.0, 'financial' => $this->text($order['financial_status'] ?? ''), 'fulfillment' => $this->text($order['fulfillment_status'] ?? ''), 'edit_summary' => $event['summary']];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['edited_at'], $a['edited_at']));

        return $rows;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
