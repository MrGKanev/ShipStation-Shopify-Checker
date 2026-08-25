<?= topbar('Chargebacks / Disputes', 'Open Shopify Payments disputes needing evidence') ?>

<?= featureInfoStart('disputes', 'Chargebacks / Disputes') ?>
  <p><strong>Chargebacks / Disputes</strong> lists open Shopify Payments disputes - buyers who questioned a charge with their bank - sorted by how urgent the response deadline is.</p>
  <ul>
    <li>Only <strong>Needs Response</strong> (has a hard evidence deadline) and <strong>Under Review</strong> (evidence submitted, awaiting the card network) are shown. Resolved disputes (won, lost, accepted, prevented) are excluded.</li>
    <li><strong>Days Until Due</strong> is negative once the evidence deadline has passed - Shopify auto-accepts the dispute at that point, so these need immediate attention.</li>
    <li>Requires the <code>read_shopify_payments_disputes</code> access scope and a store on Shopify Payments. Stores without either simply show zero disputes, not an error.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan disputes</h2>
  <div class="hint">Fetches all open Shopify Payments disputes.</div>

  <?php if ($dpError): ?>
    <div class="error-msg mb-3"><?= esc($dpError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_disputes">
    <button class="btn" type="submit">Scan disputes</button>
  </form>

  <?php if ($dpResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $dpResult['scanned'] ?></strong> open dispute<?= $dpResult['scanned'] !== 1 ? 's' : '' ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($dpResult !== null): ?>
  <?php if (empty($dpResult['rows'])): ?>
    <?= tableWrapEmpty('No open disputes', 'No Shopify Payments disputes currently need a response.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Open Disputes</h2>
        <div class="flex items-center gap-2">
          <span><?= count($dpResult['rows']) ?> dispute<?= count($dpResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-disputes"
                  data-csv-filename="disputes.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-disputes', 'Filter by order #, status…') ?>
      <table id="tbl-disputes">
        <thead>
          <tr>
            <th>Order</th>
            <th>Status</th>
            <th>Reason</th>
            <th>Amount</th>
            <th>Initiated</th>
            <th>Days Until Due</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dpResult['rows'] as $row):
            $adminUrl = $row['order_id'] ? $shopifyAdminBase . '/' . esc((string)$row['order_id']) : null;
            $due = $row['days_until_due'];
            $dueColor = severityColor($due !== null ? (float)$due : null, 3, 7, lowerIsWorse: true);
          ?>
          <tr>
            <td class="text-sm">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($row['order_name']) ?></a>
              <?php else: ?>
                <?= esc($row['order_name'] ?: '-') ?>
              <?php endif; ?>
            </td>
            <td class="text-sm"><span class="chip chip-unknown capitalize"><?= esc(str_replace('_', ' ', $row['status'])) ?></span></td>
            <td class="text-sm capitalize"><?= esc(str_replace('_', ' ', $row['reason'])) ?></td>
            <td class="text-sm"><?= formatPrice($row['amount']) ?> <span class="text-muted"><?= esc($row['currency']) ?></span></td>
            <td class="text-sm text-muted"><?= esc(substr($row['initiated_at'], 0, 10)) ?></td>
            <td class="text-sm" style="color:<?= $dueColor ?>">
              <?= $due !== null ? esc((string)$due) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
