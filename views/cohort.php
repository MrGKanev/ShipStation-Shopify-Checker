<?= topbar('Customer Cohort & LTV', 'Top customers by lifetime value and monthly cohort retention in the selected period') ?>

<?= featureInfoStart('cohort', 'Customer Cohort & LTV') ?>
  <p><strong>Customer Cohort & LTV</strong> groups all non-cancelled orders in the date range by customer email.</p>
  <ul>
    <li><strong>Top Customers</strong> — top 100 customers by total revenue in the period, with order count and average order value.</li>
    <li><strong>Monthly Cohort</strong> — customers grouped by the month of their <em>first order in the period</em>. Shows how many came back to buy again (repeat rate) and average orders per customer.</li>
    <li>Cancelled orders are excluded from all revenue figures.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Analyse customer LTV &amp; cohorts</h2>
  <div class="hint">Fetches all Shopify orders in the selected range. Large date ranges may take a moment.</div>

  <?php if ($ltvError): ?>
    <div class="error-msg mb-3"><?= esc($ltvError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_ltv">
    <?php dateRangePartial('ltv', $ltvStart, $ltvEnd) ?>
  </form>

  <?php if ($ltvResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>
        <strong><?= number_format($ltvResult['total_orders']) ?></strong> orders &nbsp;&middot;&nbsp;
        <strong><?= number_format($ltvResult['total_customers']) ?></strong> customers &nbsp;&middot;&nbsp;
        <strong>$<?= number_format($ltvResult['total_revenue'], 2) ?></strong> revenue
        (<?= esc($ltvResult['start']) ?> &rarr; <?= esc($ltvResult['end']) ?>)
      </span>
    </div>
  <?php endif; ?>
</div>

<?php if ($ltvResult !== null): ?>

  <?php /* ── Top Customers table ── */ ?>
  <?php if (empty($ltvResult['top_customers'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">🔍</div>
        <h3>No customers found</h3>
        <p>No orders with customer email addresses in this date range.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Top Customers by LTV</h2>
        <div class="flex items-center gap-2">
          <span><?= count($ltvResult['top_customers']) ?> customer<?= count($ltvResult['top_customers']) !== 1 ? 's' : '' ?> (top 100)</span>
          <button class="btn btn-sm btn-ghost"
                  data-csv-btn="#tbl-ltv-customers"
                  data-csv-filename="ltv-customers-<?= esc($ltvResult['end']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-ltv-customers', 'Filter by email...') ?>
      <table id="tbl-ltv-customers">
        <thead>
          <tr>
            <th>#</th>
            <th>Email</th>
            <th>Orders</th>
            <th>Total Spent</th>
            <th>Avg Order Value</th>
            <th>First Order</th>
            <th>Last Order</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ltvResult['top_customers'] as $i => $c): ?>
            <tr>
              <td class="text-muted text-sm"><?= $i + 1 ?></td>
              <td>
                <a href="?page=customer&action=customer_lookup"
                   onclick="event.preventDefault();document.getElementById('js-ltv-email-<?= $i ?>').submit()">
                  <?= esc($c['email']) ?>
                </a>
                <form id="js-ltv-email-<?= $i ?>" method="post" style="display:none">
                  <input type="hidden" name="action" value="customer_lookup">
                  <input type="hidden" name="customer_email" value="<?= esc($c['email']) ?>">
                </form>
              </td>
              <td><?= $c['orders'] ?></td>
              <td class="font-bold">$<?= number_format($c['total'], 2) ?></td>
              <td class="text-muted">$<?= number_format($c['avg'], 2) ?></td>
              <td class="text-muted text-sm"><?= esc($c['first_date']) ?></td>
              <td class="text-muted text-sm"><?= esc($c['last_date']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php /* ── Cohort table ── */ ?>
  <?php if (!empty($ltvResult['cohort'])): ?>
    <div class="table-wrap mt-6">
      <div class="table-header">
        <h2>Monthly Cohort Retention</h2>
        <div class="flex items-center gap-2">
          <span><?= count($ltvResult['cohort']) ?> month<?= count($ltvResult['cohort']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost"
                  data-csv-btn="#tbl-cohort"
                  data-csv-filename="cohorts-<?= esc($ltvResult['end']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <div class="hint px-4 pb-2 text-sm">
        Customers grouped by their first order month in this period. "Repeat" = placed more than one order in the period.
      </div>
      <table id="tbl-cohort">
        <thead>
          <tr>
            <th>Month</th>
            <th>New Customers</th>
            <th>Repeat Buyers</th>
            <th>Repeat Rate</th>
            <th>Avg Orders / Customer</th>
            <th>Total Revenue</th>
            <th>Avg Revenue / Customer</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ltvResult['cohort'] as $row): ?>
            <?php
              $rate = $row['retention_rate'];
              $rateClass = $rate >= 30 ? 'color:var(--ok);font-weight:600'
                         : ($rate >= 10 ? 'color:var(--warn);font-weight:600' : 'color:var(--danger)');
            ?>
            <tr>
              <td class="font-bold"><?= esc($row['month']) ?></td>
              <td><?= $row['new'] ?></td>
              <td><?= $row['repeat'] ?></td>
              <td style="<?= $rateClass ?>"><?= $rate ?>%</td>
              <td class="text-muted"><?= $row['avg_orders'] ?></td>
              <td>$<?= number_format($row['total_revenue'], 2) ?></td>
              <td class="text-muted">$<?= number_format($row['avg_revenue'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>
