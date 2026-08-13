<?= topbar('Order Timeline', 'Full chronological history of a single order') ?>

<?= featureInfoStart('timeline', 'Order Timeline') ?>
  <p><strong>Order Timeline</strong> merges every event from Shopify and ShipStation into one reverse-chronological view for a single order.</p>
  <p>Useful for CS teams investigating fulfilment delays, customers asking &ldquo;where is my order?&rdquo;, or auditing what happened to a refunded/cancelled order.</p>
  <ul>
    <li>Shows <strong>order placement, payment, fulfillments, refunds, cancellations</strong> and the full Shopify audit trail.</li>
    <li>Includes <strong>ShipStation order status and shipment history</strong> if SS credentials are configured.</li>
    <li>Flags <strong>risk signals</strong>: slow ship time, cancelled-but-shipped, refunded-but-active-in-SS.</li>
    <li>The <strong>Copy as text</strong> button exports the timeline to clipboard for quick pasting into support tickets.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Enter an order number</h2>
  <div class="hint">Enter a Shopify order number. The # prefix is optional.</div>

  <?php if ($tlError): ?>
    <div class="error-msg mb-3"><?= esc($tlError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="order_timeline">
    <div class="date-row">
      <div class="field date-row-wide">
        <label>Order Number</label>
        <input type="text" name="tl_order" value="<?= esc($tlInput) ?>"
               placeholder="1234" autofocus>
      </div>
      <button class="btn btn-submit-end" type="submit">Load timeline</button>
    </div>
  </form>
</div>

<?php if ($tlResult !== null):
  $order     = $tlResult['order'];
  $timeline  = $tlResult['timeline'];
  $risks     = $tlResult['risks'];
  $tos       = $tlResult['time_to_ship'];
  $label     = $tlResult['label'];
  $ssOrders  = $tlResult['ss_orders'];
  $shopifyId = $order['id'] ?? '';
  $orderUrl  = $shopifyId ? $shopifyAdminBase . '/' . $shopifyId : null;

  $finStatus = $order['financial_status']    ?? '-';
  $fulStatus = $order['fulfillment_status']  ?? 'unfulfilled';
  $total     = (float) ($order['total_price'] ?? 0);
  $email     = $order['email']    ?? '';
  $createdAt = substr($order['created_at'] ?? '', 0, 10);
  $itemCount = count($order['line_items'] ?? []);
  $fulCount  = count($order['fulfillments'] ?? []);

  $finChip = match(strtolower($finStatus)) {
    'paid'                        => 'chip-paid',
    'partially_paid'              => 'chip-partial',
    'unpaid','pending'            => 'chip-unpaid',
    default                       => 'chip-unknown',
  };
?>

<!-- Order summary card -->
<div class="tl-order-card">
  <div class="tl-order-meta">
    <div class="tl-order-name">
      <?php if ($orderUrl): ?>
        <a href="<?= esc($orderUrl) ?>" target="_blank" rel="noopener"><?= esc($label) ?></a>
      <?php else: ?>
        <?= esc($label) ?>
      <?php endif; ?>
      <span class="chip <?= $finChip ?>" style="font-size:.75rem;vertical-align:middle;margin-left:.4rem"><?= esc($finStatus) ?></span>
      <?php if (!empty($order['cancelled_at'])): ?>
        <span class="chip chip-unpaid" style="font-size:.75rem;vertical-align:middle;margin-left:.3rem">cancelled</span>
      <?php endif; ?>
    </div>
    <?php if ($email): ?>
      <div class="tl-order-email"><?= esc($email) ?></div>
    <?php endif; ?>
    <?php if (!empty($order['tags'])): ?>
      <div class="spot-matches mt-2">
        <?php foreach (explode(', ', $order['tags']) as $tag): ?>
          <?php if (trim($tag)): ?>
            <span class="spot-match-tag"><?= esc(trim($tag)) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="tl-order-stats">
    <div class="tl-stat">
      <div class="tl-stat-val">$<?= number_format($total, 2) ?></div>
      <div class="tl-stat-lbl">Total</div>
    </div>
    <div class="tl-stat">
      <div class="tl-stat-val"><?= $itemCount ?></div>
      <div class="tl-stat-lbl">Item<?= $itemCount !== 1 ? 's' : '' ?></div>
    </div>
    <div class="tl-stat">
      <div class="tl-stat-val"><?= $fulCount ?: '-' ?></div>
      <div class="tl-stat-lbl">Shipment<?= $fulCount !== 1 ? 's' : '' ?></div>
    </div>
    <?php if ($tos !== null): ?>
    <div class="tl-stat">
      <div class="tl-stat-val" style="color:<?= $tos > 7 ? 'var(--danger)' : ($tos > 3 ? 'var(--warn)' : 'var(--ok)') ?>"><?= $tos ?>d</div>
      <div class="tl-stat-lbl">To ship</div>
    </div>
    <?php endif; ?>
    <div class="tl-stat">
      <div class="tl-stat-val" style="font-size:.9rem"><?= esc($createdAt) ?></div>
      <div class="tl-stat-lbl">Placed</div>
    </div>
    <?php if (!empty($ssOrders)): ?>
    <div class="tl-stat">
      <div class="tl-stat-val"><?= count($ssOrders) ?></div>
      <div class="tl-stat-lbl">SS record<?= count($ssOrders) !== 1 ? 's' : '' ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($risks)): ?>
