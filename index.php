<?php
/**
 * ShipStation ↔ Shopify Audit — Web Dashboard
 *
 * Place this file at your web root (or the repo root if served directly).
 * Requires: PHP 8.1+, the reports/ directory written by audit.php,
 *           and WEB_PASSWORD set in .env
 */

declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2)) + ['', ''];
        if ($k && !isset($_ENV[$k])) putenv("{$k}={$v}");
    }
}

$webPassword  = getenv('WEB_PASSWORD') ?: 'changeme';
$shopifyStore = getenv('SHOPIFY_STORE') ?: 'N/A';
$reportDir    = __DIR__ . '/reports';

// ── Session / auth ────────────────────────────────────────────────────────────

session_start();

$error  = '';
$action = $_POST['action'] ?? '';

if ($action === 'login') {
    if (hash_equals($webPassword, $_POST['password'] ?? '')) {
        $_SESSION['authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $error = 'Incorrect password.';
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$authed = !empty($_SESSION['authed']);

// ── Spot-check (live ShipStation lookup) ──────────────────────────────────────

$spotResults  = null;   // null = not submitted yet
$spotInput    = '';
$spotError    = '';

if ($authed && $action === 'spotcheck') {
    $spotInput = trim($_POST['orders'] ?? '');
    $numbers   = array_filter(array_map('trim', preg_split('/[\s,]+/', $spotInput)));

    if (empty($numbers)) {
        $spotError = 'Enter at least one order number.';
    } elseif (count($numbers) > 50) {
        $spotError = 'Maximum 50 order numbers at once.';
    } else {
        require_once __DIR__ . '/src/ShipStation.php';
        $ssKey    = getenv('SS_API_KEY');
        $ssSecret = getenv('SS_API_SECRET');

        if (!$ssKey || !$ssSecret) {
            $spotError = 'SS_API_KEY / SS_API_SECRET not set in .env.';
        } else {
            try {
                $ss          = new ShipStation($ssKey, $ssSecret);
                $spotResults = [];
                foreach ($numbers as $num) {
                    $clean  = ltrim(trim($num), '#');
                    $orders = $ss->findByOrderNumber($clean);
                    $spotResults[] = [
                        'input'   => $num,
                        'number'  => $clean,
                        'orders'  => $orders,
                        'found'   => !empty($orders),
                    ];
                }
            } catch (Throwable $e) {
                $spotError = 'ShipStation error: ' . $e->getMessage();
            }
        }
    }
}

// ── On-demand audit (custom date range from the web UI) ───────────────────────

$auditResult   = null;   // null = not run yet
$auditStart    = $_POST['audit_start'] ?? date('Y-m-d', strtotime('-30 days'));
$auditEnd      = $_POST['audit_end']   ?? date('Y-m-d');
$auditError    = '';
$auditDuration = 0;

if ($authed && $action === 'run_audit') {
    $auditStart = $_POST['audit_start'] ?? '';
    $auditEnd   = $_POST['audit_end']   ?? '';

    $validStart = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditStart);
    $validEnd   = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditEnd);

    if (!$validStart || !$validEnd) {
        $auditError = 'Invalid date format. Use YYYY-MM-DD.';
    } elseif ($auditStart > $auditEnd) {
        $auditError = 'Start date must be before end date.';
    } elseif ((strtotime($auditEnd) - strtotime($auditStart)) > 366 * 86400) {
        $auditError = 'Date range cannot exceed 366 days.';
    } else {
        $ssKey        = getenv('SS_API_KEY');
        $ssSecret     = getenv('SS_API_SECRET');
        $shopifyToken = getenv('SHOPIFY_ACCESS_TOKEN');

        if (!$ssKey || !$ssSecret || !$shopifyToken) {
            $auditError = 'API credentials missing in .env (SS_API_KEY, SS_API_SECRET, SHOPIFY_ACCESS_TOKEN).';
        } else {
            require_once __DIR__ . '/src/Cache.php';
            require_once __DIR__ . '/src/ShipStation.php';
            require_once __DIR__ . '/src/Shopify.php';
            require_once __DIR__ . '/src/Comparator.php';
            require_once __DIR__ . '/src/Reporter.php';

            try {
                set_time_limit(300); // large date ranges can take a while
                $t0 = microtime(true);

                $cacheTtl = (int) (getenv('CACHE_TTL') ?: 14400);
                $cache    = new Cache(__DIR__ . '/cache', $cacheTtl);

                $ss      = new ShipStation($ssKey, $ssSecret, $cache);
                $shopify = new Shopify($shopifyStore, $shopifyToken, $cache);

                // Suppress the echo dots from the API clients
                ob_start();
                $shopifyOrders = $shopify->fetchAllOrders($auditStart, $auditEnd);
                $ssOrders      = $ss->fetchAllOrders($auditStart, $auditEnd);
                ob_end_clean();

                $ssIndex     = Comparator::buildSSIndex($ssOrders);
                $comparison  = Comparator::compare($shopifyOrders, $ssIndex);

                Reporter::saveReports($comparison['missing'], $auditStart, $auditEnd);

                $auditDuration = round(microtime(true) - $t0, 1);
                $auditResult   = [
                    'missing'  => $comparison['missing'],
                    'found'    => count($comparison['found']),
                    'skipped'  => count($comparison['skipped']),
                    'total_ss' => count($ssOrders),
                ];
            } catch (Throwable $e) {
                $auditError = $e->getMessage();
            }
        }
    }
}

// ── Cache info + flush ────────────────────────────────────────────────────────

$cacheDir     = __DIR__ . '/cache';
$cacheTtl     = (int) (getenv('CACHE_TTL') ?: 14400);
$cacheEntries = [];
$cacheFlushed = 0;

if ($authed) {
    require_once __DIR__ . '/src/Cache.php';
    $cacheObj = new Cache($cacheDir, $cacheTtl);

    if ($action === 'flush_cache') {
        $cacheFlushed = $cacheObj->flush();
    }

    $cacheEntries = $cacheObj->entries();
}

// ── Load report data (only when authed) ───────────────────────────────────────

$reports      = [];   // [{date, csvPath, txtPath, missing: []}]
$latestReport = null;

if ($authed && is_dir($reportDir)) {
    $files = glob($reportDir . '/missing_*.csv') ?: [];
    rsort($files); // newest first

    foreach ($files as $csvPath) {
        preg_match('/missing_(\d{4}-\d{2}-\d{2})\.csv$/', $csvPath, $m);
        $date    = $m[1] ?? 'unknown';
        $missing = [];

        if (($fh = fopen($csvPath, 'r')) !== false) {
            $headers = fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                $missing[] = array_combine($headers ?: [], $row);
            }
            fclose($fh);
        }

        $reports[] = [
            'date'    => $date,
            'csvPath' => $csvPath,
            'missing' => $missing,
            'count'   => count($missing),
        ];
    }

    $latestReport = $reports[0] ?? null;
}

