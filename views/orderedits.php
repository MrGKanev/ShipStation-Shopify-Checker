<?= topbar('Order Edit History', 'Orders edited after placement - line items, discounts, notes, custom attributes') ?>

<?= featureInfoStart('orderedits', 'Order Edit History') ?>
  <p><strong>Order Edit History</strong> uses Shopify order events via GraphQL to find orders that were modified after they were placed. It detects changes to line items (added/removed), discounts, order notes, and custom attributes.</p>
  <ul>
    <li>The <strong>Edit summary</strong> column shows the actual event messages Shopify logged for each change.</li>
    <li>The <strong>Time gap</strong> column shows how long after placement the most recent edit occurred.</li>
    <li>Address changes are shown on the separate <a href="?page=addrchanges">Address Changes</a> page.</li>
    <li>Large date ranges may be slower because order events are paginated separately from orders.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Searches Shopify order events for post-placement edits in the selected window.</div>

  <?php if ($oeError): ?>
    <div class="error-msg mb-3"><?= esc($oeError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_order_edits">
    <?php dateRangePartial('oe', $oeStart, $oeEnd) ?>
  </form>

  <?php if ($oeResult !== null): ?>
    <div class="duration-note mt-4 mb-0">
      <span>Found <strong><?= count($oeResult['rows']) ?></strong> edited order<?= count($oeResult['rows']) !== 1 ? 's' : '' ?>
        (<?= esc($oeResult['start']) ?> → <?= esc($oeResult['end']) ?>)</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($oeResult !== null): ?>
  <?php if (empty($oeResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No order edits found</h3>
        <p>No post-placement edits detected in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Edited Orders</h2>
        <div class="flex items-center gap-2">
          <span><?= count($oeResult['rows']) ?> order<?= count($oeResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-orderedits"
                  data-csv-filename="order-edits-<?= esc($oeResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-orderedits', 'Filter by order #, email…') ?>
      <table id="tbl-orderedits">
        <thead>
          <tr>
            <th>Order</th>
            <th>Placed</th>
            <th>Last edit</th>
            <th>Time gap</th>
            <th>Edit summary</th>
            <th>Email</th>
            <th>Total</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($oeResult['rows'] as $row):
            $adminUrl  = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            $gap       = timeGapDisplay((int) $row['diff_mins']);
            $gapLabel  = $gap['label'];
            $gapColor  = $gap['color'];
          ?>
          <tr>
            <td class="order-num">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($row['order_number']) ?></a>
              <?php else: ?>
                <?= esc($row['order_number']) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc(ltrim($row['order_number'], '#')) ?>" title="Copy">⧉</button>
            </td>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td class="text-sm font-medium" style="color:var(--warn)"><?= esc($row['edited_at']) ?></td>
            <td class="text-sm font-medium" style="color:<?= $gapColor ?>"><?= esc($gapLabel) ?></td>
            <td class="text-xs text-muted" style="max-width:220px">
              <?php foreach (($row['edit_summary'] ?? []) as $msg): ?>
                <div><?= esc($msg) ?></div>
              <?php endforeach; ?>
            </td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <span class="chip <?= financialChip($row['financial']) ?> capitalize"><?= esc($row['financial']) ?></span>
                <?php if ($row['fulfillment']): ?>
                  <span class="chip chip-unknown capitalize"><?= esc($row['fulfillment']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="td-actions">
              <?php if ($adminUrl): ?>
                <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener">Edit in Shopify</a>
              <?php endif; ?>
              <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode(ltrim($row['order_number'], '#')) ?>">Spot-check</a>
              <a class="ignore-btn" href="?page=timeline&prefill=<?= urlencode(ltrim($row['order_number'], '#')) ?>">Timeline</a>
              <?php if ($row['email']): ?>
                <a class="ignore-btn" href="?page=customer&email=<?= urlencode($row['email']) ?>">Customer</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
