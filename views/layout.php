<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($appTitle) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

<!-- Mobile header (hidden on desktop) -->
<header class="mobile-header">
  <div class="brand"><?= esc($appBrand) ?> <span style="color:var(--muted);font-weight:400;font-size:.75rem"><?= esc($shopifyStore) ?></span></div>
  <button class="hamburger" id="js-hamburger" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
</header>

<!-- Overlay behind open sidebar -->
<div class="sidebar-overlay" id="js-overlay"></div>

<div class="layout">

  <aside class="sidebar" id="js-sidebar">
    <div class="sidebar-header">
      <div class="brand"><?= esc($appBrand) ?></div>
      <div class="store"><?= esc($shopifyStore) ?></div>
    </div>

    <div class="sidebar-section">Tools</div>
    <ul class="sidebar-nav">
      <li>
        <a href="?" class="<?= $page === 'reports' ? 'page-active' : '' ?>">Reports</a>
      </li>
      <li>
        <a href="?page=run" class="<?= $page === 'run' ? 'page-active' : '' ?>">Run Audit</a>
      </li>
      <li>
        <a href="?page=spotcheck" class="<?= $page === 'spotcheck' ? 'page-active' : '' ?>">Spot-check</a>
      </li>
      <li>
        <a href="?page=settings" class="<?= $page === 'settings' ? 'page-active' : '' ?>">Settings</a>
      </li>
    </ul>

    <?php if (!empty($reports)): ?>
      <div class="sidebar-section">History</div>
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

    <div class="sidebar-footer">
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-ghost btn-sm btn-full" type="submit">Sign out</button>
      </form>
    </div>
  </aside>

  <main class="main">
    <?php
      $pageFile = __DIR__ . '/page-' . $page . '.php';
      if (file_exists($pageFile)) {
          require $pageFile;
      } else {
          require __DIR__ . '/page-reports.php';
      }
    ?>
  </main>

</div>

<script src="assets/app.js"></script>
</body>
</html>
