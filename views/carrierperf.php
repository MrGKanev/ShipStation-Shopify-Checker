<?= topbar('Carrier Performance', 'Avg delivery time and late-delivery rate grouped by carrier') ?>

<?= featureInfoStart('carrierperf', 'Carrier Performance Dashboard') ?>
  <p><strong>Carrier Performance</strong> pulls ShipStation shipment records for the selected date range and groups them by carrier code.</p>
  <ul>
    <li><strong>Avg Delivery Days</strong> — calculated from <em>shipDate</em> to <em>deliveryDate</em> for shipments where both fields are present.</li>
    <li><strong>Late %</strong> — percentage of those shipments that took more than 5 days to deliver.</li>
    <li>Shipments without a delivery date are counted but excluded from the averages.</li>
    <li>ShipStation must return <code>deliveryDate</code> data for meaningful results; availability varies by carrier and service level.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Analyse carrier performance</h2>
  <div class="hint">Fetches ShipStation shipments by ship date and groups performance by carrier. Requires ShipStation credentials.</div>

  <?php if ($cpError): ?>
    <div class="error-msg mb-3"><?= esc($cpError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_carrierperf">
    <?php dateRangePartial('cp', $cpStart, $cpEnd) ?>
  </form>

  <?php if ($cpResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= (int)$cpResult['scanned'] ?></strong> shipment<?= $cpResult['scanned'] !== 1 ? 's' : '' ?>
        &nbsp;(<?= esc($cpResult['start']) ?> &rarr; <?= esc($cpResult['end']) ?>)
      </span>
      <?php if (!empty($cpResult['rows'])): ?>
        <span class="source-badge"><?= count($cpResult['rows']) ?> carrier<?= count($cpResult['rows']) !== 1 ? 's' : '' ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($cpResult !== null): ?>
  <?php if (empty($cpResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No shipments found</h3>
        <p>No ShipStation shipments with ship dates in this range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader(
            $cpResult['rows'],
            'tbl-carrierperf',
            'Carrier Summary',
            'carrier-performance',
            $cpResult['start'],
            'carrier',
            'Filter by carrier...'
          ) ?>
      <?= searchInput('tbl-carrierperf', 'Filter by carrier...') ?>
      <table id="tbl-carrierperf">
        <thead>
          <tr>
            <th>Carrier</th>
            <th>Shipments</th>
            <th>With Delivery Date</th>
            <th>Avg Delivery Days</th>
            <th>Late Deliveries</th>
            <th>Late %</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cpResult['rows'] as $row): ?>
          <?php
            $latePct   = $row['late_pct'];
            $lateColor = severityColor($latePct, 20, 10);
            $avgDays   = $row['avg_days'];
            $avgColor  = severityColor($avgDays, 7, 5);
          ?>
          <tr>
            <td class="font-semibold"><?= esc($row['carrier']) ?></td>
            <td><?= (int)$row['count'] ?></td>
            <td><?= (int)$row['with_delivery'] ?></td>
            <td style="color:<?= $avgColor ?>">
              <?= $avgDays !== null ? $avgDays . ' days' : '<span class="text-muted">—</span>' ?>
            </td>
            <td><?= (int)$row['late_count'] ?></td>
            <td style="color:<?= $lateColor ?>">
              <?= $latePct !== null ? $latePct . '%' : '<span class="text-muted">—</span>' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
