<div class="topbar">
  <div>
    <h1>Order Audit</h1>
    <?php if ($selectedReport): ?>
      <div class="meta">Report for <?= esc($selectedReport['date']) ?> &mdash;
        <a href="?action=download&date=<?= esc($selectedReport['date']) ?>">Download CSV</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($reports)): ?>

  <div class="no-reports">
    <div class="icon">📭</div>
    <h2>No reports yet</h2>
    <p style="margin-bottom:1.25rem">No audit reports found. Run your first audit to see which Shopify orders are missing in ShipStation.</p>
    <a class="btn" href="?page=run">Run first audit</a>
  </div>

<?php elseif ($selectedReport): ?>
  <?php $missing = $selectedReport['missing']; $count = $selectedReport['count']; ?>

  <div class="stats">
    <div class="stat-card">
      <div class="label">Missing</div>
      <div class="value <?= $count > 0 ? 'warn' : 'ok' ?>"><?= $count ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Report date</div>
      <div class="value accent" style="font-size:1.1rem;line-height:1.8"><?= esc($selectedReport['date']) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Total reports</div>
      <div class="value accent"><?= count($reports) ?></div>
    </div>
    <div class="stat-card" style="display:flex;flex-direction:column;justify-content:space-between">
      <div class="label">Download</div>
      <a class="btn btn-sm" href="?action=download&date=<?= esc($selectedReport['date']) ?>" download
         style="margin-top:.5rem;align-self:flex-start">CSV</a>
    </div>
  </div>

  <?php if (count($reports) > 1): ?>
    <?php
      $historySlice = array_slice(array_reverse($reports), 0, 30);
      $maxCount = max(1, max(array_column($historySlice, 'count')));
    ?>
    <div style="margin-bottom:.4rem;font-size:.75rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.07em">History</div>
    <div class="history">
      <?php foreach ($historySlice as $r): ?>
        <?php
          $pct    = max(8, round(($r['count'] / $maxCount) * 80));
          $color  = $r['count'] === 0 ? 'var(--ok)' : 'var(--warn)';
          $active = $r['date'] === $selectedDate ? 'selected' : '';
        ?>
        <a href="?date=<?= esc($r['date']) ?>" style="flex:1;display:block;text-decoration:none">
          <div class="history-bar <?= $active ?>"
               style="height:<?= $pct ?>px;background:<?= $color ?>"
               title="<?= esc($r['date']) ?>: <?= $r['count'] ?> missing"></div>
          <div class="history-label"><?= substr($r['date'], 5) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?= renderMissingTable($missing, $ignoredOrders, $shopifyAdminBase, 'reports', $selectedDate ?? '') ?>

<?php endif; ?>
