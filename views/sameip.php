<?= topbar('Same IP, Different Emails', 'Different customer emails ordering from the same client IP') ?>

<?= featureInfoStart('sameip', 'Same IP, Different Emails') ?>
  <p><strong>Same IP, Different Emails</strong> finds paid orders where two or more <em>different</em> customer emails share the same client IP address - a signal for multi-account abuse or a fraud ring operating from one device/network.</p>
  <ul>
    <li>Grouped by the exact <code>clientIp</code> Shopify recorded at checkout.</li>
    <li>Multiple orders from the <em>same email</em> at the same IP are excluded - only cross-email matches are flagged.</li>
    <li>Shared IPs are common for offices, universities, or carrier-grade NAT - treat clusters as a review signal, not automatic proof of fraud.</li>
    <li>Sort by <strong>Emails</strong> descending to find the most suspicious clusters first.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Fetches paid orders and groups client IPs shared by more than one email.</div>

  <?php if ($siError): ?>
    <div class="error-msg mb-3"><?= esc($siError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_sameip">
    <?php dateRangePartial('si', $siStart, $siEnd) ?>
  </form>

  <?php if ($siResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $siResult['scanned'] ?></strong> paid order<?= $siResult['scanned'] !== 1 ? 's' : '' ?>
        (<?= esc($siResult['start']) ?> → <?= esc($siResult['end']) ?>)
        &mdash; <strong><?= count($siResult['rows']) ?></strong> shared IP<?= count($siResult['rows']) !== 1 ? 's' : '' ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($siResult !== null): ?>
  <?php if (empty($siResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No shared client IPs</h3>
        <p>Every client IP in this range is used by only one customer email.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Shared IPs</h2>
        <div class="flex items-center gap-2">
          <span><?= count($siResult['rows']) ?> IP<?= count($siResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-sameip"
                  data-csv-filename="same-ip-<?= esc($siResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-sameip', 'Filter by IP, email…') ?>
      <table id="tbl-sameip">
        <thead>
          <tr>
            <th>Client IP</th>
            <th>Emails</th>
            <th>Orders</th>
            <th>Order details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($siResult['rows'] as $row): ?>
          <tr>
            <td class="text-sm font-medium"><?= esc($row['ip']) ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['emails'] as $email): ?>
                  <a class="text-sm" href="?page=customer&email=<?= urlencode($email) ?>"><?= esc($email) ?></a>
                <?php endforeach; ?>
              </div>
              <span class="badge badge-warn" style="margin-top:4px"><?= $row['email_count'] ?> emails</span>
            </td>
            <td class="text-sm"><?= $row['order_count'] ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['orders'] as $o):
                  $adminUrl = $o['shopify_id'] ? $shopifyAdminBase . '/' . esc($o['shopify_id']) : null;
                ?>
                  <div class="text-sm flex items-center gap-2">
                    <?php if ($adminUrl): ?>
                      <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($o['order_number']) ?></a>
                    <?php else: ?>
                      <span><?= esc($o['order_number']) ?></span>
                    <?php endif; ?>
                    <span class="text-muted">·</span>
                    <span class="text-muted"><?= esc($o['created_at']) ?></span>
                    <span class="text-muted">·</span>
                    <span class="text-muted"><?= esc($o['email']) ?></span>
                    <span class="text-muted">·</span>
                    <span><?= formatPrice($o['total']) ?></span>
                    <?php if ($o['fulfillment']): ?>
                      <span class="chip chip-partial" style="font-size:.7rem"><?= esc(str_replace('_',' ',$o['fulfillment'])) ?></span>
                    <?php endif; ?>
                  </div>
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
