function on(selector, event, handler) {
  document.querySelectorAll(selector).forEach(function(el) {
    el.addEventListener(event, handler);
  });
}

// Toast notifications - move .flash elements into the toast container
(function() {
  var container = document.getElementById('toast-container');
  if (!container) return;
  document.querySelectorAll('.flash').forEach(function(el) {
    el.classList.add('toast');
    if (el.classList.contains('flash-ok'))  el.classList.add('toast-ok');
    if (el.classList.contains('flash-err')) el.classList.add('toast-err');
    container.appendChild(el);
    setTimeout(function() {
      el.classList.add('toast-dismiss');
      setTimeout(function() { if (el.parentNode) el.remove(); }, 300);
    }, 4500);
  });
})();

// Local timezone for cache expiry timestamps
document.querySelectorAll('.js-localtime').forEach(function(el) {
  var ts = parseInt(el.dataset.ts, 10);
  if (!ts) return;
  el.textContent = new Date(ts * 1000).toLocaleString([], {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit'
  });
});

// Desktop sidebar collapse
(function() {
  var sidebar    = document.getElementById('js-sidebar');
  var toggleBtn  = document.getElementById('js-sidebar-collapse');
  if (!sidebar || !toggleBtn) return;

  var MOBILE_BREAKPOINT = 701;
  var KEY = 'sidebarCollapsed';

  function isMobile() { return window.innerWidth < MOBILE_BREAKPOINT; }

  function applyCollapsed(collapsed) {
    if (isMobile()) return;
    if (collapsed) {
      sidebar.classList.add('collapsed');
    } else {
      sidebar.classList.remove('collapsed');
    }
  }

  if (localStorage.getItem(KEY) === '1') {
    applyCollapsed(true);
  }

  toggleBtn.addEventListener('click', function() {
    var nowCollapsed = sidebar.classList.contains('collapsed');
    applyCollapsed(!nowCollapsed);
    localStorage.setItem(KEY, nowCollapsed ? '0' : '1');
  });

  window.addEventListener('resize', function() {
    if (isMobile()) {
      sidebar.classList.remove('collapsed');
    } else if (localStorage.getItem(KEY) === '1') {
      sidebar.classList.add('collapsed');
    }
  });
})();

// Mobile sidebar toggle
(function() {
  var sidebar  = document.getElementById('js-sidebar');
  var overlay  = document.getElementById('js-overlay');
  var hamburger = document.getElementById('js-hamburger');
  if (!sidebar || !overlay || !hamburger) return;

  function open() {
    sidebar.classList.add('open');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  hamburger.addEventListener('click', function() {
    sidebar.classList.contains('open') ? close() : open();
  });
  overlay.addEventListener('click', close);

  // Close sidebar on nav link click (mobile)
  sidebar.querySelectorAll('a').forEach(function(a) {
    a.addEventListener('click', close);
  });
})();

// ── Date range preset buttons ─────────────────────────────────────────────────
(function() {
  document.querySelectorAll('.preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var days  = parseInt(this.dataset.days);
      var end   = new Date();
      var start = new Date();
      start.setDate(end.getDate() - days + 1);
      var fmt = function(d) {
        return d.getFullYear() + '-' +
          String(d.getMonth() + 1).padStart(2, '0') + '-' +
          String(d.getDate()).padStart(2, '0');
      };
      var form   = this.closest('form');
      var inputs = form ? Array.from(form.querySelectorAll('input[type=date]')) : [];
      var s = inputs[0] || null;
      var e = inputs[1] || null;
      if (s) s.value = fmt(start);
      if (e) e.value = fmt(end);
      var row = this.closest('.preset-row');
      if (row) row.querySelectorAll('.preset-btn').forEach(function(b) { b.classList.remove('preset-btn-active'); });
      this.classList.add('preset-btn-active');
    });
  });
})();

