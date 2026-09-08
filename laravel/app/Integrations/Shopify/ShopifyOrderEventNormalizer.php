<?php

namespace App\Integrations\Shopify;

class ShopifyOrderEventNormalizer
{
    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function normalize(array $event, string $fallbackOrderGid): array
    {
        $subjectGid = (string) ($event['subjectId'] ?? '');

        if ($subjectGid === '') {
            $subjectGid = $fallbackOrderGid;
        }

        $action = mb_strtolower((string) ($event['action'] ?? ''));

        return [
            'id' => $this->legacyId($event['id'] ?? null),
            'admin_graphql_api_id' => $event['id'] ?? '',
            'verb' => $action,
            'action' => $action,
            'created_at' => $event['createdAt'] ?? '',
            'message' => (string) ($event['message'] ?? ''),
            'subject_id' => $this->legacyId($subjectGid),
            'subject_type' => mb_strtolower((string) ($event['subjectType'] ?? 'Order')),
            'subject_graphql_api_id' => $subjectGid,
            'app_title' => $event['appTitle'] ?? '',
        ];
    }

    private function legacyId(mixed $graphqlId): int|string
    {
        $id = '';

        if (is_string($graphqlId) && preg_match('~/([0-9]+)(?:\?.*)?$~', $graphqlId, $matches) === 1) {
            $id = $matches[1];
        }

        return ctype_digit($id) ? (int) $id : $id;
    }
}
