<div class="topbar">
  <div>
    <h1>Run Audit</h1>
    <div class="meta">Compare Shopify vs ShipStation for any date range</div>
  </div>
</div>

<div class="run-form">
  <h2>Date range</h2>
  <div class="hint">Fetches orders from both platforms and shows what's missing in ShipStation. Large ranges (90+ days) may take 30–60 seconds.</div>

  <?php if ($auditError): ?>
    <div class="error-msg" style="margin-bottom:.75rem"><?= esc($auditError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="run_audit">
    <div class="date-row">
      <div class="field">
        <label>From</label>
        <input type="date" name="audit_start" value="<?= esc($auditStart) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>To</label>
        <input type="date" name="audit_end" value="<?= esc($auditEnd) ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <button class="btn" type="submit" style="flex-shrink:0">Run Audit</button>
    </div>
  </form>
</div>

<?php if ($auditResult !== null): ?>
  <?php $missing = $auditResult['missing']; $count = count($missing); ?>

  <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;flex-wrap:wrap">
    <span class="duration-note" style="margin:0">Completed in <?= $auditDuration ?>s &mdash; report saved to <code>reports/</code></span>
    <span class="source-badge <?= $auditFromCache['shopify'] ? 'cached' : 'live' ?>">
      Shopify: <?= $auditFromCache['shopify'] ? 'from cache' : 'live' ?>
    </span>
    <span class="source-badge <?= $auditFromCache['ss'] ? 'cached' : 'live' ?>">
      ShipStation: <?= $auditFromCache['ss'] ? 'from cache' : 'live' ?>
    </span>
  </div>

  <div class="audit-summary">
    <div class="stat-card">
      <div class="label">Missing</div>
      <div class="value <?= $count > 0 ? 'warn' : 'ok' ?>"><?= $count ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Matched</div>
      <div class="value ok"><?= $auditResult['found'] ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Skipped</div>
      <div class="value accent"><?= $auditResult['skipped'] ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Ignored</div>
      <div class="value accent"><?= count($auditResult['ignored']) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">SS total</div>
      <div class="value accent"><?= $auditResult['total_ss'] ?></div>
    </div>
  </div>

  <?= renderMissingTable($missing, $ignoredOrders, $shopifyAdminBase, 'run', $auditStart, $auditEnd) ?>

  <?php if (!empty($auditResult['ignored'])): ?>
    <details class="ignored-section">
      <summary><?= count($auditResult['ignored']) ?> ignored order<?= count($auditResult['ignored']) !== 1 ? 's' : '' ?> (excluded from results)</summary>
      <div class="ignored-list">
        <?php foreach ($auditResult['ignored'] as $o):
          $num  = $o['order_number'] ?? $o['name'] ?? '?';
          $info = $o['_ignore_info'] ?? [];
        ?>
          <div class="ignored-row">
            <div>
              <span class="ignored-num">#<?= esc($num) ?></span>
              <?php if (!empty($info['reason'])): ?>
                <span class="ignored-reason">&mdash; <?= esc($info['reason']) ?></span>
              <?php endif; ?>
            </div>
            <form method="post">
              <input type="hidden" name="action" value="unignore_order">
              <input type="hidden" name="order_number" value="<?= esc($num) ?>">
              <input type="hidden" name="redirect_page" value="run">
              <button class="unignore-btn" type="submit">Unignore</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

<?php endif; ?>

<div class="cache-section">
  <h2>Cache</h2>
  <div class="cache-meta">
    TTL: <?= $cacheTtl >= 3600 ? round($cacheTtl / 3600, 1) . ' h' : ($cacheTtl / 60) . ' min' ?>
    &mdash; set <code>CACHE_TTL</code> in .env (seconds) to change.
    Cached data is reused for repeated runs on the same date range.
  </div>

  <?php if ($cacheFlushed > 0): ?>
    <div class="flush-notice">Cleared <?= $cacheFlushed ?> cache file<?= $cacheFlushed !== 1 ? 's' : '' ?>.</div>
  <?php endif; ?>

  <div class="cache-actions">
    <form method="post">
      <input type="hidden" name="action" value="flush_cache">
      <input type="hidden" name="audit_start" value="<?= esc($auditStart) ?>">
      <input type="hidden" name="audit_end"   value="<?= esc($auditEnd) ?>">
      <button class="btn btn-danger btn-sm" type="submit" <?= empty($cacheEntries) ? 'disabled' : '' ?>>
        Clear all cache
      </button>
    </form>
    <span style="font-size:.8rem;color:var(--muted)"><?= count($cacheEntries) ?> file<?= count($cacheEntries) !== 1 ? 's' : '' ?> cached</span>
  </div>

  <?php if (empty($cacheEntries)): ?>
    <div class="cache-table-wrap"><div class="empty-cache">No cache files yet — run an audit to populate.</div></div>
  <?php else: ?>
    <div class="cache-table-wrap">
      <table>
        <thead>
          <tr>
            <th>Platform</th>
            <th>File</th>
            <th>Expires</th>
            <th>Size</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cacheEntries as $e): ?>
          <tr>
            <td><span class="chip chip-unknown" style="text-transform:capitalize"><?= esc($e['prefix']) ?></span></td>
            <td style="font-family:monospace;font-size:.78rem;color:var(--muted)"><?= esc(substr($e['file'], 0, 24)) ?>…</td>
            <td><?= date('Y-m-d H:i', $e['expires_at']) ?></td>
            <td><?= $e['size_kb'] ?> KB</td>
            <td>
              <?php if ($e['expired']): ?>
                <span class="tag-expired">Expired</span>
              <?php else: ?>
                <span class="tag-fresh">Fresh</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