// ── Generic form loading state (top bar + button spinner) ─────────────────────
(function() {
  var HEAVY = {
    run_audit: true, spotcheck: true, find_dupes: true, find_refunds: true,
    scan_addresses: true, scan_emails: true, find_orphans: true, tag_audit: true,
    tag_search: true, compare_orders: true, customer_lookup: true,
    metafield_search: true, metafield_lookup: true, lookup_tracking: true,
    scan_hvorders: true, scan_repeat_refunds: true, scan_failed_shipments: true,
    scan_addr_changes: true, scan_bundle: true, scan_sla: true,
    scan_country_mismatch: true, scan_partial_fulfill: true,
    scan_onhold: true, scan_notracking: true, scan_postshipaddr: true,
    scan_noteflags: true, scan_ssshipped: true, scan_inventory: true,
    scan_zombieproducts: true, scan_addrdupes: true,
    scan_products: true, scan_skudupes: true, scan_order_edits: true,
    order_timeline: true, packingslip: true, test_connection: true,
    scan_activess: true, scan_discountabuse: true, scan_tagpolicy: true,
    scan_inventoryaging: true, scan_shipmentaging: true, refresh_api_health: true
  };

  var bar = document.getElementById('js-loading-bar');

  document.querySelectorAll('form').forEach(function(form) {
    var actionInput = form.querySelector('input[name="action"]');
    if (!actionInput || !HEAVY[actionInput.value]) return;

    form.addEventListener('submit', function() {
      // Top bar
      if (bar) { bar.style.width = '0'; bar.classList.add('running'); }

      // Disable + spinner on the submit button
      var btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.dataset.origText = btn.textContent;
        btn.classList.add('btn-loading');
        btn.disabled = true;
      }

      // Keep bar visible until new page paints
      window.addEventListener('beforeunload', function() {
        if (bar) { bar.style.width = '100%'; bar.style.transition = 'none'; }
      });
    });
  });
})();


// Search / filter (coordinates with type-filter if present)
function applyTableFilters(targetId) {
  var tbody  = document.querySelector('#' + targetId);
  if (!tbody) return;
  var search = document.querySelector('.js-search[data-target="' + targetId + '"]');
  var type   = document.querySelector('.js-type-filter[data-target="' + targetId + '"]');
  var q      = search ? search.value.toLowerCase() : '';
  var t      = type   ? type.value.toLowerCase()   : '';
  tbody.querySelectorAll('tr').forEach(function(tr) {
    var text      = tr.textContent.toLowerCase();
    var typeChip  = tr.querySelector('td .chip[class*="chip-type-"]');
    var typeText  = typeChip ? typeChip.textContent.trim().toLowerCase() : '';
    var matchText = !q || text.includes(q);
    var matchType = !t || typeText === t;
    tr.style.display = (matchText && matchType) ? '' : 'none';
  });
}

document.querySelectorAll('.js-search').forEach(function(input) {
  if (!document.querySelector('#' + input.dataset.target)) return;
  input.addEventListener('input', function() { applyTableFilters(input.dataset.target); });
});

document.querySelectorAll('.js-type-filter').forEach(function(sel) {
  if (!document.querySelector('#' + sel.dataset.target)) return;
  sel.addEventListener('change', function() { applyTableFilters(sel.dataset.target); });
});

// Bulk select helpers
function updateBulkBar(barId) {
  var bar     = document.getElementById('bar-' + barId);
  var counter = document.getElementById('cnt-' + barId);
  if (!bar) return;
  var checked = document.querySelectorAll('[data-bar="' + barId + '"].js-row-check:checked').length;
  if (counter) counter.textContent = checked + ' selected';
  bar.classList.toggle('bulk-bar-active', checked > 0);
}

// Select-all checkboxes
document.querySelectorAll('.js-select-all').forEach(function(master) {
  master.addEventListener('change', function() {
    var tbody  = document.getElementById(master.dataset.target);
    var barId  = master.dataset.bar;
    if (!tbody) return;
    tbody.querySelectorAll('.js-row-check').forEach(function(cb) {
      cb.checked = master.checked;
    });
    updateBulkBar(barId);
  });
});

// Dark mode toggle
(function() {
  var btn  = document.getElementById('js-theme-toggle');
  var icon = document.getElementById('js-theme-icon');
  if (!btn) return;

  function apply(dark) {
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    if (icon) icon.textContent = dark ? '☀️' : '🌙';
  }

  var isDark = localStorage.getItem('theme') === 'dark';
  apply(isDark);

  btn.addEventListener('click', function() {
    isDark = !isDark;
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    apply(isDark);
  });
})();