<div class="tl-risks">
  <?php foreach ($risks as $r): ?>
    <div class="tl-risk tl-risk-<?= esc($r['level']) ?>">
      <?php if ($r['level'] === 'danger'): ?>&#9888;<?php elseif ($r['level'] === 'warn'): ?>&#9888;<?php else: ?>&#8505;<?php endif; ?>
      <?= esc($r['msg']) ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
  $noteAttrs          = $order['note_attributes'] ?? [];
  $riskScore          = $tlResult['risk_score'] ?? null;
  $shopifyRiskLevel   = $order['risk_level'] ?? '';
  $shopifyRiskRec     = $order['risk_recommendation'] ?? '';
  $shopifyAssessments = $order['risk_assessments'] ?? [];

  $clientIp        = $order['client_ip'] ?? '';
  $isTest          = $order['test'] ?? false;
  $journey         = $order['customer_journey'] ?? null;
  $firstVisit      = $journey['first_visit'] ?? null;
  $lastVisit       = $journey['last_visit'] ?? null;
  $daysToConvert   = $journey['days_to_conversion'] ?? null;
  $hasFraud = $clientIp !== '' || $isTest || $firstVisit !== null || $lastVisit !== null;

  $sourceName = $order['source_name'] ?? '';
  $appName    = $order['app_name'] ?? '';
  $hasChannel = $sourceName !== '' || $appName !== '';

  $currentTotal = $order['current_total_price'] ?? null;
  $isEdited     = $order['edited'] ?? false;
  $gateways     = $order['payment_gateway_names'] ?? [];
  $poNumber     = $order['po_number'] ?? '';
  $hasFinance = $isEdited || !empty($gateways) || $poNumber !== '';

  $confirmationNum = $order['confirmation_number'] ?? '';
  $statusPageUrl    = $order['status_page_url'] ?? '';
  $customerLocale   = $order['customer_locale'] ?? '';
  $hasSupport = $confirmationNum !== '' || $statusPageUrl !== '' || $customerLocale !== '';

  $hasHiddenInfo = !empty($order['note']) || !empty($noteAttrs)
    || $riskScore !== null || $shopifyRiskLevel !== '' || !empty($shopifyAssessments)
    || $hasFraud || $hasChannel || $hasFinance || $hasSupport;
