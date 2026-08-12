<?= topbar('Tax Audit', 'Paid orders with $0 tax charged') ?>

<?= featureInfoStart('taxaudit', 'Tax Audit') ?>
  <p><strong>Tax Audit</strong> finds paid orders above a minimum amount where no tax was charged and the customer is not marked tax-exempt in Shopify.</p>
  <ul>
    <li>No jurisdiction logic is applied - this is a review signal (a likely tax-setting or rate misconfiguration), not a definitive compliance verdict.</li>
    <li>Orders below the minimum amount are skipped to avoid noise from free or heavily-discounted orders.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid orders and flags those with $0 tax and a non-exempt customer.</div>

  <?php if ($txError): ?>
    <div class="error-msg mb-3"><?= esc($txError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_taxaudit">
    <?php dateRangePartial('tx', $txStart, $txEnd, '<div class="field"><label>Min order total $</label><input type="number" name="tx_min" value="' . esc((string)$txMin) . '" min="0" step="1" style="width:100px"></div>') ?>
  </form>

  <?php if ($txResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $txResult['scanned'] ?></strong> orders
        (<?= esc($txResult['start']) ?> → <?= esc($txResult['end']) ?>)
        &mdash; <strong><?= count($txResult['rows']) ?></strong> with $0 tax</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($txResult !== null): ?>
  <?php if (empty($txResult['rows'])): ?>
    <?= tableWrapEmpty('No zero-tax orders found', 'All qualifying orders in this range charged tax or the customer is tax-exempt.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($txResult['rows'], 'tbl-taxaudit', 'Zero-Tax Orders', 'tax-audit', $txResult['start'], 'order', 'Filter by order #, email…') ?>
      <table id="tbl-taxaudit">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Total</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($txResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td class="td-price"><?= formatPrice($row['total']) ?></td>
            <td><span class="chip <?= financialChip($row['financial']) ?> capitalize"><?= esc($row['financial']) ?></span></td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true, 'timeline' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
