<?= topbar('Settings', 'Configuration and connectivity') ?>

<?php if (isset($_GET['saved'])): ?>
  <div class="flash flash-ok">Settings saved.</div>
<?php endif; ?>

<div class="run-form">
  <h2>API Connection Test</h2>
  <div class="hint">Sends a lightweight request to both APIs to verify credentials and reachability.</div>

  <form method="post">
    <input type="hidden" name="action" value="test_connection">
    <button class="btn" type="submit">Test connections</button>
  </form>

  <?php if ($connResults !== null): ?>
    <div class="flex flex-col gap-3 mt-5">
      <?php foreach (['ss' => 'ShipStation', 'shopify' => 'Shopify'] as $key => $label):
        $r = $connResults[$key];
      ?>
        <div class="conn-result <?= $r['ok'] ? 'conn-ok' : 'conn-err' ?>">
          <div>
            <span class="font-bold text-[.9rem]"><?= $label ?></span>
            <?php if ($r['error']): ?>
              <div class="text-xs text-danger mt-0.5"><?= esc($r['error']) ?></div>
            <?php elseif (!$r['ok']): ?>
              <div class="text-xs text-danger mt-0.5">HTTP <?= $r['code'] ?></div>
            <?php endif; ?>
          </div>
          <div class="flex items-center gap-2">
            <?php if ($r['ms']): ?>
              <span class="text-xs text-muted"><?= $r['ms'] ?> ms</span>
            <?php endif; ?>
            <span class="badge <?= $r['ok'] ? 'badge-ok' : 'badge-warn' ?>">
              <?= $r['ok'] ? 'Connected' : 'Failed' ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (isset($_GET['unbanned'])): ?>
  <div class="flash flash-ok">✓ IP unbanned successfully.</div>
<?php endif; ?>

<div class="table-wrap mb-6">
  <div class="table-header">
    <h2>Banned IPs</h2>
    <span><?= count($bannedIps) ?> active ban<?= count($bannedIps) !== 1 ? 's' : '' ?></span>
  </div>
  <?php if (empty($bannedIps)): ?>
    <div class="empty p-6">
      <div class="icon">✅</div>
      <h3>No active bans</h3>
      <p>No IPs are currently locked out.</p>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>IP Address</th><th>Failed attempts</th><th>Banned until</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($bannedIps as $ip => $entry):
          $until = date('Y-m-d H:i', $entry['until']);
          $secs  = $entry['until'] - time();
          $days  = (int) floor($secs / 86400);
          $hours = (int) floor(($secs % 86400) / 3600);
          $remaining = $days > 0 ? "{$days}d {$hours}h left" : "{$hours}h left";
        ?>
        <tr>
          <td class="font-mono"><?= esc($ip) ?></td>
          <td><?= (int) ($entry['count'] ?? 0) ?></td>
          <td><?= esc($until) ?> <span class="text-xs text-muted">(<?= esc($remaining) ?>)</span></td>
          <td>
            <form method="post" class="inline">
              <input type="hidden" name="action" value="unban_ip">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <button class="btn btn-sm btn-danger" type="submit"
                      onclick="return confirm('Unban <?= esc($ip) ?>?')">Unban</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="run-form mb-6">
  <h2>Sidebar History</h2>
  <div class="hint">Show or hide the shared history sections in the left sidebar.</div>
  <form method="post">
    <input type="hidden" name="action" value="save_sidebar_settings">
    <div class="date-row">
      <div class="field">
        <label>📋 Missing Orders (from ShipStation)</label>
        <label class="text-sm"><input type="checkbox" name="show_missing_orders" <?= $sidebarSettings['show_missing_orders'] ? 'checked' : '' ?>> Show in sidebar</label>
      </div>
      <div class="field">
        <label>🕘 Recent Activity</label>
        <label class="text-sm"><input type="checkbox" name="show_recent_activity" <?= $sidebarSettings['show_recent_activity'] ? 'checked' : '' ?>> Show in sidebar</label>
      </div>
      <button class="btn btn-submit-end" type="submit">Save</button>
    </div>
  </form>
</div>

<div class="table-wrap mb-6">
  <div class="table-header"><h2>Notification channels</h2></div>
  <div class="flex flex-col gap-3 p-4">
    <?php foreach ([
      ['Slack',   SlackNotifier::isConfigured(),   'SLACK_WEBHOOK_URL'],
      ['Email',   EmailNotifier::isConfigured(),   'SMTP_HOST + ALERT_EMAIL'],
      ['Discord', DiscordNotifier::isConfigured(), 'DISCORD_WEBHOOK_URL'],
    ] as [$chName, $chConfigured, $chEnvHint]): ?>
      <div class="conn-result <?= $chConfigured ? 'conn-ok' : '' ?>">
        <div>
          <span class="font-bold text-[.9rem]"><?= esc($chName) ?></span>
          <?php if (!$chConfigured): ?>
            <div class="text-xs text-muted mt-0.5">Set <code><?= esc($chEnvHint) ?></code> in <code>.env</code></div>
          <?php endif; ?>
        </div>
        <span class="badge <?= $chConfigured ? 'badge-ok' : 'badge-warn' ?>">
          <?= $chConfigured ? 'Configured' : 'Not configured' ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if (($userRole ?? 'admin') === 'admin'): ?>
