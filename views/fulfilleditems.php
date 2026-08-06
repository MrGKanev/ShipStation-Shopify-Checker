<?= topbar('Fulfilled Items Report', 'Itemized quantity totals for orders fulfilled in a date range') ?>

<?= featureInfoStart('fulfilleditems', 'Fulfilled Items Report') ?>
  <p><strong>Fulfilled Items Report</strong> pulls Shopify orders created in the selected date range, keeps only those with fulfillment status <em>fulfilled</em>, and sums line-item quantities per product/variant.</p>
  <ul>
    <li>Date range filters by order <strong>creation</strong> date (Shopify's search API doesn't support filtering by fulfillment date).</li>
    <li>Partially fulfilled and unfulfilled orders are excluded.</li>
    <li>Enable <strong>Show order #</strong> to show each fulfilled order's item rows instead of summing across the whole range.</li>
    <li>Use <strong>Group by product</strong> to total each product and list the orders that contain it.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Run report</h2>
  <div class="hint">Fetches Shopify orders by creation date and totals quantities for fulfilled orders. Requires Shopify credentials.</div>

  <?php if ($fiError): ?>
    <div class="error-msg mb-3"><?= esc($fiError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_fulfilleditems">
    <?php dateRangePartial('fi', $fiStart, $fiEnd) ?>
    <div class="filter-row">
      <label class="toggle-pill">
        <input type="checkbox" name="fi_show_orders" value="1"<?= $fiShowOrders ? ' checked' : '' ?>>
        <span class="toggle-pill-track"><span class="toggle-pill-thumb"></span></span>
        <span class="toggle-pill-label">Show order #</span>
      </label>
      <label class="toggle-pill">
        <input type="checkbox" name="fi_group_products" value="1"<?= $fiGroupProducts ? ' checked' : '' ?>>
        <span class="toggle-pill-track"><span class="toggle-pill-thumb"></span></span>
        <span class="toggle-pill-label">Group by product</span>
      </label>
    </div>
  </form>

  <?php if ($fiEmailMessage): ?>
    <div class="flash flash-ok mt-3 mb-0">✓ <?= esc($fiEmailMessage) ?></div>
  <?php elseif ($fiEmailError): ?>
    <div class="flash flash-err mt-3 mb-0">✗ <?= esc($fiEmailError) ?></div>
  <?php endif; ?>

  <?php if ($fiResult !== null): ?>
    <?php
      $fiResultMode = $fiResult['mode'] ?? (!empty($fiResult['byOrder']) ? 'by_order' : 'summary');
      $fiResultByOrder = $fiResultMode === 'by_order';
      $fiResultGrouped = $fiResultMode === 'grouped';
    ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= (int)$fiResult['scanned'] ?></strong> order<?= $fiResult['scanned'] !== 1 ? 's' : '' ?> scanned
        &nbsp;(<?= esc($fiResult['start']) ?> &rarr; <?= esc($fiResult['end']) ?>)
      </span>
      <?php if (!empty($fiResult['rows'])): ?>
        <?php $fiRowLabel = $fiResultByOrder ? 'line' : 'product'; ?>
        <span class="source-badge"><?= count($fiResult['rows']) ?> <?= $fiRowLabel ?><?= count($fiResult['rows']) !== 1 ? 's' : '' ?></span>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="email_fulfilleditems">
        <input type="hidden" name="fi_start" value="<?= esc($fiResult['start']) ?>">
        <input type="hidden" name="fi_end" value="<?= esc($fiResult['end']) ?>">
        <input type="hidden" name="fi_mode" value="<?= esc($fiResultMode) ?>">
        <button class="btn btn-sm btn-ghost" type="submit">Email CSV</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if ($fiResult !== null): ?>
  <?php
    $fiResultMode = $fiResult['mode'] ?? (!empty($fiResult['byOrder']) ? 'by_order' : 'summary');
    $fiResultByOrder = $fiResultMode === 'by_order';
    $fiResultGrouped = $fiResultMode === 'grouped';
  ?>
  <?php if (empty($fiResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No fulfilled orders found</h3>
        <p>No orders with fulfillment status "fulfilled" created in this range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader(
            $fiResult['rows'],
            'tbl-fulfilleditems',
            $fiResultGrouped ? 'Grouped Product Totals' : ($fiResultByOrder ? 'Fulfilled Items' : 'Product Totals'),
            'fulfilled-items',
            $fiResult['start'],
            $fiResultByOrder ? 'line' : 'product',
            $fiResultByOrder || $fiResultGrouped ? 'Filter by order # or product...' : 'Filter by product...'
          ) ?>
      <table id="tbl-fulfilleditems">
        <thead>
          <tr>
            <?php if ($fiResultByOrder): ?>
              <th>Order</th>
            <?php endif; ?>
            <th>Product</th>
            <th>Quantity</th>
            <?php if ($fiResultGrouped): ?>
              <th>Orders</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fiResult['rows'] as $row): ?>
          <tr>
            <?php if ($fiResultByOrder): ?>
              <td><?= esc($row['order']) ?></td>
            <?php endif; ?>
            <td class="font-semibold"><?= esc($row['product']) ?></td>
            <td><?= (int)$row['quantity'] ?></td>
            <?php if ($fiResultGrouped): ?>
              <td><?= esc($row['orders']) ?></td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
