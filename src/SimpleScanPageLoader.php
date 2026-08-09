<?php
declare(strict_types=1);

/**
 * Loads smaller Shopify-only scan pages that use the shared ScanRunner scaffold.
 */
class SimpleScanPageLoader
{
    public static function load(string $page, string $action, array $ctx): array
    {
        return match ($page) {
            'tagaudit'        => self::loadTagAudit($action, $ctx),
            'emailcheck'      => self::loadEmailCheck($action, $ctx),
            'hvorders'        => self::loadHvOrders($action, $ctx),
            'countrymismatch' => self::loadCountryMismatch($action, $ctx),
            'partialfulfill'  => self::loadPartialFulfill($action, $ctx),
            'returns'         => self::loadReturns($action, $ctx),
            'returneditems'   => self::loadReturnedItems($action, $ctx),
            default           => [],
        };
    }

    private static function loadTagAudit(string $action, array $ctx): array
    {
        ['result' => $tagAuditResult, 'error' => $tagAuditError, 'start' => $taStart, 'end' => $taEnd] =
            ScanRunner::run($action, 'tag_audit', $ctx, 'ta', function ($ctx, $start, $end) {
                self::setLimits(300);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $result  = $shopify->fetchTagStats($start, $end);
                $result['start'] = $start;
                $result['end']   = $end;
                return $result;
            }, 90);

        return compact('tagAuditResult', 'tagAuditError', 'taStart', 'taEnd');
    }

    private static function loadEmailCheck(string $action, array $ctx): array
    {
        $emailResult = null;
        $emailError  = '';
        [$emailStart, $emailEnd] = DateRange::fromRequest('email');

        ['result' => $emailResult, 'error' => $emailError, 'start' => $emailStart, 'end' => $emailEnd] =
            ScanRunner::run($action, 'scan_emails', $ctx, 'email', function ($ctx, $start, $end) {
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForAddressScan($start, $end));

                $rows = self::buildEmailCheckRows($orders);
                return [
                    'rows'     => $rows,
                    'scanned'  => count($orders),
                    'start'    => $start,
                    'end'      => $end,
                    'critical' => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
                    'warnings' => count(array_filter($rows, fn($r) => $r['severity'] === 'warning')),
                ];
            });