<?php
  $usersJsonPath = __DIR__ . '/../data/users.json';
  $multiUserMode = file_exists($usersJsonPath);
  $allUsers      = $multiUserMode ? Auth::loadUsers() : [];
?>
<div class="table-wrap mb-6">
  <div class="table-header">
    <h2>Users</h2>
    <?php if ($multiUserMode): ?>
      <span><?= count($allUsers) ?> user<?= count($allUsers) !== 1 ? 's' : '' ?></span>
    <?php else: ?>
      <span class="text-muted">Legacy mode</span>
    <?php endif; ?>
  </div>

  <?php if (isset($_GET['user_added'])): ?>
    <div class="flash flash-ok">✓ User added successfully.</div>
  <?php endif; ?>
  <?php if (isset($_GET['user_deleted'])): ?>
    <div class="flash flash-ok">✓ User deleted.</div>
  <?php endif; ?>
  <?php if (isset($_GET['user_error'])): ?>
    <div class="flash flash-err">✗ <?= esc($_GET['user_error']) ?></div>
  <?php endif; ?>

  <?php if (!$multiUserMode): ?>
    <div class="empty p-6">
      <div class="icon">ℹ️</div>
      <h3>Using legacy single-user mode</h3>
      <p>Create <code>data/users.json</code> (see <code>data/users.json.example</code>) to enable multi-user support with roles.</p>
    </div>
  <?php else: ?>
    <?php if (!empty($allUsers)): ?>
    <table>
      <thead>
        <tr><th>Username</th><th>Role</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($allUsers as $u): ?>
        <tr>
          <td class="font-mono"><?= esc($u['name']) ?></td>
          <td>
            <?php
              $roleBadge = match ($u['role'] ?? '') {
                  'admin'    => 'badge-warn',
                  'operator' => 'badge-ok',
                  default    => 'badge-neutral',
              };
            ?>
            <span class="badge <?= $roleBadge ?>"><?= esc($u['role'] ?? '') ?></span>
          </td>
          <td>
            <form method="post" class="inline">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="username" value="<?= esc($u['name']) ?>">
              <button class="btn btn-sm btn-danger" type="submit"
                      onclick="return confirm('Delete user <?= esc($u['name'] ?? '') ?>?')">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <div class="run-form mt-6">
      <h3>Add user</h3>
      <form method="post">
        <input type="hidden" name="action" value="add_user">
        <div class="form-row">
          <label>Username</label>
          <input type="text" name="new_username" required autocomplete="off">
        </div>
        <div class="form-row">
          <label>Password</label>
          <input type="password" name="new_password" required autocomplete="new-password">
        </div>
        <div class="form-row">
          <label>Role</label>
          <select name="new_role">
            <option value="viewer">viewer — read-only</option>
            <option value="operator">operator — can push, ignore, run audits</option>
            <option value="admin">admin — full access</option>
          </select>
        </div>
        <button class="btn" type="submit">Add user</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="table-wrap">
  <div class="table-header"><h2>Current configuration</h2></div>
  <table>
    <thead><tr><th>Key</th><th>Value</th></tr></thead>
    <tbody>
      <?php
        $configKeys = [
          'SHOPIFY_STORE'        => getenv('SHOPIFY_STORE') ?: '-',
          'SHOPIFY_ACCESS_TOKEN' => getenv('SHOPIFY_ACCESS_TOKEN') ? '••••••••' : 'Not set',
          'SS_API_KEY'           => getenv('SS_API_KEY')    ? '••••••••' : 'Not set',
          'SS_API_SECRET'        => getenv('SS_API_SECRET') ? '••••••••' : 'Not set',
          'SLACK_WEBHOOK_URL'    => getenv('SLACK_WEBHOOK_URL') ? 'Configured' : 'Not set',
          'CACHE_TTL'            => (function() {
              $s = (int)(getenv('CACHE_TTL') ?: 82800);
              if ($s >= 86400) return round($s / 86400, 1) . ' days (' . $s . ' s)';
              if ($s >= 3600)  return round($s / 3600, 1)  . ' h ('    . $s . ' s)';
              return $s . ' s';
          })(),
          'CACHE_RETENTION'      => (function() {
              $s = (int)(getenv('CACHE_RETENTION') ?: 1209600);
              if ($s >= 86400) return round($s / 86400, 1) . ' days (' . $s . ' s)';
              if ($s >= 3600)  return round($s / 3600, 1)  . ' h ('    . $s . ' s)';
              return $s . ' s';
          })(),
          'WEB_PASSWORD'         => getenv('WEB_PASSWORD')  ? '••••••••' : 'Not set',
        ];
        foreach ($configKeys as $k => $v):
          $missing = ($v === 'Not set');
      ?>
        <tr>
          <td class="font-mono text-sm"><?= esc($k) ?></td>
          <td class="text-sm <?= $missing ? 'text-danger' : 'text-muted' ?>"><?= esc($v) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
