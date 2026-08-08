<?php
// Helpers used only in this view
$latestCount = $latestReport['count'] ?? 0;
$latestDate  = $latestReport['date']  ?? null;
$prevCount   = isset($reports[1]) ? $reports[1]['count'] : null;

$trendIcon  = '';
$trendColor = '';
if ($dbTrend !== null) {
    if ($dbTrend < 0) { $trendIcon = '↓'; $trendColor = 'var(--ok)'; }
    elseif ($dbTrend > 0) { $trendIcon = '↑'; $trendColor = 'var(--danger)'; }
    else { $trendIcon = '→'; $trendColor = 'var(--muted)'; }
}

$latestCardMod = '';
if ($latestDate) {
    $latestCardMod = $latestCount === 0 ? 'db-card--ok' : ($latestCount >= 5 ? 'db-card--danger' : 'db-card--warn');
}

// Cache helpers (used in topbar widget)
$cacheFreshColor = 'var(--muted)';
$cacheAgeLabel   = null;
if ($dbCacheCount > 0) {
    $freshPct        = (int) round($dbCacheFreshCount / $dbCacheCount * 100);
    $cacheFreshColor = $freshPct >= 80 ? 'var(--ok)' : ($freshPct >= 40 ? 'var(--warn)' : 'var(--danger)');
    if ($dbCacheNewestRefresh) {
        $ageMin      = (int) round((time() - $dbCacheNewestRefresh) / 60);
        $cacheAgeLabel = $ageMin < 60 ? "{$ageMin}m ago" : round($ageMin / 60, 1) . 'h ago';
    }
}

// 7-day helpers
$has7DayData = array_filter($dbLast7DayAudits, fn($v) => $v !== null);
$trend7Max   = $has7DayData ? max($has7DayData) : 0;
?>

<div class="topbar">
  <div>
    <h1>Dashboard</h1>
  </div>
  <div class="flex items-center gap-3">
    <?php if ($dbCacheCount > 0): ?>
      <div class="db-topbar-cache">
        <span class="db-topbar-cache-dot" style="color:<?= $cacheFreshColor ?>">●</span>
        <span><?= $dbCacheFreshCount ?>/<?= $dbCacheCount ?> fresh</span>
        <?php if ($cacheAgeLabel): ?>
          <span class="db-topbar-cache-age"><?= esc($cacheAgeLabel) ?></span>
        <?php endif; ?>
        <form method="post" class="db-topbar-cache-form">
          <input type="hidden" name="action" value="flush_cache">
          <button type="submit" class="db-topbar-cache-flush">Flush</button>
        </form>
      </div>
    <?php endif; ?>
    <a href="?page=run" class="btn btn-sm">▶ Run Audit</a>
  </div>
</div>

<?php if ($dbCacheFlushed > 0): ?>
  <div class="flash flash-ok toast" style="margin-bottom:1rem">
    Flushed <?= $dbCacheFlushed ?> cache entr<?= $dbCacheFlushed === 1 ? 'y' : 'ies' ?>.
  </div>
<?php endif; ?>