// Inline ignore form toggle
document.querySelectorAll('.js-ignore-toggle').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var form = document.getElementById('ignore-form-' + btn.dataset.order);
    if (form) form.style.display = form.style.display === 'none' ? 'flex' : 'none';
  });
});


// ── Metafields page ──────────────────────────────────────────────────────────
function fillSearch(ns, key) {
  var nsEl  = document.getElementById('js-search-ns');
  var keyEl = document.getElementById('js-search-key');
  if (nsEl)  nsEl.value  = ns;
  if (keyEl) keyEl.value = key;
  var form = document.getElementById('js-mf-search-form');
  if (form) { form.scrollIntoView({ behavior: 'smooth', block: 'center' }); keyEl.focus(); }
  var filter = document.getElementById('js-mf-filter');
  if (filter) filter.value = ns + '.' + key;
}

// ── Feature info collapsible ──────────────────────────────────────────────────
(function() {
  var STORE_KEY = 'featureInfoOpen';
  function getState() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY) || '{}'); } catch(e) { return {}; }
  }
  function saveState(key, open) {
    var s = getState(); s[key] = open;
    localStorage.setItem(STORE_KEY, JSON.stringify(s));
  }

  document.querySelectorAll('.feature-info').forEach(function(block) {
    var key  = block.dataset.infoKey || window.location.search || 'default';
    var body = block.querySelector('.feature-info-body');
    var btn  = block.querySelector('.feature-info-toggle');
    if (!body || !btn) return;

    var state = getState();
    var open  = state[key] === true; // collapsed by default

    function apply(o) {
      body.classList.toggle('open', o);
      btn.setAttribute('aria-expanded', o ? 'true' : 'false');
    }
    apply(open);

    btn.addEventListener('click', function() {
      open = !open;
      apply(open);
      saveState(key, open);
    });
  });
})();

// ── Order detail expand (Customer Lookup) ─────────────────────────────────────
var _orderDetailCache = {};

function toggleOrderDetail(orderId, summaryRow) {
  var detailRow = document.getElementById('od-' + orderId);
  var panel     = document.getElementById('panel-' + orderId);
  var icon      = document.getElementById('icon-' + orderId);
  if (!detailRow || !panel) return;

  var isOpen = detailRow.style.display !== 'none';
  if (isOpen) {
    detailRow.style.display = 'none';
    if (icon) icon.textContent = '▶';
    return;
  }

  detailRow.style.display = '';
  if (icon) icon.textContent = '▼';

  if (_orderDetailCache[orderId]) {
    panel.innerHTML = _orderDetailCache[orderId];
    return;
  }

  panel.innerHTML = '<div class="order-detail-loading">Loading…</div>';

  var fd = new FormData();
  fd.append('action',     'order_detail');
  fd.append('shopify_id', orderId);
  var csrf = document.querySelector('meta[name="csrf-token"]');
  fd.append('_csrf', csrf ? csrf.content : '');

  fetch('', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.error) {
        panel.innerHTML = '<div class="order-detail-loading" style="color:var(--danger)">Error: ' + data.error + '</div>';
        return;
      }
      var html = renderOrderDetail(data.order);
      _orderDetailCache[orderId] = html;
      panel.innerHTML = html;
    })
    .catch(function(e) {
      panel.innerHTML = '<div class="order-detail-loading" style="color:var(--danger)">Request failed.</div>';
    });
}

