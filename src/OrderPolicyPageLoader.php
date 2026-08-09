<?php
declare(strict_types=1);

/**
 * Loads order policy, notes, and conflict scan pages.
 */
class OrderPolicyPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'orderedits'    => self::loadOrderEdits($action, $ctx),
            'noteflags'     => self::loadNoteFlags($action, $ctx),
            'addrdupes'     => self::loadAddrDupes($action, $ctx),
            'activess'      => self::loadActiveSsConflicts($action, $ctx),
            'discountabuse' => self::loadDiscountAbuse($action, $ctx),
            'tagpolicy'     => self::loadTagPolicy($action, $ctx),
            default         => [],
        };
    }

    private static function loadOrderEdits(string $action, array $ctx): array
    {
        $oeResult = null;
        $oeError = '';
        [$oeStart, $oeEnd] = DateRange::fromRequest('oe', 30);

        if ($action === 'scan_order_edits') {
            $oeStart = trim($_POST['oe_start'] ?? '');
            $oeEnd = trim($_POST['oe_end'] ?? '');

            if ($err = self::requireShopify($ctx)) {
                $oeError = $err;
            } elseif ($err = DateRange::validate($oeStart, $oeEnd)) {
                $oeError = $err;
            } else {
                try {
                    self::setLimits(240);
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $rows = self::suppressOutput(fn() => $shopify->fetchEditedOrders($oeStart, $oeEnd));
                    $oeResult = ['rows' => $rows, 'start' => $oeStart, 'end' => $oeEnd];
                } catch (Throwable $e) {
                    $oeError = $e->getMessage();
                }
            }
        }

        return compact('oeResult', 'oeError', 'oeStart', 'oeEnd');
    }

    private static function loadNoteFlags(string $action, array $ctx): array
    {
        $defaultKeywords = 'urgent, hold, cancel, wrong, error, stop, do not ship, dont ship, wait, attention';
        $nfKeywordsRaw = trim($_POST['nf_keywords'] ?? $_GET['nf_keywords'] ?? $defaultKeywords);

        ['result' => $nfResult, 'error' => $nfError, 'start' => $nfStart, 'end' => $nfEnd] =
            ScanRunner::run($action, 'scan_noteflags', $ctx, 'nf', function ($ctx, $start, $end) use (&$nfKeywordsRaw, $defaultKeywords) {
                $nfKeywordsRaw = trim($_POST['nf_keywords'] ?? $defaultKeywords);
                $keywords = array_values(array_filter(array_map('trim', explode(',', strtolower($nfKeywordsRaw)))));
                if (empty($keywords)) {
                    throw new \InvalidArgumentException('Enter at least one keyword.');
                }
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders = self::suppressOutput(fn() => $shopify->fetchOrdersWithNotes($start, $end));

                $rows = [];
                foreach ($orders as $o) {
                    $note = strtolower($o['note'] ?? '');
                    if (!$note) continue;
                    $matched = [];
                    foreach ($keywords as $kw) {
                        if ($kw !== '' && str_contains($note, $kw)) $matched[] = $kw;
                    }
                    if (empty($matched)) continue;
                    $rows[] = [
                        'shopify_id'   => $o['id']          ?? '',
                        'order_number' => $o['name']        ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'email'        => $o['email']       ?? '',
                        'total'        => $o['total_price'] ?? '',
                        'financial'    => $o['financial_status']   ?? '',
                        'fulfillment'  => $o['fulfillment_status'] ?? '',
                        'note'         => $o['note']        ?? '',
                        'matched'      => $matched,
                    ];
                }
                usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
                return [
                    'rows'     => $rows,
                    'scanned'  => count($orders),
                    'start'    => $start,
                    'end'      => $end,
                    'keywords' => $keywords,
                ];
            });

        return compact('nfResult', 'nfError', 'nfStart', 'nfEnd', 'nfKeywordsRaw');
    }

    private static function loadAddrDupes(string $action, array $ctx): array
    {
        ['result' => $adResult, 'error' => $adError, 'start' => $adStart, 'end' => $adEnd] =
            ScanRunner::run($action, 'scan_addrdupes', $ctx, 'ad', function ($ctx, $start, $end) {
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders = self::suppressOutput(fn() => $shopify->fetchOrdersForAddrDupes($start, $end));

                $groups = [];
                foreach ($orders as $o) {
                    $addr = $o['shipping_address'] ?? null;
                    $email = strtolower(trim($o['email'] ?? ''));
                    if (!$addr || !$email) continue;
                    $key = strtolower(implode('|', [
                        trim($addr['address1']     ?? ''),
                        trim($addr['city']         ?? ''),
                        trim($addr['zip']          ?? ''),
                        trim($addr['country_code'] ?? ''),
                    ]));
                    if ($key === '|||') continue;
                    if (!isset($groups[$key])) {
                        $groups[$key] = ['addr' => $addr, 'emails' => [], 'orders' => []];
                    }
                    $groups[$key]['emails'][$email] = true;
                    $groups[$key]['orders'][] = [
                        'shopify_id'   => $o['id']          ?? '',
                        'order_number' => $o['name']        ?? '',
                        'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                        'email'        => $o['email']       ?? '',
                        'total'        => $o['total_price'] ?? '',
                        'fulfillment'  => $o['fulfillment_status'] ?? '',
                    ];
                }

                $rows = [];
                foreach ($groups as $g) {
                    if (count($g['emails']) < 2) continue;
                    $addr = $g['addr'];
                    $rows[] = [
                        'addr_line'   => implode(', ', array_filter([
                            $addr['address1']      ?? '',
                            $addr['city']          ?? '',
                            $addr['province_code'] ?? '',
                            $addr['zip']           ?? '',
                            $addr['country_code']  ?? '',
                        ])),
                        'addr_name'   => trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                        'email_count' => count($g['emails']),
                        'order_count' => count($g['orders']),
                        'emails'      => array_keys($g['emails']),
                        'orders'      => $g['orders'],
                    ];
                }
                usort($rows, fn($a, $b) => $b['email_count'] <=> $a['email_count'] ?: $b['order_count'] <=> $a['order_count']);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end];
            });

        return compact('adResult', 'adError', 'adStart', 'adEnd');
    }

    private static function loadActiveSsConflicts(string $action, array $ctx): array
    {
        ['result' => $asResult, 'error' => $asError, 'start' => $asStart, 'end' => $asEnd] =
            ScanRunner::run($action, 'scan_activess', $ctx, 'as', function ($ctx, $start, $end) {
                self::setLimits(300);
                [$refunded, $cancelled, $activeSs] = self::suppressOutput(function () use ($ctx, $start, $end) {
                    $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                    $ss = new ShipStation($ctx['ssKey'], $ctx['ssSecret']);
                    return [
                        $shopify->fetchRefundedOrders($start, $end),
                        $shopify->fetchCancelledOrders($start, $end),
                        $ss->fetchActiveOrders(),
                    ];
                });

                $shopifyRows = self::dedupeShopifyOrdersById($refunded, $cancelled);
                $rows        = self::buildActiveSsConflictRows($shopifyRows, $activeSs);
                return [
                    'rows'      => $rows,
                    'scanned'   => count($shopifyRows),
                    'active_ss' => count($activeSs),
                    'start'     => $start,
                    'end'       => $end,
                ];
            }, 30, true);

        return compact('asResult', 'asError', 'asStart', 'asEnd');
    }

    /**
     * Merges refunded + cancelled Shopify orders, deduped by id.
     *
     * @param  array<int, array<string, mixed>> $refunded
     * @param  array<int, array<string, mixed>> $cancelled
     * @return array<int|string, array<string, mixed>>
     */
    private static function dedupeShopifyOrdersById(array $refunded, array $cancelled): array
    {
        $shopifyRows = [];
        foreach (array_merge($refunded, $cancelled) as $o) {
            $id = (string)($o['id'] ?? '');
            if ($id && isset($shopifyRows[$id])) continue;
            $shopifyRows[$id ?: spl_object_id((object)$o)] = $o;
        }
        return $shopifyRows;
    }

    /**
     * Active SS Conflicts rows: refunded/cancelled Shopify orders that still
     * match an active (awaiting_payment/awaiting_shipment/on_hold) SS order,
     * sorted by created_at descending. `issue` is 'cancelled' when the
     * Shopify order was cancelled, otherwise its raw financial_status.
     *
     * @param  array<int|string, array<string, mixed>> $shopifyRows
     * @param  array<int, array<string, mixed>>         $activeSs
     * @return array<int, array<string, mixed>>
     */
    private static function buildActiveSsConflictRows(array $shopifyRows, array $activeSs): array
    {
        $activeIndex = Comparator::buildSSIndex($activeSs);

        $rows = [];
        foreach ($shopifyRows as $o) {
            $num = Comparator::normalise((string)($o['order_number'] ?? ''));
            $nameNorm = Comparator::normalise((string)($o['name'] ?? ''));
            $matches = $activeIndex[$num] ?? $activeIndex[$nameNorm] ?? [];
            if (empty($matches)) continue;

            $issue = !empty($o['cancelled_at']) ? 'cancelled' : ($o['financial_status'] ?? 'refunded');
            foreach ($matches as $ssOrder) {
                $rows[] = [
                    'shopify_id'   => $o['id'] ?? '',
                    'order_number' => $o['name'] ?? $o['order_number'] ?? '',
                    'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                    'issue'        => $issue,
                    'email'        => $o['email'] ?? '',
                    'total'        => $o['total_price'] ?? '',
                    'financial'    => $o['financial_status'] ?? '',
                    'cancelled_at' => self::dateOnly($o['cancelled_at'] ?? ''),
                    'ss_order_id'  => $ssOrder['orderId'] ?? '',
                    'ss_status'    => $ssOrder['orderStatus'] ?? '',
                    'ss_date'      => self::dateOnly($ssOrder['orderDate'] ?? $ssOrder['createDate'] ?? ''),
                    'ss_total'     => $ssOrder['orderTotal'] ?? '',
                ];
            }
        }
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    private static function loadDiscountAbuse(string $action, array $ctx): array
    {
        $daMinEmails = max(2, (int)($_POST['da_min_emails'] ?? $_GET['da_min_emails'] ?? 3));

        ['result' => $daResult, 'error' => $daError, 'start' => $daStart, 'end' => $daEnd] =
            ScanRunner::run($action, 'scan_discountabuse', $ctx, 'da', function ($ctx, $start, $end) use (&$daMinEmails) {
                $daMinEmails = max(2, (int)($_POST['da_min_emails'] ?? 3));
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders = self::suppressOutput(fn() => $shopify->fetchOrdersForDiscountAudit($start, $end));

                $rows = self::buildDiscountAbuseRows($orders, $daMinEmails);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'min_emails' => $daMinEmails];
            }, 30);

        return compact('daResult', 'daError', 'daStart', 'daEnd', 'daMinEmails');
    }

    /**
     * Discount Abuse rows: discount code + shipping-address combinations
     * used by at least $daMinEmails distinct customer emails, sorted by
     * email_count desc (order_count as tiebreaker). Emails are deduped via
     * associative array keys, so the same email reusing a code/address
     * multiple times counts once toward email_count.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildDiscountAbuseRows(array $orders, int $daMinEmails): array
    {
        $groups = [];
        foreach ($orders as $o) {
            $codes = $o['discount_codes'] ?? [];
            if (empty($codes)) continue;
            $addr = $o['shipping_address'] ?? null;
            if (!$addr) continue;
            $addrKey = self::addressKey($addr);
            if ($addrKey === '') continue;
            foreach ($codes as $discount) {
                $code = strtoupper(trim((string)($discount['code'] ?? '')));
                if ($code === '') continue;
                $key = $code . '|' . $addrKey;
                if (!isset($groups[$key])) {
                    $groups[$key] = ['code' => $code, 'addr' => $addr, 'emails' => [], 'orders' => [], 'total' => 0.0];
                }
                $email = strtolower(trim($o['email'] ?? ''));
                if ($email) $groups[$key]['emails'][$email] = true;
                $groups[$key]['total'] += (float)($o['total_price'] ?? 0);
                $groups[$key]['orders'][] = [
                    'shopify_id'   => $o['id'] ?? '',
                    'order_number' => $o['name'] ?? '',
                    'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                    'email'        => $o['email'] ?? '',
                    'total'        => $o['total_price'] ?? '',
                    'financial'    => $o['financial_status'] ?? '',
                    'fulfillment'  => $o['fulfillment_status'] ?? '',
                ];
            }
        }

        $rows = [];
        foreach ($groups as $g) {
            if (count($g['emails']) < $daMinEmails) continue;
            $addr = $g['addr'];
            $rows[] = [
                'code'        => $g['code'],
                'addr_line'   => self::addressLine($addr),
                'addr_name'   => trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')),
                'email_count' => count($g['emails']),
                'order_count' => count($g['orders']),
                'emails'      => array_keys($g['emails']),
                'orders'      => $g['orders'],
                'total'       => $g['total'],
            ];
        }
        usort($rows, fn($a, $b) => $b['email_count'] <=> $a['email_count'] ?: $b['order_count'] <=> $a['order_count']);
        return $rows;
    }

    private static function loadTagPolicy(string $action, array $ctx): array
    {
        $tpConfig = self::tagPolicyConfig();

        ['result' => $tpResult, 'error' => $tpError, 'start' => $tpStart, 'end' => $tpEnd] =
            ScanRunner::run($action, 'scan_tagpolicy', $ctx, 'tp', function ($ctx, $start, $end) use ($tpConfig) {
                if (!self::tagPolicyHasRules($tpConfig)) {
                    return ['rows' => [], 'scanned' => 0, 'start' => $start, 'end' => $end, 'configured' => false];
                }

                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders = self::suppressOutput(fn() => $shopify->fetchOrdersForTagPolicy($start, $end));

                $rows = self::buildTagPolicyRows($orders, $tpConfig);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'configured' => true];
            }, 30);

        return compact('tpResult', 'tpError', 'tpStart', 'tpEnd', 'tpConfig');
    }

    /** True when tag_policy.json defines at least one required or forbidden rule. */
    private static function tagPolicyHasRules(array $tpConfig): bool
    {
        return !empty(array_merge($tpConfig['required'] ?? [], $tpConfig['forbidden'] ?? []));
    }

    /**
     * Tag Policy Audit rows: orders violating a `required` rule (all
     * `when` trigger tags present but a `must_have` tag missing) or a
     * `forbidden` rule (two+ forbidden tags co-occurring), sorted by
     * created_at descending.
     *
     * A `required` rule only evaluates when ALL of its `when` tags are
     * present - partial trigger matches don't count. A `forbidden` rule
     * needs at least 2 tags configured and ALL of them present to fire.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildTagPolicyRows(array $orders, array $tpConfig): array
    {
        $rows = [];
        foreach ($orders as $o) {
            $tags = self::orderTags($o);
            $tagLookup = array_fill_keys(array_map('strtolower', $tags), true);
            $violations = [];

            foreach ($tpConfig['required'] ?? [] as $rule) {
                $when = array_map('strtolower', (array)($rule['when'] ?? []));
                $must = array_map('strtolower', (array)($rule['must_have'] ?? []));
                if ($when === [] || $must === []) continue;
                if (array_diff($when, array_keys($tagLookup)) !== []) continue;
                $missing = array_values(array_diff($must, array_keys($tagLookup)));
                if ($missing !== []) {
                    $violations[] = [
                        'type'   => 'required',
                        'name'   => $rule['name'] ?? 'Required tag policy',
                        'detail' => 'Missing: ' . implode(', ', $missing),
                    ];
                }
            }

            foreach ($tpConfig['forbidden'] ?? [] as $rule) {
                $forbidden = array_map('strtolower', (array)($rule['tags'] ?? []));
                if (count($forbidden) < 2) continue;
                if (array_diff($forbidden, array_keys($tagLookup)) === []) {
                    $violations[] = [
                        'type'   => 'forbidden',
                        'name'   => $rule['name'] ?? 'Forbidden tag combination',
                        'detail' => 'Combination: ' . implode(', ', $forbidden),
                    ];
                }
            }

            if ($violations === []) continue;
            $rows[] = [
                'shopify_id'   => $o['id'] ?? '',
                'order_number' => $o['name'] ?? '',
                'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                'email'        => $o['email'] ?? '',
                'total'        => $o['total_price'] ?? '',
                'financial'    => $o['financial_status'] ?? '',
                'fulfillment'  => $o['fulfillment_status'] ?? '',
                'tags'         => $tags,
                'violations'   => $violations,
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    private static function addressLine(array $addr): string
    {
        return implode(', ', array_filter([
            $addr['address1']      ?? '',
            $addr['city']          ?? '',
            $addr['province_code'] ?? '',
            $addr['zip']           ?? '',
            $addr['country_code']  ?? '',
        ]));
    }

    private static function addressKey(array $addr): string
    {
        return strtolower(implode('|', array_filter([
            trim((string)($addr['address1'] ?? '')),
            trim((string)($addr['city'] ?? '')),
            trim((string)($addr['zip'] ?? '')),
            trim((string)($addr['country_code'] ?? $addr['country'] ?? '')),
        ])));
    }

    /**
     * @return string[]
     */
    private static function orderTags(array $order): array
    {
        $raw = $order['tags'] ?? [];
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }
        return array_values(array_filter(array_map('trim', (array)$raw), fn($tag) => $tag !== ''));
    }

    /**
     * @return array{required?: array<int, array<string, mixed>>, forbidden?: array<int, array<string, mixed>>}
     */
    private static function tagPolicyConfig(): array
    {
        $file = __DIR__ . '/../tag_policy.json';
        if (!file_exists($file)) return [];
        return self::parseTagPolicyConfig(file_get_contents($file));
    }

    /** Decodes tag_policy.json contents, falling back to [] on malformed JSON. */
    private static function parseTagPolicyConfig(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
    }

    private static function requireShopify(array $ctx): ?string
    {
        return (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A')
            ? 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.'
            : null;
    }

    private static function suppressOutput(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }
}
