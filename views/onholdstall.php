<?= topbar('On-Hold Stall', 'Orders sitting on hold for too long') ?>

<?= featureInfoStart('onholdstall', 'On-Hold Stall') ?>
  <p><strong>On-Hold Stall</strong> finds Shopify orders that are currently in <em>on-hold</em> fulfillment status and were placed within the scanned date range.</p>
  <ul>
    <li>Uses the Shopify GraphQL <code>fulfillmentOrders</code> API, which requires the <code>read_merchant_managed_fulfillment_orders</code> or <code>read_assigned_fulfillment_orders</code> API scope.</li>
    <li>The <strong>Waiting</strong> column counts days since the order was placed - a proxy for how long it has been waiting.</li>
    <li>The <strong>Hold Reason</strong> is reported by Shopify (e.g. <code>MANUAL</code>, <code>HIGH_RISK_OF_FRAUD</code>, <code>AWAITING_PAYMENT</code>, etc.).</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Finds all on-hold fulfillment orders whose parent order was created in the range.</div>

  <?php if ($ohError): ?>
    <div class="error-msg mb-3"><?= esc($ohError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_onhold">
    <?php dateRangePartial('oh', $ohStart, $ohEnd) ?>
  </form>

  <?php if ($ohResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Found <strong><?= count($ohResult['rows']) ?></strong> on-hold order<?= count($ohResult['rows']) !== 1 ? 's' : '' ?>
        (<?= esc($ohResult['start']) ?> → <?= esc($ohResult['end']) ?>)</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($ohResult !== null): ?>
  <?php if (empty($ohResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No on-hold orders found</h3>
        <p>No fulfillment orders with on-hold status in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>On-Hold Orders</h2>
        <div class="flex items-center gap-2">
          <span><?= count($ohResult['rows']) ?> order<?= count($ohResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-onholdstall"
                  data-csv-filename="on-hold-<?= esc($ohResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-onholdstall', 'Filter by order #, email…') ?>
      <table id="tbl-onholdstall">
        <thead>
          <tr>
            <th>Order</th>
            <th>Placed</th>
            <th>Waiting</th>
            <th>Hold Reason</th>
            <th>Notes</th>
            <th>Email</th>
            <th>Total</th>
            <th>Financial</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ohResult['rows'] as $row):
            $adminUrl  = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $days      = (int)$row['days_waiting'];
            $daysColor = severityColor((float)$days, 30, 14);
            $reasonLabel = match(strtoupper($row['hold_reason'])) {
                'MANUAL'                  => 'Manual',
                'HIGH_RISK_OF_FRAUD'      => 'High fraud risk',
                'INCORRECT_ADDRESS'       => 'Incorrect address',
                'INVENTORY_OUT_OF_STOCK'  => 'Out of stock',
                'AWAITING_PAYMENT'        => 'Awaiting payment',
                'UNKNOWN'                 => 'Unknown',
                'UNFULFILLABLE_ADDRESS'   => 'Unfulfillable address',
                default                   => $row['hold_reason'] ?: '-',
            };
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td class="font-semibold" style="color:<?= $daysColor ?>"><?= $days ?>d</td>
            <td><span class="chip chip-unpaid"><?= esc($reasonLabel) ?></span></td>
            <td class="text-sm text-muted"><?= $row['hold_notes'] ? esc($row['hold_notes']) : '-' ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <td><span class="chip <?= financialChip($row['financial']) ?>"><?= esc($row['financial']) ?></span></td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'timeline' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
