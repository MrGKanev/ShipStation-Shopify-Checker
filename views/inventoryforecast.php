<?= topbar('Inventory Forecast', 'Days until zero stock based on 30-day sell-through rate per SKU') ?>

<?= featureInfoStart('inventoryforecast', 'Inventory Forecast') ?>
  <p><strong>Inventory Forecast</strong> calculates the projected days until each tracked variant runs out of stock, based on the last 30 days of actual sales.</p>
  <ul>
    <li>Only variants with <strong>inventory tracking enabled</strong> and a <strong>deny</strong> oversell policy are included.</li>
    <li><strong>Daily Rate</strong> = units sold in the last 30 days &divide; 30.</li>
    <li><strong>Days to Zero</strong> = current stock &divide; daily rate. Blank means either no sales or stock is already zero.</li>
    <li><span style="color:var(--danger);font-weight:600">Red</span> = fewer than 7 days of stock remaining.
        <span style="color:var(--warn);font-weight:600">Yellow</span> = 7–13 days.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Run forecast</h2>
  <div class="hint">Fetches all active products and the last 30 days of orders. No date range needed.</div>

  <?php if ($ifError): ?>
    <div class="error-msg mb-3"><?= esc($ifError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_inventoryforecast">
    <div class="date-row">
      <button class="btn btn-submit-end" type="submit">Run forecast</button>
    </div>
  </form>

  <?php if ($ifResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= (int)$ifResult['products'] ?></strong> products &middot;
        <strong><?= (int)$ifResult['variants'] ?></strong> variants &middot;
        <strong><?= (int)$ifResult['orders'] ?></strong> orders
        (<?= esc($ifResult['start']) ?> &rarr; <?= esc($ifResult['end']) ?>)
      </span>
      <?php if ($ifResult['critical'] > 0): ?>
        <span class="refund-risk-badge refund-risk-active"><?= $ifResult['critical'] ?> critical (&lt;7 days)</span>
      <?php endif; ?>
      <?php if ($ifResult['warning'] > 0): ?>
        <span class="source-badge" style="background:#fef9c3;color:#854d0e"><?= $ifResult['warning'] ?> low stock (&lt;14 days)</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($ifResult !== null): ?>
  <?php if (empty($ifResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No stock-out risk detected</h3>
        <p>All tracked variants have sufficient stock relative to their recent sell-through rate.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Inventory Forecast by SKU</h2>
        <div class="flex items-center gap-2">
          <span><?= count($ifResult['rows']) ?> variant<?= count($ifResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-inventoryforecast"
                  data-csv-filename="inventory-forecast-<?= esc($ifResult['end']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-inventoryforecast', 'Filter by SKU or product...') ?>
      <table id="tbl-inventoryforecast">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Product</th>
            <th>Variant</th>
            <th>Current Stock</th>
            <th>Sold (30 days)</th>
            <th>Daily Rate</th>
            <th>Days to Zero</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ifResult['rows'] as $row): ?>
          <?php
            $dtz = $row['days_to_zero'];
            if ($dtz !== null && $dtz < 7) {
                $rowStyle = 'background:rgba(220,38,38,.07)';
                $dtzStyle = 'color:var(--danger);font-weight:600';
            } elseif ($dtz !== null && $dtz < 14) {
                $rowStyle = 'background:rgba(202,138,4,.07)';
                $dtzStyle = 'color:var(--warn);font-weight:600';
            } else {
                $rowStyle = '';
                $dtzStyle = '';
            }
            $productUrl = $row['product_id']
              ? 'https://' . (str_contains($shopifyStore, '.') ? $shopifyStore : $shopifyStore . '.myshopify.com')
                . '/admin/products/' . $row['product_id']
              : null;
          ?>
          <tr style="<?= $rowStyle ?>">
            <td class="font-mono text-sm"><?= esc($row['sku']) ?></td>
            <td>
              <?php if ($productUrl): ?>
                <a href="<?= esc($productUrl) ?>" target="_blank" rel="noopener"><?= esc($row['product_title']) ?></a>
              <?php else: ?>
                <?= esc($row['product_title']) ?>
              <?php endif; ?>
            </td>
            <td class="text-sm text-muted"><?= esc($row['variant_title']) ?></td>
            <td class="<?= $row['stock'] <= 0 ? 'font-semibold' : '' ?>"
                style="<?= $row['stock'] <= 0 ? 'color:var(--danger)' : '' ?>">
              <?= (int)$row['stock'] ?>
            </td>
            <td><?= (int)$row['sold_30d'] ?></td>
            <td class="font-mono text-sm"><?= number_format($row['daily_rate'], 2) ?>/day</td>
            <td style="<?= $dtzStyle ?>">
              <?php if ($dtz === null): ?>
                <span class="text-muted">—</span>
              <?php elseif ($row['stock'] <= 0): ?>
                <span class="refund-risk-badge refund-risk-active">Out of stock</span>
              <?php else: ?>
                <?= $dtz ?> day<?= $dtz !== 1 ? 's' : '' ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
