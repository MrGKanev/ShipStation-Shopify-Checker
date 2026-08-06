<?= topbar('Inventory Oversell Risk', 'SKUs where ShipStation awaiting qty exceeds available Shopify stock') ?>

<?= featureInfoStart('inventoryoversell', 'Inventory Oversell Risk') ?>
  <p><strong>Inventory Oversell Risk</strong> compares your current Shopify inventory levels against orders currently in <em>Awaiting Shipment</em> status in ShipStation.</p>
  <ul>
    <li>Only tracks variants where Shopify <strong>inventory management is enabled</strong> and the policy is set to <strong>deny</strong> (no overselling). Variants set to "continue selling when out of stock" are excluded.</li>
    <li>A row appears when the number of units committed in ShipStation exceeds the stock level recorded in Shopify - meaning at least one order cannot be fulfilled from current stock.</li>
    <li>The <strong>Shortfall</strong> column shows how many units are missing.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Check current stock</h2>
  <div class="hint">Fetches all active products from Shopify and all awaiting orders from ShipStation - no date range needed.</div>

  <?php if ($ioError): ?>
    <div class="error-msg mb-3"><?= esc($ioError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_inventory">
    <div class="date-row">
      <button class="btn btn-submit-end" type="submit">Scan now</button>
    </div>
  </form>

  <?php if ($ioResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Checked <strong><?= $ioResult['products_scanned'] ?></strong> active products
        against <strong><?= $ioResult['ss_orders'] ?></strong> awaiting ShipStation orders</span>
      <?php if (count($ioResult['rows']) > 0): ?>
        <span class="refund-risk-badge refund-risk-active"><?= count($ioResult['rows']) ?> SKU<?= count($ioResult['rows']) !== 1 ? 's' : '' ?> at risk</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($ioResult !== null): ?>
  <?php if (empty($ioResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No oversell risk detected</h3>
        <p>All tracked SKUs have enough stock to cover awaiting ShipStation orders.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Oversell Risk by SKU</h2>
        <div class="flex items-center gap-2">
          <span><?= count($ioResult['rows']) ?> SKU<?= count($ioResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-inventoryoversell"
                  data-csv-filename="inventory-oversell.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-inventoryoversell">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Product</th>
            <th>Variant</th>
            <th>Shopify Stock</th>
            <th>SS Awaiting</th>
            <th>Shortfall</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ioResult['rows'] as $row): ?>
          <tr>
            <td class="font-mono text-sm"><?= esc($row['sku']) ?></td>
            <td>
              <?php if ($row['duplicate_sku']): ?>
                <span class="text-sm text-muted"><?= esc($row['product_title']) ?></span>
              <?php elseif ($row['product_id']): ?>
                <a href="<?= esc('https://' . (str_contains($shopifyStore, '.') ? $shopifyStore : $shopifyStore . '.myshopify.com') . '/admin/products/' . $row['product_id']) ?>"
                   target="_blank" rel="noopener"><?= esc($row['product_title']) ?></a>
              <?php else: ?>
                <?= esc($row['product_title']) ?>
              <?php endif; ?>
            </td>
            <td class="text-sm text-muted"><?= esc($row['variant_title']) ?></td>
            <td class="<?= $row['stock'] <= 0 ? 'font-semibold' : '' ?>" style="color:<?= $row['stock'] <= 0 ? 'var(--danger)' : 'inherit' ?>">
              <?= (int)$row['stock'] ?>
            </td>
            <td class="font-semibold"><?= (int)$row['awaiting'] ?></td>
            <td>
              <span class="refund-risk-badge refund-risk-active">&minus;<?= (int)$row['shortfall'] ?></span>
            </td>
            <td class="td-actions">
              <?php if ($row['duplicate_sku']): ?>
                <a class="ignore-btn" href="?page=skudupes">Check duplicates</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