// Which report to show — default = latest
$selectedDate   = $_GET['date'] ?? ($latestReport['date'] ?? null);
$selectedReport = null;
foreach ($reports as $r) {
    if ($r['date'] === $selectedDate) { $selectedReport = $r; break; }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function esc(mixed $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function badge(int $count): string {
    if ($count === 0) {
        return '<span class="badge badge-ok">All clear</span>';
    }
    return '<span class="badge badge-warn">' . $count . ' missing</span>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SS ↔ Shopify Audit</title>
<style>
  /* ── Reset & base ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #f8f9fb;
    --surface:   #ffffff;
    --border:    #e2e6ef;
    --accent:    #5b51f0;
    --accent-lt: #7b73f5;
    --ok:        #16a34a;
    --warn:      #ea6f10;
    --danger:    #dc2626;
    --text:      #111827;
    --muted:     #6b7280;
    --radius:    10px;
    --font:      'Inter', system-ui, -apple-system, sans-serif;
  }

  html, body { height: 100%; background: var(--bg); color: var(--text); font-family: var(--font); font-size: 15px; line-height: 1.6; }

  a { color: var(--accent-lt); text-decoration: none; }
  a:hover { text-decoration: underline; }

  /* ── Login screen ── */
  .login-wrap {
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
  }
  .login-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 2.5rem 2rem;
    width: 100%; max-width: 380px;
    box-shadow: 0 8px 40px rgba(0,0,0,.08);
  }
  .login-card .logo {
    font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem;
    color: var(--text);
  }
  .login-card .sub { color: var(--muted); font-size: .875rem; margin-bottom: 1.75rem; }
  .field { display: flex; flex-direction: column; gap: .4rem; margin-bottom: 1.1rem; }
  .field label { font-size: .8rem; font-weight: 600; color: var(--muted); letter-spacing: .05em; text-transform: uppercase; }
  .field input {
    background: var(--bg); border: 1px solid var(--border); border-radius: 7px;
    padding: .65rem .9rem; color: var(--text); font-size: .95rem;
    outline: none; transition: border-color .15s; -webkit-appearance: none;
  }
  .field input:focus { border-color: var(--accent); }
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    background: var(--accent); color: #fff; border: none; border-radius: 7px;
    padding: .7rem 1.25rem; font-size: .9rem; font-weight: 600; cursor: pointer;
    transition: background .15s, transform .1s;
  }
  .btn:hover  { background: var(--accent-lt); }
  .btn:active { transform: scale(.98); }
  .btn-full   { width: 100%; }
  .btn-sm     { padding: .4rem .85rem; font-size: .8rem; }
  .btn-ghost  { background: transparent; border: 1px solid var(--border); color: var(--muted); }
  .btn-ghost:hover { border-color: var(--muted); color: var(--text); background: transparent; }
  .error-msg { background: #fef2f2; border: 1px solid #fecaca; border-radius: 7px; padding: .65rem .9rem; color: #b91c1c; font-size: .875rem; margin-bottom: 1rem; }

  /* ── Layout ── */
  .layout { display: flex; min-height: 100vh; }

  /* Sidebar */
  .sidebar {
    width: 240px; flex-shrink: 0;
    background: var(--surface); border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    position: sticky; top: 0; height: 100vh; overflow-y: auto;
  }
  .sidebar-header { padding: 1.5rem 1.25rem 1rem; border-bottom: 1px solid var(--border); }
  .sidebar-header .brand { font-size: 1rem; font-weight: 700; color: var(--text); }
  .sidebar-header .brand span { color: var(--accent); }
  .sidebar-header .store { font-size: .75rem; color: var(--muted); margin-top: .1rem; }
  .sidebar-section { padding: .75rem 1rem .25rem; font-size: .7rem; font-weight: 700; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; }
  .sidebar-nav { list-style: none; padding: 0 .5rem; }
  .sidebar-nav li a {
    display: flex; align-items: center; justify-content: space-between;
    padding: .55rem .75rem; border-radius: 7px; font-size: .85rem; color: var(--muted);
    transition: background .12s, color .12s;
  }
  .sidebar-nav li a:hover { background: #eef0fb; color: var(--text); text-decoration: none; }
  .sidebar-nav li a.active { background: #ebe9fd; color: var(--accent); font-weight: 600; }
  .sidebar-nav li a .date-label { font-size: .78rem; }
  .sidebar-footer { margin-top: auto; padding: 1rem; border-top: 1px solid var(--border); }

  /* Main */
  .main { flex: 1; padding: 2rem 2.5rem; min-width: 0; }

  /* Top bar */
  .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem; }
  .topbar h1 { font-size: 1.35rem; font-weight: 700; }
  .topbar .meta { font-size: .8rem; color: var(--muted); margin-top: .1rem; }

  /* Stat cards */
  .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
  .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.1rem 1.25rem; }
  .stat-card .label { font-size: .75rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .06em; margin-bottom: .3rem; }
  .stat-card .value { font-size: 2rem; font-weight: 700; line-height: 1; }
  .stat-card .value.ok     { color: var(--ok); }
  .stat-card .value.warn   { color: var(--warn); }
  .stat-card .value.accent { color: var(--accent-lt); }

  /* Badge */
  .badge { display: inline-block; padding: .2rem .6rem; border-radius: 99px; font-size: .72rem; font-weight: 700; }
  .badge-ok   { background: #dcfce7; color: #15803d; }
  .badge-warn { background: #ffedd5; color: #c2410c; }

  /* Table */
  .table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .table-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
  .table-header h2 { font-size: .95rem; font-weight: 700; }
  table { width: 100%; border-collapse: collapse; }
  thead th { padding: .65rem 1.25rem; text-align: left; font-size: .72rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; border-bottom: 1px solid var(--border); white-space: nowrap; }
  tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
  tbody tr:last-child { border-bottom: none; }
  tbody tr:hover { background: rgba(255,255,255,.025); }
  tbody td { padding: .75rem 1.25rem; font-size: .875rem; vertical-align: middle; }
  .order-num { font-weight: 700; color: var(--text); font-family: monospace; font-size: .9rem; }
  .chip { display: inline-block; padding: .15rem .55rem; border-radius: 5px; font-size: .72rem; font-weight: 600; }
  .chip-paid     { background: #dcfce7; color: #15803d; }
  .chip-partial  { background: #fef9c3; color: #854d0e; }
  .chip-unpaid   { background: #fee2e2; color: #b91c1c; }
  .chip-unknown  { background: #f1f5f9; color: var(--muted); }

  /* Empty state */
  .empty { text-align: center; padding: 3.5rem 1rem; color: var(--muted); }
  .empty .icon { font-size: 2.5rem; margin-bottom: .75rem; }
  .empty h3 { color: #15803d; font-size: 1.1rem; margin-bottom: .4rem; }
  .empty p  { font-size: .875rem; }

  /* No reports */
  .no-reports { text-align: center; padding: 4rem 1rem; color: var(--muted); }
  .no-reports .icon { font-size: 3rem; margin-bottom: 1rem; }
  .no-reports h2 { font-size: 1.15rem; color: var(--text); margin-bottom: .5rem; }
  .no-reports code { background: #f1f5f9; color: var(--text); padding: .15rem .5rem; border-radius: 4px; font-size: .85rem; }

  /* History chart strip */
  .history { display: flex; align-items: flex-end; gap: 6px; margin-bottom: 2rem; }
  .history-bar { flex: 1; min-width: 18px; border-radius: 4px 4px 0 0; cursor: pointer; transition: opacity .15s; position: relative; }
  .history-bar:hover { opacity: .8; }
  .history-bar.selected { outline: 2px solid var(--accent); outline-offset: 2px; }
  .history-label { font-size: .65rem; color: var(--muted); text-align: center; margin-top: .25rem; white-space: nowrap; }

  /* Run audit */
  .run-form { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; }
  .run-form h2 { font-size: .95rem; font-weight: 700; margin-bottom: .25rem; }
  .run-form .hint { font-size: .8rem; color: var(--muted); margin-bottom: 1.25rem; }
  .date-row { display: flex; gap: .75rem; align-items: flex-end; flex-wrap: wrap; }
  .date-row .field { margin-bottom: 0; flex: 1; min-width: 140px; }
  .date-row input[type=date] {
    width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: 7px;
    padding: .65rem .9rem; color: var(--text); font-size: .875rem;
    outline: none; transition: border-color .15s; -webkit-appearance: none;
  }
  .date-row input[type=date]:focus { border-color: var(--accent); }
  .audit-summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: .75rem; margin-bottom: 1.5rem; }
  .audit-summary .stat-card .value { font-size: 1.6rem; }
  .duration-note { font-size: .75rem; color: var(--muted); margin-bottom: 1rem; }

  /* Cache table */
  .cache-section { margin-top: 2rem; }
  .cache-section h2 { font-size: .95rem; font-weight: 700; margin-bottom: .75rem; }
  .cache-meta { font-size: .8rem; color: var(--muted); margin-bottom: .75rem; }
  .cache-table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
  .cache-actions { display: flex; align-items: center; gap: .75rem; margin-bottom: .75rem; flex-wrap: wrap; }
  .btn-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
  .btn-danger:hover { background: #fecaca; }
  .tag-fresh   { background: #dcfce7; color: #15803d; border-radius: 5px; padding: .1rem .45rem; font-size: .7rem; font-weight: 700; }
  .tag-expired { background: #f1f5f9; color: var(--muted); border-radius: 5px; padding: .1rem .45rem; font-size: .7rem; font-weight: 700; }
  .empty-cache { text-align: center; padding: 2rem; color: var(--muted); font-size: .875rem; }
  .flush-notice { background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 7px; padding: .6rem 1rem; color: #15803d; font-size: .85rem; margin-bottom: 1rem; }

  /* Spot-check */
  .spot-form { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; }
  .spot-form h2 { font-size: .95rem; font-weight: 700; margin-bottom: .25rem; }
  .spot-form .hint { font-size: .8rem; color: var(--muted); margin-bottom: 1rem; }
  .spot-form textarea {
    width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: 7px;
    padding: .7rem .9rem; color: var(--text); font-size: .875rem; font-family: monospace;
    resize: vertical; min-height: 80px; outline: none; transition: border-color .15s;
  }
  .spot-form textarea:focus { border-color: var(--accent); }
  .spot-form .row { display: flex; gap: .75rem; align-items: flex-start; margin-top: .75rem; }
  .spot-results { display: flex; flex-direction: column; gap: .6rem; }
  .spot-row {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem;
    background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
    padding: .75rem 1rem;
  }
  .spot-row.found   { border-left: 3px solid var(--ok); }
  .spot-row.missing { border-left: 3px solid var(--danger); }
  .spot-row .spot-num { font-family: monospace; font-weight: 700; font-size: .9rem; }
  .spot-row .spot-detail { font-size: .8rem; color: var(--muted); }
  .spot-matches { display: flex; flex-wrap: wrap; gap: .4rem; }
  .spot-match-tag { background: #dcfce7; color: #15803d; border-radius: 5px; padding: .15rem .55rem; font-size: .75rem; font-weight: 600; }
  .spot-missing-tag { background: #fee2e2; color: #b91c1c; border-radius: 5px; padding: .15rem .55rem; font-size: .75rem; font-weight: 600; }
  .spot-status-label { font-size: .75rem; font-weight: 700; }

  /* Active sidebar link for spot-check page */
  .sidebar-nav li a.page-active { background: #ebe9fd; color: var(--accent); font-weight: 600; }

  tbody tr:hover { background: #f8f9fb; }

  @media (max-width: 700px) {
    .sidebar { display: none; }
    .main { padding: 1.25rem; }
    .stats { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>

<?php if (!$authed): ?>
<!-- ═══════════════════════════ LOGIN PAGE ══════════════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="logo">SS ↔ Shopify</div>
    <div class="sub">Order Audit Dashboard</div>

    <?php if ($error): ?>
      <div class="error-msg"><?= esc($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="login">
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" autofocus autocomplete="current-password">
      </div>
      <button class="btn btn-full" type="submit">Sign in</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════ DASHBOARD ══════════════════════════════ -->
<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="brand">SS <span>↔</span> Shopify</div>
      <div class="store"><?= esc($shopifyStore) ?></div>
    </div>

    <?php $page = $_GET['page'] ?? 'reports'; ?>

    <div class="sidebar-section">Tools</div>
    <ul class="sidebar-nav">
      <li>
        <a href="?" class="<?= $page === 'reports' ? 'page-active' : '' ?>">
          Reports
        </a>
      </li>
      <li>
        <a href="?page=run" class="<?= $page === 'run' ? 'page-active' : '' ?>">
          Run Audit
        </a>
      </li>
      <li>
        <a href="?page=spotcheck" class="<?= $page === 'spotcheck' ? 'page-active' : '' ?>">
          Spot-check
        </a>
      </li>
    </ul>

    <?php if (!empty($reports)): ?>
      <div class="sidebar-section">History</div>
      <ul class="sidebar-nav">
        <?php foreach ($reports as $r): ?>
          <li>
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

  <!-- Main content -->
  <main class="main">

    <?php if (($page ?? 'reports') === 'run'): ?>
    <!-- ══════════════ RUN AUDIT PAGE ══════════════ -->
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
      <div class="duration-note">Completed in <?= $auditDuration ?>s &mdash; report saved to <code>reports/</code></div>

      <?php $missing = $auditResult['missing']; $count = count($missing); ?>
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
          <div class="label">SS total</div>
          <div class="value accent"><?= $auditResult['total_ss'] ?></div>
        </div>
      </div>

      <div class="table-wrap">
        <div class="table-header">
          <h2>Missing Orders</h2>
          <span><?= $count ?> order<?= $count !== 1 ? 's' : '' ?></span>
        </div>
        <?php if ($count === 0): ?>
          <div class="empty">
            <div class="icon">✅</div>
            <h3>All clear!</h3>
            <p>Every paid Shopify order was found in ShipStation for this period.</p>
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Order #</th>
                <th>Created</th>
                <th>Financial</th>
                <th>Fulfillment</th>
                <th>Email</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($missing as $row):
                $financial = strtolower($row['financial_status'] ?? '');
                $chipClass = match($financial) {
                  'paid'           => 'chip-paid',
                  'partially_paid' => 'chip-partial',
                  'unpaid'         => 'chip-unpaid',
                  default          => 'chip-unknown',
                };
              ?>
              <tr>
                <td><span class="order-num">#<?= esc($row['order_number'] ?? $row['name'] ?? '?') ?></span></td>
                <td><?= esc(substr($row['created_at'] ?? '', 0, 10)) ?></td>
                <td><span class="chip <?= $chipClass ?>"><?= esc($row['financial_status'] ?? '—') ?></span></td>
                <td><?= esc($row['fulfillment_status'] ?? '—') ?></td>
                <td style="color:var(--muted)"><?= esc($row['email'] ?? '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Cache status -->
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
          <button class="btn btn-danger btn-sm" type="submit"
                  <?= empty($cacheEntries) ? 'disabled' : '' ?>>
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

    <?php elseif (($page ?? 'reports') === 'spotcheck'): ?>
    <!-- ══════════════ SPOT-CHECK PAGE ══════════════ -->
    <div class="topbar">
      <div>
        <h1>Spot-check Orders</h1>
        <div class="meta">Look up specific order numbers live in ShipStation</div>
      </div>
    </div>

    <div class="spot-form">
      <h2>Enter order numbers</h2>
      <div class="hint">One per line, or comma-separated. The # prefix is optional.</div>

      <?php if ($spotError): ?>
        <div class="error-msg" style="margin-bottom:.75rem"><?= esc($spotError) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="action" value="spotcheck">
        <textarea name="orders" placeholder="164777&#10;164789&#10;164812"><?= esc($spotInput) ?></textarea>
        <div class="row">
          <button class="btn" type="submit">Look up in ShipStation</button>
        </div>
      </form>
    </div>

    <?php if ($spotResults !== null): ?>
      <?php
        $foundCount   = count(array_filter($spotResults, fn($r) => $r['found']));
        $missingCount = count($spotResults) - $foundCount;
      ?>
      <div style="display:flex;gap:.6rem;align-items:center;margin-bottom:1rem">
        <span style="font-size:.85rem;color:var(--muted)"><?= count($spotResults) ?> checked &mdash;</span>
        <?php if ($foundCount):   ?><span class="badge badge-ok"><?= $foundCount ?> found</span><?php endif; ?>
        <?php if ($missingCount): ?><span class="badge badge-warn"><?= $missingCount ?> not found</span><?php endif; ?>
      </div>

      <div class="spot-results">
        <?php foreach ($spotResults as $sc): ?>
          <div class="spot-row <?= $sc['found'] ? 'found' : 'missing' ?>">
            <div>
              <div class="spot-num">#<?= esc($sc['number']) ?></div>
              <?php if ($sc['found']): ?>
                <div class="spot-matches" style="margin-top:.3rem">
                  <?php foreach ($sc['orders'] as $o): ?>
                    <span class="spot-match-tag"
                          title="SS order ID: <?= esc($o['orderId'] ?? '') ?>">
                      SS #<?= esc($o['orderNumber'] ?? '?') ?>
                      &middot; <?= esc($o['orderStatus'] ?? '?') ?>
                      <?php if (!empty($o['orderTotal'])): ?>
                        &middot; $<?= number_format((float)$o['orderTotal'], 2) ?>
                      <?php endif; ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="spot-detail" style="margin-top:.2rem">No matching order in ShipStation</div>
              <?php endif; ?>
            </div>
            <span class="spot-<?= $sc['found'] ? 'match' : 'missing' ?>-tag spot-status-label">
              <?= $sc['found'] ? 'Found' : 'Missing' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- ══════════════ REPORTS PAGE ══════════════ -->
    <div class="topbar">
      <div>
        <h1>Order Audit</h1>
        <?php if ($selectedReport): ?>
          <div class="meta">Report for <?= esc($selectedReport['date']) ?> &mdash;
            <a href="reports/missing_<?= esc($selectedReport['date']) ?>.csv" download>Download CSV</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($reports)): ?>
      <!-- No reports yet -->
      <div class="no-reports">
        <div class="icon">📭</div>
        <h2>No reports yet</h2>
        <p style="margin-bottom:.75rem">Run the audit script to generate the first report:</p>
        <code>php audit.php</code>
      </div>

    <?php elseif ($selectedReport): ?>
      <?php $missing = $selectedReport['missing']; $count = $selectedReport['count']; ?>

      <!-- Stat cards -->
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
      </div>

      <!-- History bar chart (up to 30 most recent) -->
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

      <!-- Table -->
      <div class="table-wrap">
        <div class="table-header">
          <h2>Missing Orders</h2>
          <span><?= $count ?> order<?= $count !== 1 ? 's' : '' ?></span>
        </div>

        <?php if ($count === 0): ?>
          <div class="empty">
            <div class="icon">✅</div>
            <h3>All clear!</h3>
            <p>Every Shopify order was found in ShipStation.</p>
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Order #</th>
                <th>Created</th>
                <th>Financial status</th>
                <th>Fulfillment</th>
                <th>Email</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($missing as $row):
                $financial = strtolower($row['financial_status'] ?? '');
                $chipClass = match($financial) {
                  'paid'         => 'chip-paid',
                  'partially_paid' => 'chip-partial',
                  'unpaid'       => 'chip-unpaid',
                  default        => 'chip-unknown',
                };
              ?>
              <tr>
                <td><span class="order-num">#<?= esc($row['order_number'] ?? '?') ?></span></td>
                <td><?= esc(substr($row['created_at'] ?? '', 0, 10)) ?></td>
                <td><span class="chip <?= $chipClass ?>"><?= esc($row['financial_status'] ?? '—') ?></span></td>
                <td><?= esc($row['fulfillment_status'] ?? '—') ?></td>
                <td style="color:var(--muted)"><?= esc($row['email'] ?? '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php endif; // reports page ?>
    <?php endif; // page switch ?>
  </main>
</div>
<?php endif; // authed ?>

</body>
</html>
