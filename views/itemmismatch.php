<?= topbar('Shipped Item Mismatch', 'ShipStation shipped items that don\'t match what was ordered in Shopify') ?>

<?= featureInfoStart('itemmismatch', 'Shipped Item Mismatch') ?>
  <p><strong>Shipped Item Mismatch</strong> checks what ShipStation actually shipped against what the customer ordered in Shopify, at the SKU and quantity level.</p>
  <ul>
    <li>The standard audit only confirms an order exists in both systems - it never checks whether the physical contents match. This scan closes that gap.</li>
    <li>Especially valuable for multi-part bundles: a picker grabbing the wrong variant or forgetting an accessory goes undetected today. This scan catches it.</li>
    <li>Only ShipStation orders marked <em>shipped</em> are checked. Cancelled, refunded/voided, or zero-value Shopify orders are excluded - they were never expected to ship correctly.</li>
    <li><strong>Missing Required</strong> rows are the most urgent: a bundle accessory the customer ordered was left out of the shipment, even though the order itself was correctly configured.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Compares ShipStation shipped item contents against Shopify ordered line items for the same range.</div>

  <?php if ($imError): ?>
    <div class="error-msg mb-3"><?= esc($imError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_itemmismatch">
    <?php dateRangePartial('im', $imStart, $imEnd) ?>
  </form>

  <?php if ($imResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= $imResult['scanned'] ?></strong> SS orders scanned
        &mdash; <strong><?= count($imResult['rows']) ?></strong> with a shipped-vs-ordered mismatch
        (<?= esc($imResult['start']) ?> → <?= esc($imResult['end']) ?>)
      </span>
    </div>
  <?php endif; ?>
</div>

<?php if ($imResult !== null): ?>
  <?php if (empty($imResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>Everything shipped matches what was ordered</h3>
        <p>No SKU or quantity mismatches found between ShipStation shipments and Shopify orders in this range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Item Mismatches</h2>
        <div class="flex items-center gap-2">
          <span><?= count($imResult['rows']) ?> order<?= count($imResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-itemmismatch"
                  data-csv-filename="item-mismatch-<?= esc($imResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-itemmismatch', 'Filter by order #, email…') ?>
      <table id="tbl-itemmismatch">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Date</th>
            <th>Email</th>
            <th>Order Type</th>
            <th>Missing</th>
            <th>Extra</th>
            <th>Missing Required</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($imResult['rows'] as $row):
            $shopifyUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $orderNum   = ltrim((string)($row['order_number'] ?? ''), '#');
            $fmtQty     = fn(array $skus) => implode(', ', array_map(
                fn($sku, $qty) => esc($sku) . ': ' . $qty,
                array_keys($skus), array_values($skus)
            ));
          ?>
          <tr>
            <?= orderNumCell((string)$row['order_number'], $shopifyUrl, $orderNum) ?>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td class="td-email"><?= esc($row['email'] ?: '-') ?></td>
            <td><span class="chip chip-unknown"><?= esc($row['order_type']) ?></span></td>
            <td><?php if ($row['missing']): ?><span class="chip chip-warn">⚠ <?= $fmtQty($row['missing']) ?></span><?php else: ?>-<?php endif; ?></td>
            <td><?php if ($row['extra']): ?><span class="chip chip-unpaid"><?= $fmtQty($row['extra']) ?></span><?php else: ?>-<?php endif; ?></td>
            <td>
              <?php if ($row['missing_required']): ?>
                <?php foreach ($row['missing_required'] as $label): ?>
                  <span class="chip chip-warn">🚨 <?= esc($label) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td class="td-price"><?= formatPrice($row['total'] ?: null) ?></td>
            <?= actionLinks(['ssUrl' => $row['ss_url'], 'shopifyUrl' => $shopifyUrl, 'orderNum' => $orderNum, 'spotcheck' => true, 'timeline' => true, 'email' => $row['email'] ?? '']) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
