<?= topbar('Webhook Health Monitor', 'Live list of all registered Shopify webhooks') ?>

<?= featureInfoStart('webhookhealth', 'Webhook Health Monitor') ?>
  <p><strong>Webhook Health Monitor</strong> fetches all webhooks currently registered on your Shopify store via the Admin REST API and displays their topic, endpoint address, and registration date.</p>
  <ul>
    <li>This page loads live data on every visit — no form is required.</li>
    <li>Shopify does not expose per-webhook delivery metrics via the REST API, so last-delivery status is not available here.</li>
    <li>To test or inspect webhook delivery logs, visit the <strong>Partner Dashboard</strong> or your app's event log in the Shopify admin.</li>
  </ul>
<?= featureInfoEnd() ?>

<?php if ($whError): ?>
  <div class="run-form">
    <div class="error-msg"><?= esc($whError) ?></div>
  </div>
<?php elseif (empty($whWebhooks)): ?>
  <div class="table-wrap">
    <div class="empty">
      <div class="icon">📭</div>
      <h3>No webhooks registered</h3>
      <p>Your Shopify store has no webhooks configured, or the API credentials do not have the <code>read_content</code> scope needed to list webhooks.</p>
    </div>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <div class="table-header">
      <h2>Registered Webhooks</h2>
      <div class="flex items-center gap-2">
        <span><?= count($whWebhooks) ?> webhook<?= count($whWebhooks) !== 1 ? 's' : '' ?></span>
        <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-webhooks"
                data-csv-filename="webhooks.csv">Export CSV</button>
      </div>
    </div>
    <?= searchInput('tbl-webhooks', 'Filter by topic or address...') ?>
    <table id="tbl-webhooks">
      <thead>
        <tr>
          <th>ID</th>
          <th>Topic</th>
          <th>Address / Endpoint</th>
          <th>Format</th>
          <th>Created</th>
          <th>API Version</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($whWebhooks as $wh): ?>
        <tr>
          <td class="font-mono text-sm"><?= esc((string)($wh['id'] ?? '')) ?></td>
          <td>
            <span class="source-badge" style="background:var(--c-info-bg);color:var(--c-info-fg)">
              <?= esc($wh['topic'] ?? '') ?>
            </span>
          </td>
          <td class="text-sm" style="word-break:break-all">
            <?= esc($wh['address'] ?? '') ?>
          </td>
          <td class="text-sm text-muted"><?= esc(strtoupper($wh['format'] ?? 'json')) ?></td>
          <td class="text-sm">
            <?= $wh['created_at'] ? esc(substr($wh['created_at'], 0, 10)) : '—' ?>
          </td>
          <td class="font-mono text-sm text-muted">
            <?= esc($wh['api_version'] ?? '—') ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
