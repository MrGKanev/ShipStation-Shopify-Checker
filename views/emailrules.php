<?= topbar('Email Rules', 'Choose which checks email you, where, and how') ?>

<?php if (isset($_GET['saved'])): ?>
  <div class="flash flash-ok">Email rules saved.</div>
<?php endif; ?>

<?php if (!$emailConfigured): ?>
  <div class="table-wrap mb-4">
    <div class="empty p-6">
      <div class="icon">✉</div>
      <h3>SMTP is not configured</h3>
      <p>Set <code>SMTP_HOST</code> and <code>ALERT_EMAIL</code> in <code>.env</code> to enable email notifications.</p>
    </div>
  </div>
<?php endif; ?>

<div class="hint mb-4">
  Every check defaults to <strong>Off</strong>. <strong>Immediate</strong> emails right after that check's own
  run, once its row/missing count clears the threshold. <strong>Digest</strong> holds it for the daily rollup
  (<code>email_digest.php</code>, scheduled via cron) instead of sending one email per check. Leave the email
  field blank to use <code>ALERT_EMAIL</code>.
</div>

<form method="post">
  <input type="hidden" name="action" value="save_email_rules">

  <?php
    $byArea = [];
    foreach ($emailCatalog as $tool => $meta) {
        $byArea[$meta['area']][$tool] = $meta;
    }
  ?>

  <?php foreach ($byArea as $area => $tools): ?>
    <div class="table-wrap mb-4">
      <div class="table-header">
        <h2><?= esc($area) ?></h2>
      </div>
      <table>
        <thead>
          <tr>
            <th>Check</th>
            <th>Mode</th>
            <th>Threshold</th>
            <?php if ($area === 'Audit'): ?><th>All-clear</th><?php endif; ?>
            <th>Email (optional)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tools as $tool => $meta):
            $rule = $emailRules[$tool] ?? ['mode' => 'off', 'threshold' => 1, 'include_zero' => false, 'email' => ''];
          ?>
          <tr>
            <td class="text-sm"><?= esc($meta['label']) ?></td>
            <td>
              <select name="mode[<?= esc($tool) ?>]">
                <option value="off" <?= $rule['mode'] === 'off' ? 'selected' : '' ?>>Off</option>
                <option value="immediate" <?= $rule['mode'] === 'immediate' ? 'selected' : '' ?>>Immediate</option>
                <option value="digest" <?= $rule['mode'] === 'digest' ? 'selected' : '' ?>>Digest</option>
              </select>
            </td>
            <td>
              <input type="number" min="<?= $tool === 'run_audit' ? 0 : 1 ?>" name="threshold[<?= esc($tool) ?>]" value="<?= esc($rule['threshold']) ?>" style="width:5em">
            </td>
            <?php if ($area === 'Audit'): ?>
              <td>
                <?php if ($tool === 'run_audit'): ?>
                  <label class="text-sm"><input type="checkbox" name="include_zero[<?= esc($tool) ?>]" <?= $rule['include_zero'] ? 'checked' : '' ?>> Send at 0 too</label>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <td>
              <input type="email" name="email[<?= esc($tool) ?>]" value="<?= esc($rule['email']) ?>" placeholder="ALERT_EMAIL" style="width:14em">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>

  <button class="btn btn-submit-end" type="submit">Save Rules</button>
</form>