function renderOrderDetail(o) {
  var html = '<div class="od-grid">';

  // ── Line items ──
  var items = o.line_items || [];
  html += '<div class="od-section"><div class="od-section-title">Items (' + items.length + ')</div>';
  html += '<div class="od-items">';
  items.forEach(function(li) {
    var price = li.price ? parseFloat(li.price).toFixed(2) : '0.00';
    html += '<div class="od-item">';
    html += '<div class="od-item-name">' + esc(li.title || '') + (li.variant_title ? ' <span class="od-variant">· ' + esc(li.variant_title) + '</span>' : '') + '</div>';
    html += '<div class="od-item-meta">Qty: ' + (li.quantity || 1) + ' &nbsp;·&nbsp; $' + price + ' ea';
    if (li.sku) html += ' &nbsp;·&nbsp; SKU: ' + esc(li.sku);
    html += '</div></div>';
  });
  html += '</div></div>';

  // ── Shipping address ──
  var addr = o.shipping_address;
  if (addr) {
    html += '<div class="od-section"><div class="od-section-title">Ship to</div>';
    html += '<div class="od-address">';
    if (addr.name) html += '<div>' + esc(addr.name) + '</div>';
    if (addr.address1) html += '<div>' + esc(addr.address1) + '</div>';
    if (addr.address2) html += '<div>' + esc(addr.address2) + '</div>';
    html += '<div>' + [addr.city, addr.province_code, addr.zip].filter(Boolean).map(esc).join(', ') + '</div>';
    if (addr.country) html += '<div>' + esc(addr.country) + '</div>';
    if (addr.phone) html += '<div class="od-muted">' + esc(addr.phone) + '</div>';
    html += '</div></div>';
  }

  // ── Shipping method ──
  var shipping = (o.shipping_lines || [])[0];
  if (shipping) {
    html += '<div class="od-section"><div class="od-section-title">Shipping</div>';
    html += '<div class="od-address"><div>' + esc(shipping.title || '') + '</div>';
    if (shipping.price) html += '<div>$' + parseFloat(shipping.price).toFixed(2) + '</div>';
    html += '</div></div>';
  }

  // ── Note ──
  if (o.note) {
    html += '<div class="od-section"><div class="od-section-title">Note</div>';
    html += '<div class="od-note">' + esc(o.note) + '</div></div>';
  }

  // ── Financials ──
  html += '<div class="od-section"><div class="od-section-title">Summary</div><div class="od-address">';
  if (o.subtotal_price)  html += '<div class="od-fin-row"><span>Subtotal</span><span>$' + parseFloat(o.subtotal_price).toFixed(2) + '</span></div>';
  if (o.total_discounts && parseFloat(o.total_discounts) > 0)
    html += '<div class="od-fin-row" style="color:var(--ok)"><span>Discount</span><span>-$' + parseFloat(o.total_discounts).toFixed(2) + '</span></div>';
  if (o.total_tax)       html += '<div class="od-fin-row"><span>Tax</span><span>$' + parseFloat(o.total_tax).toFixed(2) + '</span></div>';
  if (o.total_price)     html += '<div class="od-fin-row" style="font-weight:600"><span>Total</span><span>$' + parseFloat(o.total_price).toFixed(2) + '</span></div>';
  html += '</div></div>';

  html += '</div>';
  return html;
}

