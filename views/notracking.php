<?= topbar('Fulfilled Without Tracking', 'Fulfilled orders missing a tracking number') ?>

<?= featureInfoStart('notracking', 'Fulfilled Without Tracking') ?>
  <p><strong>Fulfilled Without Tracking</strong> finds orders that Shopify has marked as fulfilled (or partially fulfilled) but where one or more fulfillments have no tracking number after the configured grace period.</p>
  <ul>
    <li>Only fulfillments older than the <strong>grace period</strong> (default 24 h) are flagged - newly created fulfillments often have tracking added within minutes.</li>
    <li>Carriers sometimes scan packages without a tracking upload. This check surfaces those gaps before customers notice.</li>
    <li>Fulfillments that have <code>tracking_company</code> set but no <code>tracking_number</code> are also included.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Scans fulfilled orders created in the range for fulfillments missing tracking numbers.</div>

  <?php if ($ntError): ?>
    <div class="error-msg mb-3"><?= esc($ntError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_notracking">
    <?php dateRangePartial('nt', $ntStart, $ntEnd, '<div class="field"><label>Grace period (hours)</label><input type="number" name="nt_threshold" value="' . (int)$ntThreshold . '" min="1" style="width:80px"></div>') ?>
  </form>

  <?php if ($ntResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $ntResult['scanned'] ?></strong> fulfilled orders
        (<?= esc($ntResult['start']) ?> → <?= esc($ntResult['end']) ?>)
        &mdash; <strong><?= count($ntResult['rows']) ?></strong> missing tracking after <?= (int)$ntResult['threshold'] ?>h</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($ntResult !== null): ?>
  <?php if (empty($ntResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>All fulfillments have tracking</h3>
        <p>Every fulfilled order in this range has a tracking number (or was fulfilled within the grace period).</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Missing Tracking Numbers</h2>
        <div class="flex items-center gap-2">
          <span><?= count($ntResult['rows']) ?> order<?= count($ntResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-notracking"
                  data-csv-filename="no-tracking-<?= esc($ntResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-notracking', 'Filter by order #, email…') ?>
      <table id="tbl-notracking">
        <thead>
          <tr>
            <th>Order</th>
            <th>Placed</th>
            <th>Fulfillment date</th>
            <th>Hours since</th>
            <th>Carrier</th>
            <th>Email</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ntResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <?php foreach ($row['missing'] as $idx => $f):
            $hoursColor = severityColor((float)$f['hours_ago'], 48, 24);
          ?>
          <tr>
            <?php if ($idx === 0): ?>
            <?= orderNumCell($row['order_number'], $adminUrl, null, count($row['missing'])) ?>
            <td class="text-sm" rowspan="<?= count($row['missing']) ?>"><?= esc($row['created_at']) ?></td>
            <?php endif; ?>
            <td class="text-sm"><?= esc($f['created_at']) ?></td>
            <td class="font-semibold" style="color:<?= $hoursColor ?>"><?= $f['hours_ago'] ?>h</td>
            <td class="text-sm text-muted"><?= $f['company'] ? esc($f['company']) : '-' ?></td>
            <?php if ($idx === 0): ?>
            <td class="td-email" rowspan="<?= count($row['missing']) ?>"><?= esc($row['email']) ?></td>
            <td class="td-price" rowspan="<?= count($row['missing']) ?>"><?= formatPrice($row['total']) ?></td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true, 'rowspan' => count($row['missing'])]) ?>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
