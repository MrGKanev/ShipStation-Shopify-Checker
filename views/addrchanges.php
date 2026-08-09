<?= topbar('Address Changes', 'Orders whose shipping address was edited after the order was placed') ?>

<?= featureInfoStart('addrchanges', 'Address Changes') ?>
    <p><strong>Address Changes</strong> uses Shopify order events via GraphQL to find orders where the shipping address was modified after the order was placed. This is useful for catching last-minute customer requests, fraudulent address swaps, or support edits that may not have reached ShipStation in time.</p>
    <ul>
      <li>Only orders with an explicit <em>shipping address updated</em> event are returned - not just any order edit.</li>
      <li>The <strong>Changed</strong> column shows when the address was last modified, not when the order was created.</li>
      <li>Large date ranges may take longer as order events are paginated separately from orders.</li>
    </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Searches Shopify order events for shipping address changes in the selected window.</div>

  <?php if ($acError): ?>
    <div class="error-msg mb-3"><?= esc($acError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_addr_changes">
    <?php dateRangePartial('ac', $acStart, $acEnd) ?>
  </form>

  <?php if ($acResult !== null): ?>
    <div class="duration-note mt-4 mb-0">
      <span>Found <strong><?= count($acResult['rows']) ?></strong> order<?= count($acResult['rows']) !== 1 ? 's' : '' ?> with address changes
        (<?= esc($acResult['start']) ?> → <?= esc($acResult['end']) ?>)</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($acResult !== null): ?>
  <?php if (empty($acResult['rows'])): ?>
    <?= tableWrapEmpty('No address changes found', 'No shipping address edits detected in this date range.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($acResult['rows'], 'tbl-addrchanges', 'Address Changes', 'address-changes', $acResult['start']) ?>
      <table id="tbl-addrchanges">
        <thead>
          <tr>
            <th>Order</th>
            <th>Placed</th>
            <th>Changed</th>
            <th>Time gap</th>
            <th>Email</th>
            <th>Current shipping address</th>
            <th>Total</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($acResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $gap      = timeGapDisplay((int) $row['gap_mins']);
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td><?= esc($row['created_at']) ?></td>
            <td class="font-medium" style="color:var(--warn)"><?= esc($row['changed_at']) ?></td>
            <td class="text-sm font-medium" style="color:<?= $gap['color'] ?>"><?= esc($gap['label']) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-email">
              <?php if ($row['addr_name']): ?>
                <div class="font-medium"><?= esc($row['addr_name']) ?></div>
              <?php endif; ?>
              <?php if ($row['addr_line']): ?>
                <div class="text-xs text-muted"><?= esc($row['addr_line']) ?></div>
              <?php endif; ?>
            </td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <span class="chip chip-unknown capitalize"><?= esc($row['financial']) ?></span>
                <?php if ($row['fulfillment']): ?>
                  <span class="chip chip-unknown capitalize"><?= esc($row['fulfillment']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'shopifyLabel' => 'Edit in Shopify', 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
