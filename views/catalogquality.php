<?= topbar('Catalog Quality', 'Active products with publishing or discovery gaps') ?>

<?= featureInfoStart('catalogquality', 'Catalog Quality') ?>
  <p><strong>Catalog Quality</strong> finds active products with gaps that hurt storefront visibility or SEO.</p>
  <ul>
    <li><strong>Not published</strong> - the product isn't published to the Online Store sales channel, so it's invisible to customers.</li>
    <li><strong>Missing SEO title / description</strong> - no custom search-engine listing preview set.</li>
    <li><strong>Not in any collection</strong> - customers can't discover the product by browsing.</li>
  </ul>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan active products</h2>
  <div class="hint">Fetches all active products and flags publishing/SEO/collection gaps.</div>

  <?php if ($cqError): ?>
    <div class="error-msg mb-3"><?= esc($cqError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_catalogquality">
    <button class="btn" type="submit">Scan products</button>
  </form>

  <?php if ($cqResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $cqResult['scanned'] ?></strong> active products
        &mdash; <strong><?= count($cqResult['rows']) ?></strong> with quality issues</span>
    </div>
  <?php endif; ?>
</div>

<?php if ($cqResult !== null): ?>
  <?php if (empty($cqResult['rows'])): ?>
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>No catalog quality issues</h3>
        <p>All <?= $cqResult['scanned'] ?> active products are published, have SEO fields set, and are in a collection.</p>
      </div>
    </div>
  <?php else: ?>
    <?php
      $shopifyProductBase = 'https://'
        . (str_contains($shopifyAdminBase, '//') ? parse_url($shopifyAdminBase, PHP_URL_HOST) : $shopifyAdminBase)
        . '/admin/products';
    ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Catalog Quality Issues</h2>
        <div class="flex items-center gap-2">
          <span><?= count($cqResult['rows']) ?> product<?= count($cqResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-catalogquality"
                  data-csv-filename="catalog-quality.csv">Export CSV</button>
        </div>
      </div>
      <?= searchInput('tbl-catalogquality', 'Filter by title, vendor, type…') ?>
      <table id="tbl-catalogquality">
        <thead>
          <tr>
            <th>Product</th>
            <th>Vendor</th>
            <th>Type</th>
            <th>Issues</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cqResult['rows'] as $row):
            $adminUrl = $row['id'] ? $shopifyProductBase . '/' . esc($row['id']) : null;
          ?>
          <tr>
            <td class="order-num">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($row['title']) ?></a>
              <?php else: ?>
                <?= esc($row['title']) ?>
              <?php endif; ?>
            </td>
            <td><?= esc($row['vendor'] ?: '-') ?></td>
            <td><?= esc($row['type'] ?: '-') ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['issues'] as $issue): ?>
                  <span class="chip chip-warn"><?= esc($issue) ?></span>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="td-actions">
              <?php if ($adminUrl): ?>
                <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener">Edit in Shopify</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
