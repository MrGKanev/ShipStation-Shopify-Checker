<?= topbar('Marketing Consent Audit', 'Orders from customers without active email marketing consent') ?>

<?= featureInfoStart('consentaudit', 'Marketing Consent Audit') ?>
  <p><strong>Marketing Consent Audit</strong> finds paid orders placed by customers whose Shopify email marketing consent state is not <code>subscribed</code>.</p>
  <ul>
    <li>Useful before running any marketing/win-back campaign off the order list - these customers should be excluded or handled separately.</li>
    <li>SMS consent is shown as an informational column but doesn't affect the flag.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid orders and flags those whose customer is not actively subscribed to email marketing.</div>

  <?php if ($caError): ?>
    <div class="error-msg mb-3"><?= esc($caError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_consentaudit">
    <?php dateRangePartial('ca', $caStart, $caEnd) ?>
  </form>

  <?php if ($caResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $caResult['scanned'] ?></strong> orders
        (<?= esc($caResult['start']) ?> → <?= esc($caResult['end']) ?>)
        &mdash; <strong><?= count($caResult['rows']) ?></strong> without active email consent</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($caResult !== null): ?>
  <?php if (empty($caResult['rows'])): ?>
    <?= tableWrapEmpty('No consent issues found', 'All customers in this range are actively subscribed to email marketing.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($caResult['rows'], 'tbl-consentaudit', 'Consent Issues', 'consent-audit', $caResult['start'], 'order', 'Filter by order #, email…') ?>
      <table id="tbl-consentaudit">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Email consent</th>
            <th>SMS consent</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($caResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td class="text-sm"><?= esc($row['created_at']) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td><span class="chip chip-warn capitalize"><?= esc(str_replace('_', ' ', $row['email_consent'])) ?></span></td>
            <td><span class="chip chip-unknown capitalize"><?= esc(str_replace('_', ' ', $row['sms_consent'])) ?></span></td>
            <td><span class="chip <?= financialChip($row['financial']) ?> capitalize"><?= esc($row['financial']) ?></span></td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true, 'timeline' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
