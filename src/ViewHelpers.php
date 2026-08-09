<?php
/**
 * Global view-helper functions.
 * Keep all functions at global scope - views call them without qualification.
 */

function esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Injects a hidden `_csrf` field into every rendered `method="post"` form
 * that doesn't already have one, so views never need to remember to add it
 * themselves. Skips non-POST forms and forms whose body already carries a
 * `_csrf` field (checked against the form's inner content, not its opening
 * tag's own attributes - a form never has a `_csrf` attribute on itself,
 * that name only ever appears on a child `<input>`).
 */
function injectCsrfTokens(string $html, string $csrfToken): string
{
    return preg_replace_callback('/<form\b([^>]*)>(.*?)<\/form>/is', function (array $m) use ($csrfToken): string {
        $attrs = $m[1] ?? '';
        $body  = $m[2] ?? '';
        if (!preg_match('/\bmethod\s*=\s*([\'"]?)post\1/i', $attrs)) {
            return $m[0];
        }
        if (str_contains($body, '_csrf')) {
            return $m[0];
        }
        return '<form' . $attrs . '><input type="hidden" name="_csrf" value="' . esc($csrfToken) . '">' . $body . '</form>';
    }, $html) ?? $html;
}

function badge(int $count): string
{
    return $count === 0
        ? '<span class="badge badge-ok">All clear</span>'
        : '<span class="badge badge-warn">' . $count . ' missing</span>';
}

function pushFlashBanner(): string
{
    $ok  = $_GET['push_ok']    ?? '';
    $err = $_GET['push_error'] ?? '';
    if ($ok) {
        return '<div class="flash flash-ok">✓ Order #' . esc($ok) . ' pushed to ShipStation successfully.</div>';
    }
    if ($err) {
        return '<div class="flash flash-err">✗ Push failed: ' . esc($err) . '</div>';
    }
    return '';
}

function classifyOrder(array $order): string
{
    if (!empty($order['order_type'])) return $order['order_type'];
    return Comparator::classifyOrder($order);
}

/**
 * Prepares display variables for a single GraphQL order node.
 * Used by metafields.php, tagsearch.php and any future GraphQL order tables.
 *
 * @return array{url:string|null, name:string, date:string, email:string,
 *               finChip:string, finLabel:string, fulLabel:string,
 *               amount:string|null, currency:string, legacyId:string}
 */
function gqlOrderRow(array $o, string $shopifyAdminBase): array
{
    $legacyId = $o['legacyResourceId'] ?? '';
    $fin      = strtolower($o['displayFinancialStatus'] ?? '');
    return [
        'legacyId' => $legacyId,
        'url'      => $legacyId ? $shopifyAdminBase . '/' . $legacyId : null,
        'name'     => $o['name'] ?? '-',
        'date'     => !empty($o['createdAt']) ? date('Y-m-d', strtotime($o['createdAt'])) : '-',
        'email'    => $o['email'] ?? '-',
        'finChip'  => financialChip($fin),
        'finLabel' => $o['displayFinancialStatus']   ?? '-',
        'fulLabel' => $o['displayFulfillmentStatus'] ?? '-',
        'amount'   => $o['totalPriceSet']['shopMoney']['amount']       ?? null,
        'currency' => $o['totalPriceSet']['shopMoney']['currencyCode'] ?? '',
    ];
}

/**
 * Formats a minute count as a human label + CSS color variable, for any
 * "time since X" column (Order Edit History's edit gap, Address Changes'
 * placement-to-change gap). Documented thresholds: red >= 1 day,
 * yellow >= 1 hour, otherwise muted.
 *
 * @return array{label: string, color: string}
 */
function timeGapDisplay(int $diffMins): array
{
    $diffMins  = max(0, $diffMins);
    $diffDays  = intdiv($diffMins, 1440);
    $diffHours = intdiv($diffMins % 1440, 60);
    $diffRem   = $diffMins % 60;

    if ($diffDays > 0)      $label = "{$diffDays}d " . ($diffHours ? "{$diffHours}h" : '');
    elseif ($diffHours > 0) $label = "{$diffHours}h " . ($diffRem ? "{$diffRem}m" : '');
    else                    $label = "{$diffMins}m";

    $color = $diffDays >= 1 ? 'var(--danger)' : ($diffHours >= 1 ? 'var(--warn)' : 'var(--muted)');

    return ['label' => trim($label), 'color' => $color];
}

