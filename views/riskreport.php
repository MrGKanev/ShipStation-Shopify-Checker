<?= topbar('Fraud Risk Report', 'Paid orders scored by combined fraud signals') ?>

<?= featureInfoStart('riskreport', 'Fraud Risk Report') ?>
  <p><strong>Fraud Risk Report</strong> scores every paid order in the selected date range using the same composite risk model as Spot-check and Metafields search, then lists everything above <em>low</em> risk.</p>
  <ul>
    <li>Signals: disposable/invalid email, billing ≠ shipping country, missing phone on a high-value order, PO Box address, partially paid, a fraud/high-risk tag, Shopify's own HIGH risk assessment, or no shipping address at all.</li>
    <li>Only <strong>medium</strong> (score ≥ 21) and <strong>high</strong> (score ≥ 51) orders are shown - <em>low</em> is too noisy to review at scale.</li>
    <li>Click a badge to expand the exact signals that contributed to the score.</li>
    <li>Custom signal weights can be set in <code>data/risk_weights.json</code> (copy from the <code>.example</code> file).</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid orders and scores each one with the combined fraud risk model.</div>

  <?php if ($frError): ?>
    <div class="error-msg mb-3"><?= esc($frError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_riskreport">
    <?php dateRangePartial('fr', $frStart, $frEnd) ?>
  </form>

  <?php if ($frResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $frResult['scanned'] ?></strong> paid order<?= $frResult['scanned'] !== 1 ? 's' : '' ?>
        (<?= esc($frResult['start']) ?> → <?= esc($frResult['end']) ?>)
        &mdash; <strong><?= count($frResult['rows']) ?></strong> flagged</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($frResult !== null): ?>
  <?php if (empty($frResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No elevated-risk orders</h3>
        <p>Every paid order in this range scored "low" on the combined fraud risk model.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Flagged Orders</h2>
        <div class="flex items-center gap-2">
          <span><?= count($frResult['rows']) ?> order<?= count($frResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-riskreport"
                  data-csv-filename="fraud-risk-<?= esc($frResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-riskreport', 'Filter by order #, email…') ?>
      <table id="tbl-riskreport">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Total</th>
            <th>Financial</th>
            <th>Risk</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($frResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <tr>
            <td class="text-sm">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($row['order_number']) ?></a>
              <?php else: ?>
                <?= esc($row['order_number']) ?>
              <?php endif; ?>
            </td>
            <td class="text-sm text-muted"><?= esc($row['created_at']) ?></td>
            <td class="text-sm">
              <a href="?page=customer&email=<?= urlencode($row['email']) ?>"><?= esc($row['email']) ?></a>
            </td>
            <td class="text-sm"><?= formatPrice($row['total']) ?></td>
            <td class="text-sm"><span class="chip <?= financialChip($row['financial']) ?> capitalize"><?= esc($row['financial']) ?></span></td>
            <td><?= riskBadge($row['risk']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
