<?= topbar('Returned Items Report', 'Itemized quantity totals for refunded line items in a date range') ?>

<?= featureInfoStart('returneditems', 'Returned Items Report') ?>
  <p><strong>Returned Items Report</strong> pulls Shopify orders refunded in the selected date range and sums refund line-item quantities per product/variant.</p>
  <ul>
    <li>Date range filters by order <strong>creation</strong> date (Shopify's search API doesn't support filtering by refund date).</li>
    <li>Useful for spotting which SKUs are coming back most - a signal for quality issues or restock decisions.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Run report</h2>
  <div class="hint">Fetches refunded Shopify orders by creation date and totals returned quantities. Requires Shopify credentials.</div>

  <?php if ($riError): ?>
    <div class="error-msg mb-3"><?= esc($riError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_returneditems">
    <?php dateRangePartial('ri', $riStart, $riEnd) ?>
  </form>

  <?php if ($riEmailMessage): ?>
    <div class="flash flash-ok mt-3 mb-0">✓ <?= esc($riEmailMessage) ?></div>
  <?php elseif ($riEmailError): ?>
    <div class="flash flash-err mt-3 mb-0">✗ <?= esc($riEmailError) ?></div>
  <?php endif; ?>

  <?php if ($riResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= (int)$riResult['scanned'] ?></strong> order<?= $riResult['scanned'] !== 1 ? 's' : '' ?> scanned
        &nbsp;(<?= esc($riResult['start']) ?> &rarr; <?= esc($riResult['end']) ?>)
      </span>
      <?php if (!empty($riResult['rows'])): ?>
        <span class="source-badge"><?= count($riResult['rows']) ?> product<?= count($riResult['rows']) !== 1 ? 's' : '' ?></span>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="email_returneditems">
        <input type="hidden" name="ri_start" value="<?= esc($riResult['start']) ?>">
        <input type="hidden" name="ri_end" value="<?= esc($riResult['end']) ?>">
        <button class="btn btn-sm btn-ghost" type="submit">Email CSV</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if ($riResult !== null): ?>
  <?php if (empty($riResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No returned items found</h3>
        <p>No refunded orders created in this range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader(
            $riResult['rows'],
            'tbl-returneditems',
            'Product Totals',
            'returned-items',
            $riResult['start'],
            'product',
            'Filter by product...'
          ) ?>
      <?= searchInput('tbl-returneditems', 'Filter by product...') ?>
      <table id="tbl-returneditems">
        <thead>
          <tr>
            <th>Product</th>
            <th>Quantity</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($riResult['rows'] as $row): ?>
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