<!-- ── Stat cards ─────────────────────────────────────────────────────── -->
<div class="db-cards">

  <!-- Latest audit -->
  <div class="db-card <?= $latestCardMod ?>">
    <div class="db-card-label">Latest Audit</div>
    <div class="db-card-num">
      <?php if ($latestDate): ?>
        <?= $latestCount ?>
        <?php if ($trendIcon): ?>
          <span style="font-size:1.1rem;color:<?= $trendColor ?>"><?= $trendIcon ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span style="font-size:1.2rem;color:var(--muted)">-</span>
      <?php endif; ?>
    </div>
    <div class="db-card-sub">
      <?php if ($latestDate): ?>
        missing &middot; <?= esc($latestDate) ?>
        <?php if ($prevCount !== null && $dbTrend !== 0): ?>
          <span style="color:<?= $trendColor ?>"> (was <?= $prevCount ?>)</span>
        <?php endif; ?>
        <?php if ($dbDaysSinceAudit !== null): ?>
          <?php
            $dsa = $dbDaysSinceAudit;
            $dsaColor = $dsa <= 2 ? 'var(--ok)' : ($dsa <= 7 ? 'var(--warn)' : 'var(--danger)');
            $dsaLabel = $dsa === 0 ? 'today' : ($dsa === 1 ? '1 day ago' : "{$dsa} days ago");
          ?>
          <br><span style="color:<?= $dsaColor ?>">last run: <?= $dsaLabel ?></span>
        <?php endif; ?>
      <?php else: ?>
        No audits run yet
      <?php endif; ?>
    </div>
  </div>

  <!-- All time -->
  <div class="db-card">
    <div class="db-card-label">All-Time Reports</div>
    <div class="db-card-num"><?= $dbTotalReports ?></div>
    <div class="db-card-sub">
      <?php if ($dbTotalReports > 0): ?>
        <?= $dbTotalMissing ?> total missing orders
        <?php if ($dbAvgCadence !== null): ?>
          <br><span style="color:var(--muted)">avg every <?= $dbAvgCadence ?> day<?= $dbAvgCadence == 1 ? '' : 's' ?></span>
        <?php endif; ?>
      <?php else: ?>
        No history yet
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent pushes -->
  <div class="db-card">
    <div class="db-card-label">Pushes (30 days)</div>
    <div class="db-card-num"><?= count($dbPushRecent) ?></div>
    <div class="db-card-sub">
      <?php if ($dbLastPush): ?>
        last: <?= esc(substr($dbLastPush, 0, 10)) ?>
        <?php if ($dbAvgResolutionDays !== null): ?>
          <br><span style="color:var(--muted)">avg <?= $dbAvgResolutionDays ?> day<?= $dbAvgResolutionDays == 1 ? '' : 's' ?> to resolve</span>
        <?php endif; ?>
      <?php else: ?>
        No pushes yet
      <?php endif; ?>
    </div>
  </div>

  <!-- Ignored -->
  <div class="db-card <?= $dbStaleIgnored > 0 ? 'db-card--warn' : '' ?>">
    <div class="db-card-label">Ignored Orders</div>
    <div class="db-card-num"><?= count($ignoredOrders) ?></div>
    <div class="db-card-sub">
      <?php if ($dbStaleIgnored > 0): ?>
        <span style="color:var(--warn)"><?= $dbStaleIgnored ?> stale (60+ days)</span> &middot;
      <?php endif; ?>
      <a href="?page=ignored">Manage &rarr;</a>
    </div>
  </div>

  <!-- Pushes today / this week -->
  <div class="db-card">
    <div class="db-card-label">Pushed Today / 7 days</div>
    <div class="db-card-num" style="font-size:1.7rem">
      <?= $dbPushesToday ?>
      <span style="font-size:.95rem;color:var(--muted);font-weight:500"> / <?= $dbPushesWeek ?></span>
    </div>
    <div class="db-card-sub">
      <?php if ($dbLastPush): ?>
        last: <?= esc(substr($dbLastPush, 0, 10)) ?>
      <?php else: ?>
        No pushes yet
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ── Action Queue ──────────────────────────────────────────────────── -->
<div class="db-section">
  <div class="db-section-title">
    ⚠ Action Queue - Missing Orders (from ShipStation)
    <?php if ($latestDate): ?>
      <a href="?date=<?= esc($latestDate) ?>">View full report &rarr;</a>
    <?php endif; ?>
    <?php if ($dbOldestMissingDays !== null && $latestCount > 0): ?>
      <?php $omColor = $dbOldestMissingDays >= 14 ? 'var(--danger)' : ($dbOldestMissingDays >= 7 ? 'var(--warn)' : 'var(--muted)'); ?>
      <span style="font-size:.78rem;font-weight:400;color:<?= $omColor ?>">oldest order: <?= $dbOldestMissingDays ?> days old</span>
    <?php endif; ?>
  </div>
  <?php if (!empty($dbMissingByType) && $latestCount > 0): ?>
    <div style="padding:.35rem 0 .6rem;font-size:.8rem;color:var(--muted)">
      <?php foreach ($dbMissingByType as $type => $cnt): ?>
        <span style="margin-right:.75rem"><strong style="color:var(--fg)"><?= $cnt ?>×</strong> <?= esc($type) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$latestDate): ?>
    <div class="empty" style="padding:2.5rem 1rem">
      <div class="icon">📋</div>
      <h3>No audits run yet</h3>
      <p>Run your first audit to start tracking missing orders.</p>
      <a href="?page=run" class="btn btn-sm" style="display:inline-block;margin-top:.75rem">▶ Run Audit</a>
    </div>

  <?php elseif ($latestCount === 0): ?>
    <div class="empty" style="padding:2rem 1rem">
      <div class="icon">✅</div>
      <h3>All clear from <?= esc($latestDate) ?></h3>
      <p>No missing orders in the most recent audit. Run a new one to check the latest range.</p>
      <a href="?page=run" class="btn btn-sm" style="display:inline-block;margin-top:.75rem">▶ Run Audit</a>
    </div>

  <?php else: ?>
    <div class="table-wrap" style="margin-top:0">
      <table id="tbl-dashboard-queue">
        <thead>
          <tr>
            <th>Order</th>
            <th>Type</th>
            <th>Placed</th>
            <th>Email</th>
            <th>Total</th>
            <th>Financial</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($latestReport['missing'] as $row):
            $orderNum  = $row['shopify_name'] ?: $row['order_number'] ?? '';
            $shopifyId = $row['shopify_id'] ?? '';
            $adminUrl  = $shopifyId ? $shopifyAdminBase . '/' . esc($shopifyId) : null;
            $numClean  = ltrim($orderNum, '#');
            $seen      = $orderHistory[Comparator::normalise($row['order_number'] ?? '')] ?? null;
          ?>
          <tr>
            <td class="order-num">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($orderNum) ?></a>
              <?php else: ?>
                <?= esc($orderNum) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc($numClean) ?>" title="Copy">⧉</button>
              <?php if ($seen && $seen['count'] > 1): ?>
                <span class="seen-badge" title="Seen in <?= $seen['count'] ?> reports"><?= $seen['count'] ?>×</span>
              <?php endif; ?>
            </td>
            <td class="text-sm text-muted"><?= esc($row['order_type'] ?? '-') ?></td>
            <td class="text-sm"><?= esc(substr($row['created_at'] ?? '', 0, 10)) ?></td>
            <td class="td-email"><?= esc($row['email'] ?? '') ?></td>
            <td class="td-price"><?= formatPrice($row['total_price'] ?? null) ?></td>
            <td>
              <?php if ($row['financial_status'] ?? ''): ?>
                <span class="chip <?= financialChip($row['financial_status']) ?>"><?= esc($row['financial_status']) ?></span>
              <?php endif; ?>
            </td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'shopifyLabel' => 'Shopify', 'orderNum' => $numClean, 'email' => $row['email'] ?? '', 'spotcheck' => true, 'timeline' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ── Three-col: 7-day trend · History · Quick Actions ─────────────── -->
