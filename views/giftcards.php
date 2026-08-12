<?= topbar('Gift Cards', 'Unused or soon-to-expire gift card balances') ?>

<?= featureInfoStart('giftcards', 'Gift Cards') ?>
  <p><strong>Gift Cards</strong> finds enabled gift cards with a remaining balance that are either expiring soon or have never been redeemed at all.</p>
  <ul>
    <li><strong>Expiring soon</strong> - balance &gt; 0 and the expiry date falls within the configured window.</li>
    <li><strong>Never redeemed</strong> - the balance still equals the full initial value.</li>
    <li>Disabled or fully-redeemed cards are excluded.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan gift cards</h2>
  <div class="hint">Fetches all gift cards and flags those expiring soon or never redeemed.</div>

  <?php if ($gcError): ?>
    <div class="error-msg mb-3"><?= esc($gcError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_giftcards">
    <div class="field">
      <label>Expiring within (days)</label>
      <input type="number" name="gc_days" value="<?= esc((string)$gcDays) ?>" min="1" step="1" style="width:100px">
    </div>
    <button class="btn" type="submit">Scan gift cards</button>
  </form>

  <?php if ($gcResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $gcResult['scanned'] ?></strong> gift cards
        &mdash; <strong><?= count($gcResult['rows']) ?></strong> flagged</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($gcResult !== null): ?>
  <?php if (empty($gcResult['rows'])): ?>
    <?= tableWrapEmpty('No gift card issues', 'All ' . $gcResult['scanned'] . ' gift cards are either fresh, redeemed, or not close to expiry.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Gift Card Issues</h2>
        <div class="flex items-center gap-2">
          <span><?= count($gcResult['rows']) ?> card<?= count($gcResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-giftcards"
                  data-csv-filename="gift-cards.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-giftcards', 'Filter by code, email…') ?>
      <table id="tbl-giftcards">
        <thead>
          <tr>
            <th>Code</th>
            <th>Customer</th>
            <th>Balance</th>
            <th>Initial value</th>
            <th>Expires</th>
            <th>Issues</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($gcResult['rows'] as $row): ?>
          <tr>
            <td class="font-mono"><?= esc($row['masked_code']) ?></td>
            <td class="td-email"><?= esc($row['customer_email'] ?: '-') ?></td>
            <td class="td-price"><?= formatPrice($row['balance']) ?></td>
            <td class="td-price"><?= formatPrice($row['initial_value']) ?></td>
            <td class="text-sm"><?= esc($row['expires_on'] ?: 'No expiry') ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['reasons'] as $reason): ?>
                  <span class="chip chip-warn"><?= esc($reason) ?></span>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
