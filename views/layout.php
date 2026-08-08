<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= esc($csrfToken ?? '') ?>">
<title><?= esc($appTitle) ?><?= $appStoreNumber ? ' - #' . esc($appStoreNumber) : '' ?></title>
<link rel="stylesheet" href="assets/app.css">
<script>
  (function() {
    if (localStorage.getItem('theme') === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
  })();
</script>
</head>
<body>

<div id="js-loading-bar"></div>

<!-- Mobile header (hidden on desktop) -->
<header class="mobile-header">
  <div class="brand">
    <?php if ($appLogo): ?>
      <img src="<?= esc($appLogo) ?>" alt="<?= esc($appBrand) ?>" class="mobile-logo">
    <?php else: ?>
      <?= esc($appBrand) ?> <span class="header-store"><?= esc($shopifyStore) ?></span>
    <?php endif; ?>
  </div>
  <button class="hamburger" id="js-hamburger" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
</header>

<!-- Overlay behind open sidebar -->
<div class="sidebar-overlay" id="js-overlay"></div>

<div class="layout">

  <aside class="sidebar" id="js-sidebar">
    <div class="sidebar-header">
      <div class="sidebar-header-top">
        <?php if ($appLogo): ?>
          <img src="<?= esc($appLogo) ?>" alt="<?= esc($appBrand) ?>" class="sidebar-logo">
        <?php else: ?>
          <div class="brand"><?= esc($appBrand) ?></div>
        <?php endif; ?>
        <button class="theme-icon-btn" id="js-theme-toggle" type="button" title="Toggle theme">
          <span id="js-theme-icon">🌙</span>
        </button>
      </div>
      <div class="store"><span class="store-label">Store</span> <?= esc($shopifyStore) ?></div>
      <?php if (!empty($allStores) && count($allStores) > 1): ?>
        <form method="post" class="store-switcher">
          <input type="hidden" name="action" value="switch_store">
          <select name="store_id" class="store-select" onchange="this.form.submit()" title="Switch store">
            <?php foreach ($allStores as $s): ?>
              <option value="<?= esc($s['id']) ?>" <?= (($s['id'] ?? '') === ($storeId ?? '')) ? 'selected' : '' ?>>
                <?= esc($s['label'] ?? $s['shopify_store'] ?? $s['id']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>

    <?php
      $page = ToolRegistry::normalizePage($page, 'hub-audit');
      $activeGroup = ToolRegistry::groupOf($page);
      $pageTitles = ToolRegistry::titles();
    ?>

    <nav class="flat-nav">

      <a href="?page=dashboard" class="flat-nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" title="Dashboard">
        <span class="flat-nav-icon">🏠</span><span class="nav-label"> Dashboard</span>
        <?php if (($latestReport['count'] ?? 0) > 0): ?>
          <span class="badge badge-warn badge-sm nav-badge" style="margin-left:auto"><?= $latestReport['count'] ?></span>
        <?php endif; ?>
      </a>
      <a href="?page=hub-audit" class="flat-nav-link <?= ($activeGroup === 'audit' && $page !== 'dashboard') ? 'active' : '' ?>" title="Audit">
        <span class="flat-nav-icon">📋</span><span class="nav-label"> Audit</span>
      </a>
      <a href="?page=hub-search" class="flat-nav-link <?= $activeGroup === 'search' ? 'active' : '' ?>" title="Search &amp; Lookup">
        <span class="flat-nav-icon">🔎</span><span class="nav-label"> Search &amp; Lookup</span>
      </a>
      <a href="?page=ignored" class="flat-nav-link <?= $activeGroup === 'manage'  ? 'active' : '' ?>" title="Manage">
        <span class="flat-nav-icon">📂</span><span class="nav-label"> Manage</span>
        <?php if (count($ignoredOrders) > 0): ?>
          <span class="badge badge-warn badge-sm nav-badge" style="margin-left:auto"><?= count($ignoredOrders) ?></span>
        <?php endif; ?>
      </a>
      <a href="?page=settings" class="flat-nav-link <?= $activeGroup === 'settings' ? 'active' : '' ?>" title="Settings">
        <span class="flat-nav-icon">⚙</span><span class="nav-label"> Settings<?php if (($userRole ?? 'admin') !== 'admin'): ?> 🔒<?php endif; ?></span>
      </a>

    </nav>

    <?php if ($activeGroup === 'manage'): ?>
      <div class="sidebar-section">Manage</div>
      <ul class="sidebar-nav">
        <li><a href="?page=ignored" class="<?= $page === 'ignored' ? 'active' : '' ?>">Ignored Orders</a></li>
        <li><a href="?page=pushlog" class="<?= $page === 'pushlog' ? 'active' : '' ?>">Push Log</a></li>
        <li><a href="?page=runlog" class="<?= $page === 'runlog' ? 'active' : '' ?>">Run History</a></li>
        <li><a href="?page=jobs" class="<?= $page === 'jobs' ? 'active' : '' ?>">Job Queue</a></li>
        <li><a href="?page=actionlog" class="<?= $page === 'actionlog' ? 'active' : '' ?>">Action Log</a></li>
        <li><a href="?page=printqueue" class="<?= $page === 'printqueue' ? 'active' : '' ?>">Print Queue</a></li>
      </ul>
    <?php endif; ?>

    <?php if ($activeGroup === 'settings'): ?>
      <div class="sidebar-section">Settings</div>
      <ul class="sidebar-nav">
        <li><a href="?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">Configuration</a></li>
        <li><a href="?page=apihealth" class="<?= $page === 'apihealth' ? 'active' : '' ?>">API Health</a></li>
        <li><a href="?page=configcheck" class="<?= $page === 'configcheck' ? 'active' : '' ?>">Config Check</a></li>
        <li><a href="?page=slackrules" class="<?= $page === 'slackrules' ? 'active' : '' ?>">Slack Rules</a></li>
        <li><a href="?page=webhookhealth" class="<?= $page === 'webhookhealth' ? 'active' : '' ?>">Webhook Health</a></li>
      </ul>
    <?php endif; ?>

    <div class="sidebar-search">
      <form method="get" action="">
        <input type="hidden" name="page" value="globalsearch">
        <input type="text" name="q" class="sidebar-search-input"
               placeholder="Search order #…"
               value="<?= esc(($page === 'globalsearch') ? ($_GET['q'] ?? '') : '') ?>"
               autocomplete="off" spellcheck="false">
      </form>
    </div>

    <?php $sidebarSettings = SidebarSettings::load(); ?>

    <?php if ($sidebarSettings['show_missing_orders'] && !empty($reports)): ?>
      <div class="sidebar-section">📋 Missing Orders (from ShipStation)</div>
      <ul class="sidebar-nav">
        <?php foreach ($reports as $r): ?>
          <li class="history-item">
            <a href="?date=<?= esc($r['date']) ?>"
               class="<?= ($page === 'reports' && $r['date'] === $selectedDate) ? 'active' : '' ?>">
              <span class="date-label"><?= esc($r['date']) ?></span>
              <?= badge($r['count']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($sidebarSettings['show_recent_activity'] && !empty($recentRuns)): ?>
      <div class="sidebar-section">🕘 Recent Activity</div>
      <ul class="sidebar-nav">
        <?php foreach ($recentRuns as $run): ?>
          <?php
            $rPage   = ReportRegistry::page($run['tool']);
            $rPrefix = ReportRegistry::prefix($run['tool']);
            if (!$rPage || !$rPrefix) continue;
            $rParam  = "{$rPrefix}_history";
            $rCount  = (int) ($run['rows_found'] ?? 0);
          ?>
          <li class="history-item">
            <a href="?page=<?= esc($rPage) ?>&<?= esc($rParam) ?>=<?= esc($run['date']) ?>"
               class="<?= ($page === $rPage && ($_GET[$rParam] ?? '') === $run['date']) ? 'active' : '' ?>">
              <span class="date-label"><?= esc(ReportRegistry::icon($run['tool'])) ?> <?= esc(ReportRegistry::label($run['tool'])) ?> · <?= esc($run['date']) ?></span>
              <span class="badge badge-sm <?= $rCount > 0 ? 'badge-warn' : 'badge-ok' ?>"><?= $rCount ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="sidebar-footer">
      <form method="post">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-ghost btn-sm btn-full sidebar-signout-btn" type="submit">Sign out</button>
      </form>
      <div class="sidebar-footer-row">
        <a href="https://github.com/MrGKanev/Shopify-ops"
           class="sidebar-github" target="_blank" rel="noopener" title="View on GitHub">
          Shopify Ops v<?= esc($appVersion) ?>
        </a>
        <button class="sidebar-collapse-btn" id="js-sidebar-collapse" title="Collapse sidebar" type="button">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
        </button>
      </div>
    </div>
  </aside>

  <main class="main">
    <?php
      $allowedPages = ToolRegistry::allowedPages();
      $page         = in_array($page, $allowedPages, true) ? $page : 'hub-audit';
      $pageFile     = __DIR__ . '/' . $page . '.php';

      // Breadcrumb
      /** @var string $appBrand */
      $groupMeta = ToolRegistry::groupMeta();
      $crumbs   = [];
      $crumbs[] = ['href' => '?page=hub-audit', 'label' => esc($appBrand)];
      if ($page === 'dashboard') {
          $crumbs[] = ['label' => 'Dashboard'];
      } elseif ($page === 'hub-audit') {
          $crumbs[] = ['label' => 'Audit'];
      } elseif ($page === 'hub-search') {
          $crumbs[] = ['label' => 'Search &amp; Lookup'];
      } elseif ($page === 'settings') {
          $crumbs[] = ['label' => 'Settings'];
      } else {
          $gm = $groupMeta[$activeGroup] ?? $groupMeta['audit'];
          $crumbs[] = ['href' => $gm['href'], 'label' => $gm['label']];
          $crumbs[] = ['label' => esc($pageTitles[$page] ?? $page)];
      }
      if (count($crumbs) > 1) {
          $bc = '<nav class="breadcrumb" aria-label="Breadcrumb">';
          foreach ($crumbs as $i => $c) {
              if ($i > 0) $bc .= '<span class="breadcrumb-sep">›</span>';
              $bc .= isset($c['href'])
                  ? '<a href="' . $c['href'] . '">' . $c['label'] . '</a>'
                  : '<span class="breadcrumb-current">' . $c['label'] . '</span>';
          }
          $bc .= '</nav>';
          echo $bc;
      }

      if (file_exists($pageFile)) {
          require $pageFile;
      } else {
          require __DIR__ . '/hub-audit.php';
      }

      // Inline "History" list for this audit's own saved runs - centralised here
      // so every audit page gets it without editing each individual view.
      $inlineHistoryTool = ReportRegistry::toolForPage($page);
      $inlineHistoryRuns = $inlineHistoryTool ? AuditSnapshot::forTool($inlineHistoryTool, 30) : [];
      if ($inlineHistoryRuns) {
          $ihPrefix = ReportRegistry::prefix($inlineHistoryTool);
          $ihParam  = "{$ihPrefix}_history";
          $ihActive = $_GET[$ihParam] ?? '';
          echo '<div class="table-wrap mt-6"><div class="table-header"><h2>🕘 История</h2><span>'
              . count($inlineHistoryRuns) . ' запазени пускания</span></div><ul class="audit-history-list">';
          foreach ($inlineHistoryRuns as $run) {
              $runCount = (int) ($run['rows_found'] ?? 0);
              $active   = $run['date'] === $ihActive ? ' active' : '';
              echo '<li class="audit-history-item' . $active . '">'
                  . '<a href="?page=' . esc($page) . '&' . esc($ihParam) . '=' . esc($run['date']) . '">'
                  . '<span class="date-label">' . esc($run['date']) . '</span>'
                  . '<span class="badge ' . ($runCount > 0 ? 'badge-warn' : 'badge-ok') . '">' . $runCount . '</span>'
                  . '</a></li>';
          }
          echo '</ul></div>';
      }
    ?>
  </main>

</div>

<div id="toast-container"></div>
<script src="assets/app.js"></script>

<!-- Dry-run preview modal -->
<div id="preview-modal">
  <div class="preview-dialog">
    <div class="preview-header">
      <strong id="preview-title">Preview payload</strong>
      <button class="preview-close"
              onclick="document.getElementById('preview-modal').style.display='none'">&times;</button>
    </div>
    <pre id="preview-body">Loading…</pre>
    <div class="preview-footer">
      <span>This is the payload that <em>would</em> be sent to ShipStation - nothing has been pushed yet.</span>
      <button id="preview-copy-btn" class="preview-copy-btn" onclick="copyPreviewPayload()">Copy JSON</button>
    </div>
  </div>
</div>

<script>
function previewPush(shopifyId, orderLabel) {
  var modal = document.getElementById('preview-modal');
  var body  = document.getElementById('preview-body');
  var title = document.getElementById('preview-title');
  var btn   = document.getElementById('preview-copy-btn');
  title.textContent = 'Preview payload - ' + orderLabel;
  body.textContent  = 'Loading…';
  btn.textContent   = 'Copy JSON';
  modal.style.display = 'flex';

  var fd = new FormData();
  fd.append('action',     'preview_push');
  fd.append('shopify_id', shopifyId);
  fd.append('_csrf', document.querySelector('meta[name="csrf-token"]').content || '');

  fetch('', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.error) {
        body.textContent = 'Error: ' + data.error;
      } else {
        body.textContent = JSON.stringify(data.payload, null, 2);
      }
    })
    .catch(function(e) { body.textContent = 'Request failed: ' + e; });
}

function copyPreviewPayload() {
  var text = document.getElementById('preview-body').textContent;
  var btn  = document.getElementById('preview-copy-btn');
  navigator.clipboard.writeText(text).then(function() {
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = 'Copy JSON'; }, 2000);
  }).catch(function() {
    btn.textContent = 'Failed';
    setTimeout(function() { btn.textContent = 'Copy JSON'; }, 2000);
  });
}

document.getElementById('preview-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
</body>
</html>
