<?php
$importedCount = isset($_GET['imported']) ? (int) $_GET['imported'] : null;
$totalIgnored  = count($ignoredOrders);

// Sort ignored orders by ignored_at desc
$sortedIgnored = $ignoredOrders;
uasort($sortedIgnored, fn($a, $b) => strcmp($b['ignored_at'] ?? '', $a['ignored_at'] ?? ''));
?>

<div class="topbar">
  <div>
    <h1>Ignored Orders</h1>
    <div class="meta"><?= $totalIgnored ?> order<?= $totalIgnored !== 1 ? 's' : '' ?> excluded from audits</div>
  </div>
</div>

<?php if ($importedCount !== null): ?>
  <div class="flush-notice" style="margin-bottom:1.25rem">
    Imported <?= $importedCount ?> order<?= $importedCount !== 1 ? 's' : '' ?> from CSV.
  </div>
<?php endif; ?>

<!-- CSV Import -->
<div class="run-form" style="margin-bottom:1.5rem">
  <h2>Bulk Import via CSV</h2>
  <div class="hint" style="margin-bottom:1rem">
    Upload a CSV file with order numbers in the first column (one per row). A header row is automatically detected and skipped.
  </div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="import_ignore_csv">
    <div class="date-row" style="align-items:flex-end">
      <div class="field" style="flex:2">
        <label>CSV file</label>
        <input type="file" name="ignore_csv" accept=".csv,text/csv" required
               style="padding:.55rem .9rem;font-size:.875rem">
      </div>
      <div class="field" style="flex:2">
        <label>Reason (optional)</label>
        <input type="text" name="import_reason" placeholder="e.g. Promo orders, already handled">
      </div>
      <button class="btn" type="submit" style="flex-shrink:0">Import</button>
    </div>
  </form>
</div>

<!-- Ignored Orders Table -->
<?php if ($totalIgnored === 0): ?>
  <div class="no-reports">
    <div class="icon">📋</div>
    <h2>Nothing ignored yet</h2>
    <p>Ignored orders are excluded from all audits. Use the Ignore button on any missing order to add one.</p>
  </div>
<?php else: ?>

<form method="post" id="bulk-unignore-form">
  <input type="hidden" name="action" value="bulk_ignore_orders">
  <?php /* We'll repurpose bulk for unignore via a separate action below */ ?>
</form>

<form method="post" id="unignore-form">
  <input type="hidden" name="action" value="bulk_unignore_orders">
  <div class="bulk-bar" id="bar-ignored" style="display:flex">
    <span class="bulk-count" id="cnt-ignored">Select to bulk-unignore</span>
    <button class="btn btn-sm btn-ghost" type="submit">Unignore selected</button>
    <button class="btn btn-sm btn-ghost" type="button"
            onclick="document.querySelectorAll('#ignored-tbody .js-row-check').forEach(c=>c.checked=false);updateBulkBar('ignored')">
      Clear
    </button>
  </div>

  <div class="table-wrap">
    <div class="table-header">
      <h2>All Ignored Orders</h2>
      <span><?= $totalIgnored ?></span>
    </div>
    <table>
      <thead>
        <tr>
          <th style="width:32px">
            <input type="checkbox" class="js-select-all" data-target="ignored-tbody" data-bar="ignored" title="Select all">
          </th>
          <th>Order #</th>
          <th>Ignored on</th>
          <th>Reason</th>
          <th>Seen in reports</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="ignored-tbody">
        <?php foreach ($sortedIgnored as $normNum => $info):
          $reason    = $info['reason'] ?? '';
          $ignoredAt = $info['ignored_at'] ?? '—';
          $history   = $orderHistory[$normNum] ?? null;
          $seenCount = $history['count'] ?? '—';
        ?>
        <tr>
          <td>
            <input type="checkbox" class="js-row-check" name="order_numbers[]"
                   value="<?= esc($normNum) ?>" data-bar="ignored"
                   onchange="updateBulkBar('ignored')">
          </td>
          <td><span class="order-num">#<?= esc($normNum) ?></span></td>
          <td style="color:var(--muted)"><?= esc($ignoredAt) ?></td>
          <td style="color:var(--muted)"><?= $reason !== '' ? esc($reason) : '<em style="opacity:.5">—</em>' ?></td>
          <td>
            <?php if (is_int($seenCount)): ?>
              <?php if ($seenCount >= 3): ?>
                <span class="seen-badge seen-hot"><?= $seenCount ?>×</span>
              <?php elseif ($seenCount >= 2): ?>
                <span class="seen-badge seen-warn"><?= $seenCount ?>×</span>
              <?php else: ?>
                <span style="color:var(--muted);font-size:.78rem">1×</span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--muted);font-size:.78rem">—</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="unignore_order">
              <input type="hidden" name="order_number" value="<?= esc($normNum) ?>">
              <input type="hidden" name="redirect_page" value="ignored">
              <button class="unignore-btn" type="submit">Unignore</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</form>

<?php endif; ?>
