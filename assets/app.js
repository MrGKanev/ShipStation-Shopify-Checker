// Local timezone for cache expiry timestamps
document.querySelectorAll('.js-localtime').forEach(function(el) {
  var ts = parseInt(el.dataset.ts, 10);
  if (!ts) return;
  el.textContent = new Date(ts * 1000).toLocaleString([], {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit'
  });
});

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

// Audit loading overlay
(function() {
  var form = document.getElementById('js-audit-form');
  var overlay = document.getElementById('js-audit-loading');
  if (!form || !overlay) return;

  var steps = [
    { id: 'lstep-shopify', delay: 0 },
    { id: 'lstep-ss',      delay: 4000 },
    { id: 'lstep-compare', delay: 9000 },
  ];

  form.addEventListener('submit', function() {
    overlay.classList.add('active');

    steps.forEach(function(s, i) {
      setTimeout(function() {
        // Mark previous step done
        if (i > 0) {
          var prev = document.getElementById(steps[i - 1].id);
          if (prev) { prev.classList.remove('active'); prev.classList.add('done'); }
        }
        var el = document.getElementById(s.id);
        if (el) el.classList.add('active');
      }, s.delay);
    });
  });
})();

// Search / filter
document.querySelectorAll('.js-search').forEach(function(input) {
  var tbody = document.querySelector('#' + input.dataset.target);
  if (!tbody) return;
  input.addEventListener('input', function() {
    var q = input.value.toLowerCase();
    tbody.querySelectorAll('tr').forEach(function(tr) {
      tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
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
    btn.querySelector('span + span') || (btn.lastChild.textContent = dark ? ' Light mode' : ' Dark mode');
    btn.childNodes.forEach(function(n) {
      if (n.nodeType === 3) n.textContent = dark ? ' Light mode' : ' Dark mode';
    });
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