function esc(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── CSV Export ────────────────────────────────────────────────────────────────
function exportTableCSV(tableSelector, filename) {
  var table = document.querySelector(tableSelector);
  if (!table) return;
  var rows = [];
  table.querySelectorAll('tr').forEach(function(tr) {
    var cells = [];
    tr.querySelectorAll('th, td').forEach(function(td) {
      // Skip checkbox columns and action columns
      if (td.querySelector('input[type=checkbox]')) return;
      if (td.classList.contains('td-actions') || td.classList.contains('col-actions') || td.classList.contains('col-check')) return;
      var text = td.textContent.replace(/\s+/g, ' ').trim();
      cells.push('"' + text.replace(/"/g, '""') + '"');
    });
    if (cells.length) rows.push(cells.join(','));
  });
  var csv = rows.join('\r\n');
  var blob = new Blob([csv], { type: 'text/csv' });
  var url  = URL.createObjectURL(blob);
  var a    = document.createElement('a');
  a.href     = url;
  a.download = filename || 'export.csv';
  document.body.appendChild(a);
  a.click();
  setTimeout(function() { URL.revokeObjectURL(url); a.remove(); }, 1000);
}

on('[data-csv-btn]', 'click', function() {
  exportTableCSV(this.dataset.csvBtn, this.dataset.csvFilename || 'export.csv');
});

// ── CSV Export (selected rows only) ──────────────────────────────────────────
function exportSelectedCSV(barId, tableSelector, filename) {
  var table = document.querySelector(tableSelector);
  if (!table) return;
  var checkedRows = new Set();
  document.querySelectorAll('[data-bar="' + barId + '"].js-row-check:checked').forEach(function(cb) {
    var tr = cb.closest('tr');
    if (tr) checkedRows.add(tr);
  });
  if (!checkedRows.size) return;
  var rows = [];
  // Header row
  var headerCells = [];
  table.querySelectorAll('thead th').forEach(function(th) {
    if (th.classList.contains('col-check') || th.querySelector('input[type=checkbox]')) return;
    headerCells.push('"' + th.textContent.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"');
  });
  if (headerCells.length) rows.push(headerCells.join(','));
  // Data rows
  table.querySelectorAll('tbody tr').forEach(function(tr) {
    if (!checkedRows.has(tr)) return;
    var cells = [];
    tr.querySelectorAll('td').forEach(function(td) {
      if (td.querySelector('input[type=checkbox]')) return;
      if (td.classList.contains('td-actions') || td.classList.contains('col-actions') || td.classList.contains('col-check')) return;
      cells.push('"' + td.textContent.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"');
    });
    if (cells.length) rows.push(cells.join(','));
  });
  var csv  = rows.join('\r\n');
  var blob = new Blob([csv], { type: 'text/csv' });
  var url  = URL.createObjectURL(blob);
  var a    = document.createElement('a');
  a.href = url; a.download = filename || 'selected.csv';
  document.body.appendChild(a); a.click();
  setTimeout(function() { URL.revokeObjectURL(url); a.remove(); }, 1000);
}

// ── Quick Copy ────────────────────────────────────────────────────────────────
on('[data-copy]', 'click', function() {
  var btn  = this;
  var text = btn.dataset.copy;
  navigator.clipboard.writeText(text).then(function() {
    var orig = btn.textContent;
    btn.textContent = '✓';
    setTimeout(function() { btn.textContent = orig; }, 1500);
  });
});

// ── Search History ────────────────────────────────────────────────────────────
(function() {
  var HISTORY_KEY = 'searchHistory';
  var MAX         = 8;

  function getHistory() {
    try { return JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); } catch(e) { return []; }
  }

  function saveHistory(list) {
    localStorage.setItem(HISTORY_KEY, JSON.stringify(list.slice(0, MAX)));
  }

  function addToHistory(val) {
    if (!val || val.length < 2) return;
    var list = getHistory().filter(function(v) { return v !== val; });
    list.unshift(val);
    saveHistory(list);
  }

  function buildDropdown(input) {
    var wrap = input.parentNode;
    wrap.style.position = 'relative';
    var dd = document.createElement('ul');
    dd.className = 'search-history-dropdown';
    dd.style.cssText = 'display:none;position:absolute;top:100%;left:0;right:0;z-index:200;' +
      'background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);' +
      'margin-top:2px;padding:4px 0;list-style:none;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;';
    wrap.appendChild(dd);

    function show() {
      var list = getHistory();
      if (!list.length) return;
      dd.innerHTML = '';
      list.forEach(function(v) {
        var li = document.createElement('li');
        li.textContent = v;
        li.style.cssText = 'padding:7px 12px;cursor:pointer;font-size:.875rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
        li.addEventListener('mousedown', function(e) {
          e.preventDefault();
          input.value = v;
          dd.style.display = 'none';
          input.form && input.form.dispatchEvent(new Event('input'));
        });
        li.addEventListener('mouseover', function() { li.style.background = 'var(--bg)'; });
        li.addEventListener('mouseout',  function() { li.style.background = ''; });
        dd.appendChild(li);
      });
      dd.style.display = 'block';
    }

    input.addEventListener('focus', show);
    input.addEventListener('blur',  function() { setTimeout(function() { dd.style.display = 'none'; }, 150); });
    input.addEventListener('input', function() { if (input.value) dd.style.display = 'none'; else show(); });

    return dd;
  }

  // Attach to tag search input
  var tagInput = document.querySelector('input[name="tag_input"]');
  if (tagInput) {
    buildDropdown(tagInput);
    tagInput.form && tagInput.form.addEventListener('submit', function() {
      addToHistory(tagInput.value.trim());
    });
  }

  // Attach to spotcheck textarea (convert newlines to space for history)
  var spotInput = document.querySelector('textarea[name="orders"]');
  if (spotInput) {
    // For spotcheck we save/restore the raw textarea value as one history entry
    var spotWrap = spotInput.parentNode;
    spotWrap.style.position = 'relative';
    var spotDd = document.createElement('ul');
    spotDd.className = 'search-history-dropdown';
    spotDd.style.cssText = 'display:none;position:absolute;top:0;left:calc(100% + 8px);z-index:200;' +
      'background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);' +
      'padding:4px 0;list-style:none;box-shadow:0 4px 16px rgba(0,0,0,.12);min-width:180px;max-height:240px;overflow-y:auto;';
    var spotLabel = document.createElement('div');
    spotLabel.textContent = 'Recent';
    spotLabel.style.cssText = 'padding:4px 12px 2px;font-size:.7rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;';
    spotDd.appendChild(spotLabel);
    spotWrap.appendChild(spotDd);

    var SPOT_KEY = 'spotcheckHistory';
    function getSpotHistory() {
      try { return JSON.parse(localStorage.getItem(SPOT_KEY) || '[]'); } catch(e) { return []; }
    }
    function showSpotHistory() {
      var list = getSpotHistory();
      var existing = spotDd.querySelectorAll('li');
      existing.forEach(function(el) { el.remove(); });
      if (!list.length) { spotDd.style.display = 'none'; return; }
      list.forEach(function(v) {
        var li = document.createElement('li');
        li.textContent = v.replace(/\n/g, ', ').substring(0, 40) + (v.length > 40 ? '…' : '');
        li.style.cssText = 'padding:6px 12px;cursor:pointer;font-size:.8rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;';
        li.title = v;
        li.addEventListener('mousedown', function(e) {
          e.preventDefault();
          spotInput.value = v;
          spotDd.style.display = 'none';
        });
        li.addEventListener('mouseover', function() { li.style.background = 'var(--bg)'; });
        li.addEventListener('mouseout',  function() { li.style.background = ''; });
        spotDd.appendChild(li);
      });
      spotDd.style.display = 'block';
    }
    spotInput.addEventListener('focus', showSpotHistory);
    spotInput.addEventListener('blur',  function() { setTimeout(function() { spotDd.style.display = 'none'; }, 150); });
    var spotForms = document.querySelectorAll('form input[name="action"][value="spotcheck"]');
    spotForms.forEach(function(inp) {
      inp.form && inp.form.addEventListener('submit', function() {
        var val = spotInput.value.trim();
        if (!val) return;
        var list = getSpotHistory().filter(function(v) { return v !== val; });
        list.unshift(val);
        localStorage.setItem(SPOT_KEY, JSON.stringify(list.slice(0, MAX)));
      });
    });
  }

  // Also save tag search from URL on page load (if result is shown)
  var tagCode = document.querySelector('code');
  if (tagCode && tagInput) {
    // already saved on submit
  }
})();

// ── Note template placeholder substitution ────────────────────────────────────
document.querySelectorAll('.js-note-template-select').forEach(function(sel) {
  sel.addEventListener('change', function() {
    var form     = sel.closest('form');
    if (!form) return;
    var textarea = form.querySelector('.js-note-textarea');
    if (!textarea) return;
    var template = sel.value;
    if (!template) { textarea.value = ''; return; }
    var orderNum = form.dataset.order || '';
    var email    = form.dataset.email  || '';
    var today    = new Date().toISOString().slice(0, 10);
    textarea.value = template
      .replace(/\{\{order_number\}\}/g, orderNum)
      .replace(/\{\{date\}\}/g,         today)
      .replace(/\{\{email\}\}/g,        email);
  });
});

// ── Keyboard shortcut: / focuses first search input ───────────────────────────
(function() {
  document.addEventListener('keydown', function(e) {
    if (e.key !== '/') return;
    var tag = document.activeElement && document.activeElement.tagName.toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
    e.preventDefault();
    var el = document.querySelector(
      'input[name="tag_input"], input[name="orders"], textarea[name="orders"], ' +
      '.js-search, input[type="text"]:not([readonly]), input[type="search"]'
    );
    if (el) { el.focus(); el.select && el.select(); }
  });
})();