?>
<?php if ($hasHiddenInfo): ?>
<div class="table-wrap mb-4">
  <div class="table-header">
    <h2>Hidden / Internal Info</h2>
  </div>
  <div class="hi-panel" style="padding:1rem 1.25rem">

    <?php if ($isTest): ?>
      <div class="hi-banner hi-banner-danger">&#9888; TEST ORDER &mdash; do not fulfil</div>
    <?php endif; ?>

    <?php if (!empty($order['note'])): ?>
      <div class="hi-card">
        <div class="hi-card-title">Notes</div>
        <div class="hi-note-text"><?= nl2br(esc($order['note'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($noteAttrs)): ?>
      <div class="hi-card">
        <div class="hi-card-title">Additional details</div>
        <ul class="hi-list">
          <?php foreach ($noteAttrs as $attr): ?>
            <li class="hi-fact">
              <span class="hi-fact-label"><?= esc($attr['key']) ?></span>
              <span class="hi-fact-value"><?= esc($attr['value']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="hi-grid">
      <?php if ($shopifyRiskLevel !== '' || ($shopifyRiskRec !== '' && $shopifyRiskRec !== 'NONE') || !empty($shopifyAssessments)): ?>
        <div class="hi-card hi-card-full">
          <div class="hi-card-title">Shopify risk</div>
          <?php if ($shopifyRiskLevel !== ''): ?>
            <span class="chip <?= match ($shopifyRiskLevel) {
              'HIGH'   => 'chip-unpaid',
              'MEDIUM' => 'chip-partial',
              default  => 'chip-unknown',
            } ?>"><?= esc($shopifyRiskLevel) ?></span>
          <?php endif; ?>
          <?php if ($shopifyRiskRec !== '' && $shopifyRiskRec !== 'NONE'): ?>
            <span class="chip chip-unknown"><?= esc(ucfirst(strtolower($shopifyRiskRec))) ?></span>
          <?php endif; ?>
          <?php if (!empty($shopifyAssessments)): ?>
            <ul class="hi-list hi-list-cols mt-2">
              <?php foreach ($shopifyAssessments as $a): ?>
                <?php foreach (($a['facts'] ?? []) as $fact): ?>
                  <?php if (!empty($fact['description'])): ?>
                    <li><?= esc($fact['description']) ?><?= !empty($a['provider']['title']) ? ' (' . esc($a['provider']['title']) . ')' : '' ?></li>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($hasFraud && ($clientIp !== '' || $daysToConvert !== null || $firstVisit !== null || $lastVisit !== null)): ?>
        <div class="hi-card">
          <div class="hi-card-title">Fraud / attribution</div>
          <ul class="hi-list">
            <?php if ($clientIp !== ''): ?>
              <li class="hi-fact"><span class="hi-fact-label">IP address</span><span class="hi-fact-value"><?= esc($clientIp) ?></span></li>
            <?php endif; ?>
            <?php if ($daysToConvert !== null): ?>
              <li class="hi-fact"><span class="hi-fact-label">Days to conversion</span><span class="hi-fact-value"><?= (int)$daysToConvert ?></span></li>
            <?php endif; ?>
            <?php if ($firstVisit !== null && ($firstVisit['landing_page'] !== '' || $firstVisit['source'] !== '')): ?>
              <li>First visit: <?= esc($firstVisit['landing_page'] ?: '-') ?>
                <?php if ($firstVisit['source'] !== ''): ?> via <?= esc($firstVisit['source']) ?><?php endif; ?>
                <?php if (!empty($firstVisit['utm']['campaign'])): ?> (campaign: <?= esc($firstVisit['utm']['campaign']) ?>)<?php endif; ?>
              </li>
            <?php endif; ?>
            <?php if ($lastVisit !== null && ($lastVisit['landing_page'] !== '' || $lastVisit['source'] !== '')): ?>
              <li>Last visit: <?= esc($lastVisit['landing_page'] ?: '-') ?>
                <?php if ($lastVisit['source'] !== ''): ?> via <?= esc($lastVisit['source']) ?><?php endif; ?>
              </li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($hasChannel): ?>
        <div class="hi-card">
          <div class="hi-card-title">Channel / origin</div>
          <ul class="hi-list">
            <?php if ($sourceName !== ''): ?>
              <li class="hi-fact"><span class="hi-fact-label">Source</span><span class="hi-fact-value"><?= esc($sourceName) ?></span></li>
            <?php endif; ?>
            <?php if ($appName !== ''): ?>
              <li class="hi-fact"><span class="hi-fact-label">Created via</span><span class="hi-fact-value"><?= esc($appName) ?></span></li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($hasFinance): ?>
        <div class="hi-card">
          <div class="hi-card-title">Finance / edits</div>
          <?php if ($isEdited): ?>
            <div class="mb-2">
              <span class="chip chip-partial">Edited</span>
              <?php if ($currentTotal !== null): ?>
                <div class="hi-fact-value" style="text-align:left;margin-top:.3rem">$<?= number_format((float)$currentTotal, 2) ?> current &middot; $<?= number_format($total, 2) ?> original</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <ul class="hi-list">
            <?php if (!empty($gateways)): ?>
              <li class="hi-fact"><span class="hi-fact-label">Payment gateway<?= count($gateways) !== 1 ? 's' : '' ?></span><span class="hi-fact-value"><?= esc(implode(', ', $gateways)) ?></span></li>
            <?php endif; ?>
            <?php if ($poNumber !== ''): ?>
              <li class="hi-fact"><span class="hi-fact-label">PO number</span><span class="hi-fact-value"><?= esc($poNumber) ?></span></li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($riskScore !== null): ?>
        <div class="hi-card">
          <div class="hi-card-title">Our risk score</div>
          <span class="chip <?= match ($riskScore['level']) {
            'high'   => 'chip-unpaid',
            'medium' => 'chip-partial',
            default  => 'chip-unknown',
          } ?>"><?= esc(ucfirst($riskScore['level'])) ?> <?= (int)$riskScore['score'] ?></span>
          <?php if (!empty($riskScore['signals'])): ?>
            <ul class="hi-list mt-2">
              <?php foreach ($riskScore['signals'] as $s): ?>
                <li><?= esc($s['label']) ?> <span class="risk-pts">+<?= (int)$s['points'] ?></span></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($hasSupport): ?>
        <div class="hi-card">
          <div class="hi-card-title">Support</div>
          <ul class="hi-list">
            <?php if ($confirmationNum !== ''): ?>
              <li class="hi-fact"><span class="hi-fact-label">Confirmation #</span><span class="hi-fact-value"><?= esc($confirmationNum) ?></span></li>
            <?php endif; ?>
            <?php if ($customerLocale !== ''): ?>
              <li class="hi-fact"><span class="hi-fact-label">Checkout locale</span><span class="hi-fact-value"><?= esc($customerLocale) ?></span></li>
            <?php endif; ?>
            <?php if ($statusPageUrl !== ''): ?>
              <li><a href="<?= esc($statusPageUrl) ?>" target="_blank" rel="noopener">Order status page &rarr;</a></li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (empty($timeline)): ?>
  <div class="table-wrap">
    <div class="empty">
      <div class="icon">&#128197;</div>
      <h3>No timeline events</h3>
      <p>No events could be built for this order.</p>
    </div>
  </div>
<?php else: ?>

<div class="tl-wrap">
  <div class="tl-header">
    <h2>Timeline &mdash; <?= count($timeline) ?> event<?= count($timeline) !== 1 ? 's' : '' ?></h2>
    <button class="btn btn-sm btn-ghost" id="tl-copy-btn" onclick="copyTimeline()">Copy as text</button>
  </div>

  <ul class="tl-list" id="tl-list">
    <?php foreach ($timeline as $item):
      $type    = $item['type'];
      $source  = $item['source'];
      $tsFmt   = $item['ts_fmt'];
      $title   = $item['title'];
      $detail  = $item['detail'];
      $tracking = $item['tracking'];
      $url     = $item['url'];
    ?>
    <li class="tl-item"
        data-ts="<?= esc($tsFmt) ?>"
        data-title="<?= esc($title) ?>"
        data-detail="<?= esc($detail) ?>"
        data-source="<?= esc($source) ?>">
      <div class="tl-dot tl-dot-<?= esc($type) ?>"></div>
      <div class="tl-content">
        <div class="tl-time"><?= esc($tsFmt) ?>
          <span class="tl-source tl-source-<?= esc($source) ?>"><?= $source === 'shipstation' ? 'ShipStation' : 'Shopify' ?></span>
        </div>
        <div class="tl-title">
          <?php if ($url): ?>
            <a href="<?= esc($url) ?>" target="_blank" rel="noopener"><?= esc($title) ?></a>
          <?php else: ?>
            <?= esc($title) ?>
          <?php endif; ?>
        </div>
        <?php if ($detail): ?>
          <div class="tl-detail"><?= esc($detail) ?></div>
        <?php endif; ?>
        <?php if ($tracking): ?>
          <div class="tl-tracking"><?= esc($tracking) ?></div>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<script>
function copyTimeline() {
  var btn   = document.getElementById('tl-copy-btn');
  var items = document.querySelectorAll('#tl-list .tl-item');
  var lines = ['Order Timeline: <?= esc(addslashes($label)) ?>',
               'Generated: ' + new Date().toISOString().slice(0, 10), ''];

  items.forEach(function(el) {
    var ts     = el.dataset.ts     || '';
    var title  = el.dataset.title  || '';
    var detail = el.dataset.detail || '';
    var source = el.dataset.source === 'shipstation' ? '[SS]' : '[Shopify]';
    lines.push(ts + '  ' + source + '  ' + title + (detail ? '  -  ' + detail : ''));
  });

  navigator.clipboard.writeText(lines.join('\n')).then(function() {
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = 'Copy as text'; }, 2000);
  }).catch(function() {
    btn.textContent = 'Failed';
    setTimeout(function() { btn.textContent = 'Copy as text'; }, 2000);
  });
}
</script>

<?php endif; ?>
<?php endif; ?>
