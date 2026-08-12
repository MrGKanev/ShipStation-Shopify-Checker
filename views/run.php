<?= topbar('Run Audit', 'Compare Shopify vs ShipStation for any date range') ?>

<?= featureInfoStart('run', 'Run Audit') ?>
  <p><strong>Run Audit</strong> fetches all paid Shopify orders in the selected date range and compares them against ShipStation - identifying any orders that exist in Shopify but are missing from ShipStation.</p>
  <p>The audit saves a dated CSV report that appears in the sidebar history and on the Reports page, so you can track which orders reappear across multiple runs.</p>
  <ul>
    <li>Shopify orders are filtered to <strong>paid / partially paid</strong> status only - unpaid, cancelled, and test orders are excluded.</li>
    <li>ShipStation is queried with a <strong>+7 day buffer</strong> beyond the end date to account for orders pushed after the Shopify creation date.</li>
    <li>Both datasets are cached - re-running the same date range is instant. Use <em>Flush cache</em> on the Settings page to force a fresh fetch.</li>
    <li>Large ranges (90+ days) may take 30–60 seconds on the first run.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Date range</h2>
  <div class="hint">Fetches orders from both platforms and shows what's missing in ShipStation. Large ranges (90+ days) may take 30–60 seconds.</div>

  <?php if ($auditError): ?>
    <div class="error-msg mb-3"><?= esc($auditError) ?></div>
  <?php endif; ?>

  <form method="post" id="js-audit-form">
    <input type="hidden" name="action" value="run_audit" id="audit-action">
    <?php dateRangePartial('audit', $auditStart, $auditEnd, '<button class="btn btn-ghost btn-submit-end" type="submit" onclick="document.getElementById(\'audit-action\').value=\'queue_audit\'">Queue</button>', 'Run Audit') ?>
  </form>
  <div class="hint mt-2">Queued audits run outside the web request with <code>php worker.php --once</code>.</div>

</div>

<?php if ($auditResult !== null): ?>
  <?php $missing = $auditResult['missing']; $count = count($missing); ?>

  <div class="flex items-center gap-2 flex-wrap mb-4">
    <span class="duration-note m-0">Completed in <?= $auditDuration ?>s &mdash; report saved to <code>reports/</code></span>
    <span class="source-badge <?= $auditFromCache['shopify'] ? 'cached' : 'live' ?>">
      Shopify: <?= $auditFromCache['shopify'] ? 'from cache' : 'live' ?>
    </span>
    <span class="source-badge <?= $auditFromCache['ss'] ? 'cached' : 'live' ?>">
      ShipStation: <?= $auditFromCache['ss'] ? 'from cache' : 'live' ?>
    </span>
    <?php if (($auditSlack['configured'] ?? false) && ($auditSlack['sent'] ?? false)): ?>
      <span class="source-badge live">Slack: sent</span>
    <?php elseif (($auditSlack['configured'] ?? false) && !empty($auditSlack['error'])): ?>
      <span class="source-badge cached" title="<?= esc($auditSlack['error']) ?>">Slack: failed</span>
    <?php endif; ?>
    <?php if (($auditEmail['configured'] ?? false) && ($auditEmail['sent'] ?? false)): ?>
      <span class="source-badge live">Email: sent</span>
    <?php elseif (($auditEmail['configured'] ?? false) && !empty($auditEmail['error'])): ?>
      <span class="source-badge cached" title="<?= esc($auditEmail['error']) ?>">Email: failed</span>
    <?php endif; ?>
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
      <div class="label">ShipStation total</div>
      <div class="value accent"><?= $auditResult['total_ss'] ?></div>
    </div>
  </div>

  <?= pushFlashBanner() ?>
  <?php
    $partialMissing          = $missing;
    $partialIgnoredOrders    = $ignoredOrders;
    $partialShopifyAdminBase = $shopifyAdminBase;
    $partialContext          = 'run';
    $partialContextVal       = $auditStart;
    $partialOrderHistory     = $orderHistory;
    require __DIR__ . '/partials/missing-table.php';
  ?>

  <?php if (!empty($auditResult['duplicates'])): ?>
    <details class="ignored-section mt-6">
      <summary>
        ⚠️ <?= count($auditResult['duplicates']) ?> potential duplicate<?= count($auditResult['duplicates']) !== 1 ? 's' : '' ?> detected
        <span class="label-opt"> - same customer, same amount, within 24 h</span>
      </summary>
      <div class="table-wrap mt-3">
        <table>
          <thead>
            <tr>
              <th>Email</th>
              <th>Amount</th>
              <th>Orders</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($auditResult['duplicates'] as $dup): ?>
            <tr>
              <td class="td-email"><?= esc($dup['email']) ?></td>
              <td class="td-price">$<?= number_format((float)$dup['amount'], 2) ?></td>
              <td>
                <div class="spot-matches">
                  <?php foreach ($dup['orders'] as $do):
                    $doUrl = !empty($do['id']) ? $shopifyAdminBase . '/' . $do['id'] : null;
                    $doDate = $do['created_at'] ? date('Y-m-d H:i', strtotime($do['created_at'])) : '';
                  ?>
                    <?php if ($doUrl): ?>
                      <a class="spot-match-tag spot-match-tag-sh" href="<?= esc($doUrl) ?>" target="_blank" rel="noopener">
                        <?= esc($do['name'] ?? $do['order_number'] ?? '?') ?> &middot; <?= esc($doDate) ?>
                      </a>
                    <?php else: ?>
                      <span class="spot-match-tag spot-match-tag-sh">
                        <?= esc($do['name'] ?? $do['order_number'] ?? '?') ?> &middot; <?= esc($doDate) ?>
                      </span>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

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
    TTL: <?php
      if ($cacheTtl >= 86400)     echo round($cacheTtl / 86400, 1) . ' days';
      elseif ($cacheTtl >= 3600)  echo round($cacheTtl / 3600, 1) . ' h';
      else                         echo ($cacheTtl / 60) . ' min';
    ?> &mdash; set <code>CACHE_TTL</code> in .env (seconds) to change.
    Cached data is reused for repeated runs on the same date range. Expired files are deleted automatically.
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
    <span class="text-xs text-muted"><?= count($cacheEntries) ?> file<?= count($cacheEntries) !== 1 ? 's' : '' ?> cached</span>
  </div>

  <?php if (empty($cacheEntries)): ?>
    <div class="cache-table-wrap"><div class="empty-cache">No cache files yet - run an audit to populate.</div></div>
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
            <td><span class="chip chip-unknown capitalize"><?= esc($e['prefix']) ?></span></td>
            <td class="font-mono text-[.8rem] text-muted"><?= esc(substr($e['file'], 0, 24)) ?>…</td>
            <td><span class="js-localtime" data-ts="<?= $e['expires_at'] ?>"></span></td>
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
