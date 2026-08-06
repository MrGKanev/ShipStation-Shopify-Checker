<?= topbar('Fulfilled Items Report', 'Itemized quantity totals for orders fulfilled in a date range') ?>

<?= featureInfoStart('fulfilleditems', 'Fulfilled Items Report') ?>
  <p><strong>Fulfilled Items Report</strong> pulls Shopify orders created in the selected date range, keeps only those with fulfillment status <em>fulfilled</em>, and sums line-item quantities per product/variant.</p>
  <ul>
    <li>Date range filters by order <strong>creation</strong> date (Shopify's search API doesn't support filtering by fulfillment date).</li>
    <li>Partially fulfilled and unfulfilled orders are excluded.</li>
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
  </form>

  <?php if ($fiEmailMessage): ?>
    <div class="flash flash-ok mt-3 mb-0">✓ <?= esc($fiEmailMessage) ?></div>
  <?php elseif ($fiEmailError): ?>
    <div class="flash flash-err mt-3 mb-0">✗ <?= esc($fiEmailError) ?></div>
  <?php endif; ?>

  <?php if ($fiResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= (int)$fiResult['scanned'] ?></strong> order<?= $fiResult['scanned'] !== 1 ? 's' : '' ?> scanned
        &nbsp;(<?= esc($fiResult['start']) ?> &rarr; <?= esc($fiResult['end']) ?>)
      </span>
      <?php if (!empty($fiResult['rows'])): ?>
        <span class="source-badge"><?= count($fiResult['rows']) ?> product<?= count($fiResult['rows']) !== 1 ? 's' : '' ?></span>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="email_fulfilleditems">
        <input type="hidden" name="fi_start" value="<?= esc($fiResult['start']) ?>">
        <input type="hidden" name="fi_end" value="<?= esc($fiResult['end']) ?>">
        <button class="btn btn-sm btn-ghost" type="submit">Email CSV</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if ($fiResult !== null): ?>
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
            'Product Totals',
            'fulfilled-items',
            $fiResult['start'],
            'product',
            'Filter by product...'
          ) ?>
      <?= searchInput('tbl-fulfilleditems', 'Filter by product...') ?>
      <table id="tbl-fulfilleditems">
        <thead>
          <tr>
            <th>Product</th>
            <th>Quantity</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fiResult['rows'] as $row): ?>
          <tr>
            <td class="font-semibold"><?= esc($row['product']) ?></td>
            <td><?= (int)$row['quantity'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
