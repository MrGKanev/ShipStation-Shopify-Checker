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
              <?php foreach ($sc['orders'] as $o):
                $ssOrderId = $o['orderId'] ?? '';
                $ssUrl = $ssOrderId
                  ? 'https://app.shipstation.com/#!/orders/order-details/' . urlencode($ssOrderId)
                  : null;
              ?>
                <?php if ($ssUrl): ?>
                  <a class="spot-match-tag" href="<?= esc($ssUrl) ?>" target="_blank" rel="noopener" title="Open in ShipStation">
                <?php else: ?>
                  <span class="spot-match-tag">
                <?php endif; ?>
                  SS #<?= esc($o['orderNumber'] ?? '?') ?>
                  &middot; <?= esc($o['orderStatus'] ?? '?') ?>
                  <?php if (!empty($o['orderTotal'])): ?>
                    &middot; $<?= number_format((float)$o['orderTotal'], 2) ?>
                  <?php endif; ?>
                <?php echo $ssUrl ? '</a>' : '</span>'; ?>
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