function formatPrice(float|string|null $amount): string
{
    if ($amount === null || $amount === '') return '-';
    return '$' . number_format((float) $amount, 2);
}

function financialChip(string $status): string
{
    return match(strtolower($status)) {
        'paid'                   => 'chip-paid',
        'partially_paid'         => 'chip-partial',
        'unpaid', 'pending'      => 'chip-unpaid',
        default                  => 'chip-unknown',
    };
}

function formatAddressLine(?array $addr): string
{
    if ($addr === null) return '';
    return implode(', ', array_filter([
        trim(($addr['address1'] ?? '') . ' ' . ($addr['address2'] ?? '')),
        $addr['city']          ?? '',
        $addr['province_code'] ?? $addr['state']   ?? '',
        $addr['zip']           ?? $addr['postalCode'] ?? '',
        $addr['country_code']  ?? $addr['country'] ?? '',
    ]));
}

function topbar(string $title, string $meta = ''): string
{
    return '<div class="topbar"><div>'
        . '<h1>' . esc($title) . '</h1>'
        . ($meta ? '<div class="meta">' . esc($meta) . '</div>' : '')
        . '</div></div>';
}

function featureInfoStart(string $key, string $label): string
{
    return '<div class="feature-info" data-info-key="' . esc($key) . '">'
        . '<button class="feature-info-toggle" aria-expanded="false">'
        . '<svg width="12" height="12" viewBox="0 0 10 6" fill="none">'
        . '<path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg> About: ' . esc($label) . '</button>'
        . '<div class="feature-info-body">';
}

function featureInfoEnd(): string
{
    return '</div></div>';
}

/**
 * Renders a metafield value - pretty-prints JSON, escapes plain text.
 */