<div class="db-three-col">

  <!-- 7-day audit trend -->
  <div>
    <div class="db-section-title">
      📅 7-Day Trend
      <span style="font-size:.72rem;font-weight:400;color:var(--muted);text-transform:none;letter-spacing:0">missing / day</span>
    </div>
    <div class="db-panel">
      <div class="db-7day">
        <?php foreach ($dbLast7DayAudits as $day => $count):
          $isToday  = $day === date('Y-m-d');
          $dayShort = date('D', strtotime($day))[0];
          if ($count === null) {
            $barColor = 'var(--border)';
            $barH     = 6;
            $tooltip  = "$day: no audit";
          } else {
            $barColor = $count === 0 ? 'var(--ok)' : ($count >= 5 ? 'var(--danger)' : 'var(--warn)');
            $barH     = $trend7Max > 0 ? max(8, (int) round($count / $trend7Max * 52)) : 8;
            if ($count === 0) $barH = 8;
            $tooltip  = "$day: $count missing";
          }
        ?>
        <div class="db-7day-col<?= $isToday ? ' db-7day-today' : '' ?>" title="<?= esc($tooltip) ?>">
          <div class="db-7day-bar-wrap">
            <div class="db-7day-bar" style="height:<?= $barH ?>px;background:<?= $barColor ?><?= $count === null ? ';background:transparent;border:1.5px dashed var(--border)' : '' ?>"></div>
          </div>
          <div class="db-7day-label"><?= $dayShort ?></div>
          <?php if ($count !== null): ?>
            <div class="db-7day-count" style="color:<?= $barColor ?>"><?= $count ?></div>
          <?php else: ?>
            <div class="db-7day-count" style="color:var(--border)">–</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Audit History -->
  <div>
    <div class="db-section-title">
      📊 Audit History
      <?php if ($dbTotalReports > 10): ?>
        <a href="?page=trends">All &rarr;</a>
      <?php endif; ?>
    </div>
    <?php if (empty($dbTrendReports)): ?>
      <div class="text-sm" style="color:var(--muted);padding:.5rem 0">No reports yet.</div>
    <?php else: ?>
      <div class="db-panel">
        <?php foreach ($dbTrendReports as $r):
          $pct     = $dbMaxCount > 0 ? round($r['count'] / $dbMaxCount * 100) : 0;
          $barMod  = $r['count'] === 0 ? '' : ($r['count'] >= 5 ? 'db-history-bar--danger' : 'db-history-bar--warn');
          $isLatest = ($r === $dbTrendReports[0]);
        ?>
        <div class="db-history-row">
          <div class="db-history-date">
            <a href="?date=<?= esc($r['date']) ?>" style="color:<?= $isLatest ? 'var(--accent)' : 'var(--muted)' ?>;font-weight:<?= $isLatest ? '600' : '400' ?>">
              <?= esc($r['date']) ?>
            </a>
          </div>
          <div class="db-history-count" style="color:<?= $r['count'] === 0 ? 'var(--ok)' : ($r['count'] >= 5 ? 'var(--danger)' : 'var(--warn)') ?>">
            <?= $r['count'] ?>
          </div>
          <div class="db-history-bar-wrap">
            <div class="db-history-bar <?= $barMod ?>" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Quick Actions + Recent Pushes -->
  <div>
    <div class="db-section-title">⚡ Quick Actions</div>
    <div class="db-quick-grid">
      <a href="?page=run"          class="db-quick-btn"><span class="icon">▶</span> Run Audit</a>
      <a href="?page=addrcheck"    class="db-quick-btn"><span class="icon">📍</span> Address Scan</a>
      <a href="?page=emailcheck"   class="db-quick-btn"><span class="icon">✉</span> Email Check</a>
      <a href="?page=bundlecheck"  class="db-quick-btn"><span class="icon">🧩</span> Bundle Check</a>
      <a href="?page=partialfulfill" class="db-quick-btn"><span class="icon">⏳</span> Partial Stalls</a>
      <a href="?page=onholdstall"  class="db-quick-btn"><span class="icon">⏸</span> On-Hold Stall</a>
      <a href="?page=noteflags"    class="db-quick-btn"><span class="icon">🚩</span> Note Flags</a>
      <a href="?page=ssshipped"    class="db-quick-btn"><span class="icon">🔄</span> SS Sync Check</a>
      <a href="?page=orphans"      class="db-quick-btn"><span class="icon">👻</span> Orphan Detector</a>
      <a href="?page=zombieproducts" class="db-quick-btn"><span class="icon">🧟</span> Zombie Products</a>
    </div>

    <?php if (!empty($dbPushRecent)): ?>
      <div class="db-section-title" style="margin-top:1rem">
        📤 Recent Pushes
        <a href="?page=pushlog">All &rarr;</a>
      </div>
      <div class="db-panel">
        <?php foreach (array_slice($dbPushRecent, 0, 5) as $p): ?>
        <div class="db-history-row">
          <div class="db-history-date" style="width:auto;flex:1">
            <a href="?page=spotcheck&prefill=<?= urlencode($p['order_number'] ?? '') ?>"><?= esc($p['order_number'] ?? '-') ?></a>
          </div>
          <div style="font-size:.78rem;color:var(--muted)"><?= esc(substr($p['pushed_at'] ?? '', 0, 10)) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>
