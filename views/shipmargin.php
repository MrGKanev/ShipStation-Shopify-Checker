<?= topbar('Shipping Margin Erosion', 'Orders where the ShipStation label cost exceeds what the customer paid for shipping') ?>

<?= featureInfoStart('shipmargin', 'Shipping Margin Erosion') ?>
  <p><strong>Shipping Margin Erosion</strong> compares what ShipStation actually charged to ship a package against what the customer paid for shipping at checkout in Shopify.</p>
  <ul>
    <li><strong>Ship Cost</strong> is the ShipStation label cost plus insurance (<code>shipmentCost + insuranceCost</code>). <strong>Shipping Charged</strong> is the sum of the order's Shopify shipping line prices.</li>
    <li>A row only appears when <strong>Loss</strong> (Ship Cost − Shipping Charged) exceeds the configured threshold - small, expected differences are filtered out by default.</li>
    <li>Common causes: free-shipping promos on heavy/bulky items, underpriced flat-rate options, or carrier zone surcharges that weren't priced into checkout.</li>
    <li>Voided shipments are excluded - they were never actually charged.</li>
    <li>Only ShipStation shipments that match a Shopify order by order number are checked.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches ShipStation shipments by ship date and compares label cost against Shopify shipping charged. Requires ShipStation credentials.</div>

  <?php if ($smError): ?>
    <div class="error-msg mb-3"><?= esc($smError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_shipmargin">
    <?php dateRangePartial('sm', $smStart, $smEnd, '<div class="field"><label>Loss threshold ($)</label><input type="number" name="sm_threshold" value="' . (int)$smThreshold . '" min="1" style="width:80px"></div>') ?>
  </form>

  <?php if ($smResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= (int)$smResult['scanned'] ?></strong> shipment<?= $smResult['scanned'] !== 1 ? 's' : '' ?> scanned
        &mdash; <strong><?= count($smResult['rows']) ?></strong> shipped at a loss over $<?= (int)$smResult['threshold'] ?>
        (<?= esc($smResult['start']) ?> → <?= esc($smResult['end']) ?>)
      </span>
    </div>
  <?php endif; ?>
</div>

<?php if ($smResult !== null): ?>
  <?php if (empty($smResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No margin-eroding shipments found</h3>
        <p>No matched ShipStation shipments exceeded the loss threshold in this range.</p>
      </div>
    </div>
  <?php else: ?>
    <?php if (!empty($smResult['by_carrier'])): ?>
    <div class="table-wrap mb-4">
      <div class="table-header">
        <h2>Loss by Carrier</h2>
      </div>
      <table id="tbl-shipmargin-carrier">
        <thead>
          <tr>
            <th>Carrier</th>
            <th>Orders</th>
            <th>Total Loss</th>
            <th>Avg Loss</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($smResult['by_carrier'] as $c): ?>
          <tr>
            <td class="font-semibold"><?= esc($c['carrier']) ?></td>
            <td><?= (int)$c['count'] ?></td>
            <td class="td-price" style="color:var(--danger)"><?= formatPrice($c['total_loss']) ?></td>
            <td class="td-price"><?= formatPrice($c['avg_loss']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
      <div class="table-header">
        <h2>Orders Shipped at a Loss</h2>
        <div class="flex items-center gap-2">
          <span><?= count($smResult['rows']) ?> order<?= count($smResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-shipmargin"
                  data-csv-filename="shipping-margin-<?= esc($smResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-shipmargin', 'Filter by order #, carrier, email…') ?>
      <table id="tbl-shipmargin">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Ship Date</th>
            <th>Carrier / Service</th>
            <th>Ship Cost</th>
            <th>Shipping Charged</th>
            <th>Loss</th>
            <th>Email</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($smResult['rows'] as $row):
            $shopifyUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $orderNum   = ltrim((string)($row['order_number'] ?? ''), '#');
          ?>
          <tr>
            <?= orderNumCell((string)$row['order_number'], $row['ss_url'], $orderNum) ?>
            <td class="text-sm"><?= esc($row['ship_date']) ?></td>
            <td><?= esc($row['carrier']) ?><?= $row['service'] ? ' / ' . esc($row['service']) : '' ?></td>
            <td class="td-price"><?= formatPrice($row['ship_cost']) ?></td>
            <td class="td-price"><?= formatPrice($row['shipping_charged']) ?></td>
            <td class="td-price font-semibold" style="color:var(--danger)"><?= formatPrice($row['loss']) ?></td>
            <td class="td-email"><?= esc($row['email'] ?: '-') ?></td>
            <td class="td-price"><?= formatPrice($row['total'] ?: null) ?></td>
            <?= actionLinks(['ssUrl' => $row['ss_url'], 'shopifyUrl' => $shopifyUrl, 'orderNum' => $orderNum, 'spotcheck' => true, 'timeline' => true, 'email' => $row['email'] ?? '']) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