function renderMetafieldValue(string $value): string
{
    $decoded = json_decode($value, true);
    if ($decoded !== null) {
        return '<pre class="mf-val-pre">' . esc(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }
    return esc($value);
}

function orderNumCell(string $num, ?string $url, ?string $copyVal = null, ?int $rowspan = null): string
{
    $copyVal ??= ltrim($num, '#');
    $inner   = $url
        ? '<a href="' . esc($url) . '" target="_blank" rel="noopener">' . esc($num) . '</a>'
        : esc($num);
    $attr = $rowspan !== null ? ' rowspan="' . $rowspan . '"' : '';
    return '<td class="order-num"' . $attr . '>'
        . $inner
        . '<button class="copy-btn" data-copy="' . esc($copyVal) . '" title="Copy">⧉</button>'
        . '</td>';
}

function actionLinks(array $opts): string
{
    $rowspanAttr = isset($opts['rowspan']) ? ' rowspan="' . (int)$opts['rowspan'] . '"' : '';
    $html = '<td class="td-actions"' . $rowspanAttr . '>';
    if (!empty($opts['ssUrl'])) {
        $label = $opts['ssLabel'] ?? 'Open in SS';
        $html .= '<a class="ignore-btn" href="' . esc($opts['ssUrl']) . '" target="_blank" rel="noopener">' . esc($label) . '</a>';
    }
    if (!empty($opts['shopifyUrl'])) {
        $label = $opts['shopifyLabel'] ?? 'View in Shopify';
        $html .= '<a class="ignore-btn" href="' . esc($opts['shopifyUrl']) . '" target="_blank" rel="noopener">' . esc($label) . '</a>';
    }
    $num = ltrim($opts['orderNum'] ?? '', '#');
    if ($num && !empty($opts['spotcheck'])) {
        $html .= '<a class="ignore-btn" href="?page=spotcheck&prefill=' . urlencode($num) . '">Spot-check</a>';
    }
    if ($num && !empty($opts['timeline'])) {
        $html .= '<a class="ignore-btn" href="?page=timeline&order=' . urlencode($num) . '">Timeline</a>';
    }
    if (!empty($opts['email'])) {
        $html .= '<a class="ignore-btn" href="?page=customer&email=' . urlencode($opts['email']) . '">Customer</a>';
    }
    return $html . '</td>';
}

function dateRangePartial(
    string $prefix,
    mixed $startVal,
    mixed $endVal,
    string $extraHtml = '',
    string $submitLabel = 'Scan',
    string $fromLabel = 'From',
    string $toLabel = 'To'
): void {
    $partialStartName   = $prefix . '_start';
    $partialStartVal    = $startVal;
    $partialEndName     = $prefix . '_end';
    $partialEndVal      = $endVal;
    $partialExtraHtml   = $extraHtml;
    $partialSubmitLabel = $submitLabel;
    $partialFromLabel   = $fromLabel;
    $partialToLabel     = $toLabel;
    require __DIR__ . '/../views/partials/_date-range.php';
}

function searchInput(string $tableId, string $placeholder): string
{
    return '<div class="search-wrap mb-3">'
        . '<input class="js-search" data-target="' . esc($tableId) . '"'
        . ' placeholder="' . esc($placeholder) . '" type="search">'
        . '</div>';
}

function tableWrapEmpty(string $heading, string $msg): string
{
    return '<div class="table-wrap">'
        . '<div class="empty">'
        . '<div class="icon">✅</div>'
        . '<h3>' . esc($heading) . '</h3>'
        . '<p>' . esc($msg) . '</p>'
        . '</div>'
        . '</div>';
}

function tableWrapHeader(
    array $rows,
    string $tableId,
    string $title,
    string $csvSlug,
    string $dateStart,
    string $unit = 'order',
    string $searchPlaceholder = ''
): string {
    $n    = count($rows);
    $html = '<div class="table-header">'
        . '<h2>' . esc($title) . '</h2>'
        . '<div class="flex items-center gap-2">'
        . '<span>' . $n . ' ' . esc($unit) . ($n !== 1 ? 's' : '') . '</span>'
        . '<button class="btn btn-sm btn-ghost"'
        . ' data-csv-btn="#' . esc($tableId) . '"'
        . ' data-csv-filename="' . esc($csvSlug) . '-' . esc($dateStart) . '.csv">'
        . 'Export CSV</button>'
        . '</div>'
        . '</div>';
    if ($searchPlaceholder !== '') {
        $html .= searchInput($tableId, $searchPlaceholder);
    }
    return $html;
}

/**
 * Renders a coloured risk badge from a RiskScorer::score() result.
 *
 * Shows a coloured pill (badge-ok / badge-warn / badge-danger) labelled
 * "<Level> <score>".  When signals are present the full breakdown is
 * shown in a <details> element so the user can expand it inline.
 *
 * Pass $withDetails = false to render a plain badge (e.g. for CSV export).
 *
 * @param  array{score: int, level: string, signals: list<array{label: string, points: int}>} $risk
 */
function riskBadge(array $risk, bool $withDetails = true): string
{
    $level   = $risk['level']   ?? 'low';
    $score   = $risk['score']   ?? 0;
    $signals = $risk['signals'] ?? [];

    $cls = match ($level) {
        'high'   => 'badge-danger',
        'medium' => 'badge-warn',
        default  => 'badge-ok',
    };
    $label = ucfirst($level) . ' ' . $score;

    if ($withDetails && !empty($signals)) {
        $rows = array_map(
            fn($s) => '<div class="risk-signal">'
                . esc($s['label'])
                . ' <span class="risk-pts">+' . (int) $s['points'] . '</span>'
                . '</div>',
            $signals
        );
        return '<details class="risk-details">'
            . '<summary class="badge ' . $cls . '">' . esc($label) . '</summary>'
            . '<div class="risk-signal-list">' . implode('', $rows) . '</div>'
            . '</details>';
    }

    return '<span class="badge ' . $cls . '">' . esc($label) . '</span>';
}

function datePresets(): string
{
    $presets = [
        7   => '1 week',
        21  => '3 weeks',
        30  => '1 month',
        90  => '3 months',
        180 => '6 months',
        365 => '1 year',
    ];
    $html = '<div class="preset-row"><span class="preset-label">Quick select:</span>';
    foreach ($presets as $days => $label) {
        $html .= '<button class="preset-btn" type="button" data-days="' . $days . '">' . $label . '</button>';
    }
    return $html . '</div>';
}
