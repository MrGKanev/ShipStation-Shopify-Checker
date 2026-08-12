<?= topbar('Slack Rules', 'Control when Slack notifications are sent') ?>

<?php if (isset($_GET['saved'])): ?>
  <div class="flash flash-ok">Slack rules saved.</div>
<?php endif; ?>

<?php if (!$slackConfigured): ?>
  <div class="table-wrap mb-4">
    <div class="empty p-6">
      <div class="icon">⚙</div>
      <h3>Slack webhook is not configured</h3>
      <p>Set <code>SLACK_WEBHOOK_URL</code> in <code>.env</code> to enable notifications.</p>
    </div>
  </div>
<?php endif; ?>

<div class="run-form">
  <h2>Notification Rules</h2>
  <div class="hint">Rules are local to this store. Scan notifications are disabled by default to avoid noisy channels.</div>
  <form method="post">
    <input type="hidden" name="action" value="save_slack_rules">
    <div class="date-row">
      <div class="field">
        <label>Audit notifications</label>
        <label class="text-sm"><input type="checkbox" name="audit_enabled" <?= $slackRules['audit_enabled'] ? 'checked' : '' ?>> Enabled</label>
      </div>
      <div class="field">
        <label>Minimum missing orders</label>
        <input type="number" min="0" name="audit_min_missing" value="<?= esc($slackRules['audit_min_missing']) ?>">
      </div>
      <div class="field">
        <label>All-clear audit messages</label>
        <label class="text-sm"><input type="checkbox" name="include_zero_audit" <?= $slackRules['include_zero_audit'] ? 'checked' : '' ?>> Send when missing = 0</label>
      </div>
      <div class="field">
        <label>Scan issue notifications</label>
        <label class="text-sm"><input type="checkbox" name="scan_enabled" <?= $slackRules['scan_enabled'] ? 'checked' : '' ?>> Enabled</label>
      </div>
      <div class="field">
        <label>Minimum scan rows</label>
        <input type="number" min="1" name="scan_min_rows" value="<?= esc($slackRules['scan_min_rows']) ?>">
      </div>
      <div class="field" style="flex:1 1 100%">
        <label>Mentions</label>
        <input type="text" name="mentions" value="<?= esc($slackRules['mentions']) ?>" placeholder="U012ABC3DE U024XYZ9FG" style="width:100%">
        <div class="hint">Space-separated Slack user or group IDs to @-mention on every notification (find a user's ID via their Slack profile → "Copy member ID"). Leave blank for none.</div>
      </div>
      <button class="btn btn-submit-end" type="submit">Save Rules</button>
    </div>
  </form>
</div>