        return compact('emailResult', 'emailError', 'emailStart', 'emailEnd');
    }

    /** Disposable/temporary email domains flagged as critical by the Email Checker. */
    private const DISPOSABLE_EMAIL_DOMAINS = [
        'mailinator.com','guerrillamail.com','tempmail.com','throwam.com',
        'yopmail.com','sharklasers.com','guerrillamailblock.com','grr.la',
        'guerrillamail.info','trashmail.com','trashmail.net','trashmail.org',
        'dispostable.com','maildrop.cc','spamgourmet.com','spamgourmet.net',
        'mailnull.com','spamcorner.com','10minutemail.com','10minutemail.net',
        'fakeinbox.com','mailnesia.com','discard.email','spamspot.com',
        'mytemp.email','temp-mail.org','getnada.com','tempr.email',
    ];

    /**
     * Email Checker rows: orders with a missing/invalid/suspicious email
     * address, sorted critical-first.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildEmailCheckRows(array $orders): array
    {
        $rows = [];
        foreach ($orders as $o) {
            $email  = strtolower(trim($o['email'] ?? ''));
            $issues = [];
            if (!$email) {
                $issues[] = ['level' => 'critical', 'message' => 'No email address on order'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $issues[] = ['level' => 'critical', 'message' => 'Invalid email format'];
            } else {
                $domain = substr($email, strrpos($email, '@') + 1);
                if (in_array($domain, self::DISPOSABLE_EMAIL_DOMAINS, true)) {
                    $issues[] = ['level' => 'critical', 'message' => 'Disposable / temporary email domain (' . $domain . ')'];
                }
                $local = substr($email, 0, strrpos($email, '@'));
                if (strlen($local) <= 2) {
                    $issues[] = ['level' => 'warning', 'message' => 'Very short local part - may be a test address'];
                }
                if (preg_match('/^(test|noemail|no-?reply|none|null|fake|dummy|xxx|aaa|zzz)\b/i', $local)) {
                    $issues[] = ['level' => 'warning', 'message' => 'Email looks like a placeholder'];
                }
                if (preg_match('/(.)\1{4,}/', $local)) {
                    $issues[] = ['level' => 'warning', 'message' => 'Email has repeated characters - may be keyboard mashing'];
                }
            }
            if (!empty($issues)) {
                $rows[] = [
                    'shopify_id'   => $o['id'] ?? '',
                    'order_number' => $o['name'] ?? '',
                    'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                    'email'        => $o['email'] ?? '',
                    'issues'       => $issues,
                    'severity'     => in_array('critical', array_column($issues, 'level')) ? 'critical' : 'warning',
                ];
            }
        }
        usort($rows, fn($a, $b) =>
            ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1)
        );
        return $rows;
    }

    private static function loadHvOrders(string $action, array $ctx): array
    {
        $hvMin = max(0, (int)($_POST['hv_min'] ?? $_GET['hv_min'] ?? 200));

        ['result' => $hvResult, 'error' => $hvError, 'start' => $hvStart, 'end' => $hvEnd] =
            ScanRunner::run($action, 'scan_hvorders', $ctx, 'hv', function ($ctx, $start, $end) use (&$hvMin) {
                $hvMin   = max(0, (int)($_POST['hv_min'] ?? 200));
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForHighValue($start, $end));

                $rows = self::buildHvOrderRows($orders, $hvMin);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'min' => $hvMin];
            });

        return compact('hvResult', 'hvError', 'hvStart', 'hvEnd', 'hvMin');
    }

    /**
     * High-Value No Phone rows: orders at or above $hvMin with no shipping
     * phone number, sorted by total descending.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildHvOrderRows(array $orders, float $hvMin): array
    {
        $rows = [];
        foreach ($orders as $o) {
            $addr  = $o['shipping_address'] ?? null;
            $phone = trim($addr['phone'] ?? '');
            $total = (float)($o['total_price'] ?? 0);
            if ($phone || $total < $hvMin) continue;
            $rows[] = [
                'shopify_id'   => $o['id'] ?? '',
                'order_number' => $o['name'] ?? '',
                'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                'email'        => $o['email'] ?? '',
                'total'        => $total,
                'address'      => $addr,
            ];
        }
        usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
        return $rows;
    }

    private static function loadCountryMismatch(string $action, array $ctx): array
    {
        $cmResult = null;
        $cmError  = '';
        [$cmStart, $cmEnd] = DateRange::fromRequest('cm');

        ['result' => $cmResult, 'error' => $cmError, 'start' => $cmStart, 'end' => $cmEnd] =
            ScanRunner::run($action, 'scan_country_mismatch', $ctx, 'cm', function ($ctx, $start, $end) {
                self::setLimits(180);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersForCountryMismatch($start, $end));

                $rows = self::buildCountryMismatchRows($orders);
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end];
            });

        return compact('cmResult', 'cmError', 'cmStart', 'cmEnd');
    }

    /**
     * Country Mismatch rows: orders where billing and shipping country
     * differ (both must be present), sorted by created_at descending.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildCountryMismatchRows(array $orders): array
    {
        $rows = [];
        foreach ($orders as $o) {
            $bill = $o['billing_address']  ?? null;
            $ship = $o['shipping_address'] ?? null;
            $billCountry = strtoupper(trim($bill['country_code'] ?? $bill['country'] ?? ''));
            $shipCountry = strtoupper(trim($ship['country_code'] ?? $ship['country'] ?? ''));
            if (!$billCountry || !$shipCountry || $billCountry === $shipCountry) continue;
            $rows[] = [
                'shopify_id'   => $o['id'] ?? '',
                'order_number' => $o['name'] ?? '',
                'created_at'   => self::dateOnly($o['created_at'] ?? ''),
                'email'        => $o['email'] ?? '',
                'total_price'  => (float)($o['total_price'] ?? 0),
                'financial'    => $o['financial_status'] ?? '',
                'fulfillment'  => $o['fulfillment_status'] ?? '',
                'bill_country' => $billCountry,
                'ship_country' => $shipCountry,
                'bill_name'    => trim(($bill['first_name'] ?? '') . ' ' . ($bill['last_name'] ?? '')),
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    private static function loadPartialFulfill(string $action, array $ctx): array
    {
        $pfThreshold = max(1, (int)($_POST['pf_threshold'] ?? $_GET['pf_threshold'] ?? 7));

        ['result' => $pfResult, 'error' => $pfError, 'start' => $pfStart, 'end' => $pfEnd] =
            ScanRunner::run($action, 'scan_partial_fulfill', $ctx, 'pf', function ($ctx, $start, $end) use (&$pfThreshold) {
                $pfThreshold = max(1, (int)($_POST['pf_threshold'] ?? 7));
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchPartiallyFulfilledOrders($start, $end));

                $rows = self::buildPartialFulfillRows($orders, $pfThreshold, time());
                return ['rows' => $rows, 'scanned' => count($orders), 'start' => $start, 'end' => $end, 'threshold' => $pfThreshold];
            }, 90);

        return compact('pfResult', 'pfError', 'pfStart', 'pfEnd', 'pfThreshold');
    }

    /**
     * Partial Fulfillment Stalls rows: orders whose days-since-last-progress
     * (last fulfillment created_at, or order created_at if never fulfilled
     * at all) meets or exceeds $pfThreshold and still has fulfillable line
     * items, sorted by days_stalled descending.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private static function buildPartialFulfillRows(array $orders, int $pfThreshold, int $now): array
    {
        $rows = [];
        foreach ($orders as $o) {
            $lastFulfilled = '';
            foreach ($o['fulfillments'] ?? [] as $f) {
                $fa = $f['created_at'] ?? '';
                if ($fa > $lastFulfilled) $lastFulfilled = $fa;
            }
            $stallSince  = $lastFulfilled ?: ($o['created_at'] ?? '');
            $daysStalled = $stallSince ? (int) floor(($now - strtotime($stallSince)) / 86400) : 0;
            if ($daysStalled < $pfThreshold) continue;

            $unfulfilledItems = [];
            foreach ($o['line_items'] ?? [] as $li) {
                $fulfillableQty = (int)($li['fulfillable_quantity'] ?? 0);
                if ($fulfillableQty <= 0) continue;
                $unfulfilledItems[] = [
                    'name' => $li['name'] ?? $li['title'] ?? '',
                    'sku'  => $li['sku']  ?? '',
                    'qty'  => $fulfillableQty,
                ];
            }
            if (empty($unfulfilledItems)) continue;

            $rows[] = [
                'shopify_id'        => $o['id'] ?? '',
                'order_number'      => $o['name'] ?? '',
                'created_at'        => self::dateOnly($o['created_at'] ?? ''),
                'last_fulfilled'    => self::dateOnly($lastFulfilled),
                'days_stalled'      => $daysStalled,
                'email'             => $o['email'] ?? '',
                'total_price'       => (float)($o['total_price'] ?? 0),
                'financial'         => $o['financial_status'] ?? '',
                'unfulfilled_items' => $unfulfilledItems,
            ];
        }
        usort($rows, fn($a, $b) => $b['days_stalled'] <=> $a['days_stalled']);
        return $rows;
    }

    private static function loadReturns(string $action, array $ctx): array
    {
        ['result' => $rtResult, 'error' => $rtError, 'start' => $rtStart, 'end' => $rtEnd] =
            ScanRunner::run($action, 'scan_returns', $ctx, 'rt', function ($ctx, $start, $end) {
                self::setLimits(240);
                $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken']);
                $orders  = self::suppressOutput(fn() => $shopify->fetchRefundedOrders($start, $end));

                $rows    = [];
                $skuStat = [];  // [sku => ['units' => int, 'orders' => int, 'revenue' => float]]

                foreach ($orders as $o) {
                    foreach ($o['refunds'] ?? [] as $refund) {
                        $items = [];
                        foreach ($refund['refund_line_items'] ?? [] as $rli) {
                            $li  = $rli['line_item'] ?? [];
                            $sku = trim((string)($li['sku'] ?? ''));
                            $qty = (int)($rli['quantity'] ?? 0);
                            $sub = (float)($rli['subtotal'] ?? 0);

                            $items[] = [
                                'name'     => $li['name'] ?? $li['title'] ?? '',
                                'sku'      => $sku,
                                'quantity' => $qty,
                                'subtotal' => $sub,
                            ];

                            if ($sku !== '' && $qty > 0) {
                                if (!isset($skuStat[$sku])) {
                                    $skuStat[$sku] = ['sku' => $sku, 'units' => 0, 'orders' => 0, 'revenue' => 0.0];
                                }
                                $skuStat[$sku]['units']   += $qty;
                                $skuStat[$sku]['revenue'] += $sub;
                                $skuStat[$sku]['orders']++;
                            }
                        }

                        $rows[] = [
                            'shopify_id'     => $o['id']            ?? '',
                            'order_number'   => $o['name']          ?? '',
                            'created_at'     => self::dateOnly($o['created_at'] ?? ''),
                            'refund_date'    => self::dateOnly($refund['created_at'] ?? ''),
                            'email'          => $o['email']         ?? '',
                            'financial'      => $o['financial_status'] ?? '',
                            'refund_total'   => (float)($refund['total_refunded'] ?? 0),
                            'reason'         => trim($refund['note'] ?? ''),
                            'items'          => $items,
                        ];
                    }
                }

                usort($rows, fn($a, $b) => strcmp($b['refund_date'], $a['refund_date']));

                // Sort SKU stats by units returned descending
                usort($skuStat, fn($a, $b) => $b['units'] <=> $a['units']);

                return [
                    'rows'     => $rows,
                    'sku_stat' => array_values($skuStat),
                    'scanned'  => count($orders),
                    'start'    => $start,
                    'end'      => $end,
                ];
            }, 30);

        return compact('rtResult', 'rtError', 'rtStart', 'rtEnd');
    }

    private static function loadReturnedItems(string $action, array $ctx): array
    {
        ['result' => $riResult, 'error' => $riError, 'start' => $riStart, 'end' => $riEnd] =
            ScanRunner::run($action, 'scan_returneditems', $ctx, 'ri', function ($ctx, $start, $end) {
                self::setLimits(240);
                $data = self::fetchReturnedItems($ctx, $start, $end);
                return ['rows' => $data['rows'], 'scanned' => $data['scanned'], 'start' => $start, 'end' => $end];
            }, 30);

        $riEmailMessage = '';
        $riEmailError   = '';

        if ($action === 'email_returneditems') {
            $notifier = $ctx['emailNotifier'] ?? EmailNotifier::fromEnvironment();

            if ($err = DateRange::validate($riStart, $riEnd)) {
                $riEmailError = $err;
            } elseif (!$ctx['shopifyToken'] || $ctx['shopifyStore'] === 'N/A') {
                $riEmailError = 'SHOPIFY_ACCESS_TOKEN / SHOPIFY_STORE not set in .env.';
            } elseif (!$notifier) {
                $riEmailError = 'SMTP_HOST / ALERT_EMAIL not set in .env.';
            } else {
                try {
                    self::setLimits(240);
                    $data     = self::fetchReturnedItems($ctx, $riStart, $riEnd);
                    $filename = "returned_items_{$riStart}_to_{$riEnd}.csv";
                    $subject  = "Returned Items Report ({$riStart} \u{2192} {$riEnd})";
                    $body     = ReturnedItemsReport::emailHtml($data['totals'], $riStart, $riEnd);

                    $notifier->sendReport($subject, $body, $filename, ReturnedItemsReport::toCsvString($data['totals']));

                    $riEmailMessage = 'Emailed to ' . getenv('ALERT_EMAIL') . '.';
                    $riResult       = ['rows' => $data['rows'], 'scanned' => $data['scanned'], 'start' => $riStart, 'end' => $riEnd];
                } catch (Throwable $e) {
                    $riEmailError = $e->getMessage();
                }
            }
        }

        return compact('riResult', 'riError', 'riStart', 'riEnd', 'riEmailMessage', 'riEmailError');
    }

    /**
     * @return array{rows: array<int, array{product: string, quantity: int}>, totals: array<string, int>, scanned: int}
     */
    private static function fetchReturnedItems(array $ctx, string $start, string $end): array
    {
        $shopify = new Shopify($ctx['shopifyStore'], $ctx['shopifyToken'], null, $ctx['httpStack'] ?? null);
        $orders  = self::suppressOutput(fn() => $shopify->fetchOrdersRefundedSince($start));
        $totals  = ReturnedItemsReport::aggregate($orders, $start, $end);

        $rows = [];
        foreach ($totals as $label => $qty) {
            $rows[] = ['product' => $label, 'quantity' => $qty];
        }

        return ['rows' => $rows, 'totals' => $totals, 'scanned' => count($orders)];
    }

    private static function dateOnly(string $dt): string
    {
        return substr($dt, 0, 10);
    }

    private static function setLimits(int $secs = 300): void
    {
        if (function_exists('set_time_limit')) set_time_limit($secs);
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
