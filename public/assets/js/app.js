(function () {
  'use strict';

  // Sidebar toggle (mobile)
  var overlay = document.querySelector('.sidebar-overlay');
  var openBtn = document.querySelector('[data-sidebar-toggle]');
  var closeBtn = document.querySelector('[data-sidebar-close]');
  var sidebar = document.getElementById('sidebar');
  var main = document.querySelector('.main');
  var mobile = window.matchMedia('(max-width: 900px)');
  var previousFocus;

  function openSidebar() {
    if (!sidebar || !mobile.matches) return;
    previousFocus = document.activeElement;
    sidebar.inert = false;
    document.body.classList.add('sidebar-open');
    if (overlay) overlay.hidden = false;
    if (main) main.inert = true;
    if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    if (closeBtn) closeBtn.focus();
  }
  function closeSidebar() {
    var wasOpen = document.body.classList.contains('sidebar-open');
    document.body.classList.remove('sidebar-open');
    if (overlay) overlay.hidden = true;
    if (main) main.inert = false;
    if (sidebar) sidebar.inert = mobile.matches;
    if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    if (wasOpen && mobile.matches && previousFocus) previousFocus.focus();
    if (!mobile.matches && closeBtn === document.activeElement && main) main.querySelector('main').focus();
  }
  closeSidebar();
  mobile.addEventListener('change', closeSidebar);
  document.addEventListener('keydown', function (event) {
    if (!document.body.classList.contains('sidebar-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); closeSidebar(); }
    if (event.key !== 'Tab') return;
    var controls = Array.from(sidebar.querySelectorAll('a[href], button, input, select, textarea, [tabindex="0"]')).filter(function (el) {
      return !el.disabled && el.getClientRects().length > 0;
    });
    var first = controls[0], last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });

  if (openBtn) openBtn.addEventListener('click', openSidebar);
  if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Nav group toggles
  document.querySelectorAll('.nav-group-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = btn.parentElement;
      var open = group.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // Navigation search
  var search = document.getElementById('nav-search');
  if (search) {
    var savedGroups = null;
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      var groups = document.querySelectorAll('.sidebar-nav .nav-group');
      var links = document.querySelectorAll('.sidebar-nav .nav-link');
      var matches = 0;
      if (q && savedGroups === null) savedGroups = Array.from(groups).map(function (group) { return group.classList.contains('is-open'); });

      groups.forEach(function (group, index) {
        var label = group.querySelector('.nav-group-toggle .nav-label');
        var groupText = label ? label.textContent.toLowerCase() : '';
        var children = group.querySelectorAll('.nav-child');
        var anyChildMatch = Array.from(children).some(function (c) {
          return c.textContent.toLowerCase().indexOf(q) !== -1;
        });
        var groupMatch = groupText.indexOf(q) !== -1;

        var visible = !q || groupMatch || anyChildMatch;
        var open = q ? visible : (savedGroups ? savedGroups[index] : group.classList.contains('is-open'));
        group.hidden = !visible;
        group.classList.toggle('is-open', open);
        group.querySelector('.nav-group-toggle').setAttribute('aria-expanded', String(open));
        children.forEach(function (child) { child.parentElement.hidden = !!q && !groupMatch && child.textContent.toLowerCase().indexOf(q) === -1; });
        if (visible) matches++;
      });

      links.forEach(function (link) {
        var visible = !q || link.textContent.toLowerCase().indexOf(q) !== -1;
        link.parentElement.hidden = !visible;
        if (visible) matches++;
      });
      if (!q) savedGroups = null;
      var empty = document.getElementById('nav-empty');
      if (empty) empty.hidden = matches > 0;
    });
  }

  // cPanel-style function search: filter the dashboard feature grid.
  var fnSearch = document.getElementById('fn-search');
  if (fnSearch) {
    fnSearch.addEventListener('input', function () {
      var q = fnSearch.value.trim().toLowerCase();
      document.querySelectorAll('.fn-section').forEach(function (section) {
        var items = section.querySelectorAll('[data-fn-label]');
        var visibleCount = 0;
        items.forEach(function (item) {
          var text = (item.getAttribute('data-fn-label') || '') + ' ' + item.textContent;
          var show = !q || text.toLowerCase().indexOf(q) !== -1;
          item.hidden = !show;
          if (show) visibleCount++;
        });
        var groupName = section.getAttribute('data-fn-group') || '';
        section.hidden = !q || groupName.toLowerCase().indexOf(q) !== -1 ? false : (visibleCount === 0);
      });
    });
  }

  // Confirmation dialogs
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener(el.tagName === 'FORM' ? 'submit' : 'click', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
  });

  // Auto-submit filter controls (CSP-safe replacement for inline onchange).
  document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
    el.addEventListener('change', function () {
      if (el.form) el.form.submit();
    });
  });

  // Keep feedback visible and connect server validation messages to their fields.
  document.querySelectorAll('[data-error-field]').forEach(function (message, index) {
    var field = Array.from(document.querySelectorAll('input, select, textarea')).find(function (input) {
      return input.name === message.dataset.errorField && input.type !== 'hidden';
    });
    if (!field) return;
    if (!field.id) field.id = 'invalid-field-' + index;
    var inline = document.createElement('p');
    inline.id = 'field-error-' + index;
    inline.className = 'field-error';
    inline.textContent = message.textContent;
    field.insertAdjacentElement('afterend', inline);
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('aria-describedby', ((field.getAttribute('aria-describedby') || '') + ' ' + inline.id).trim());
    var link = document.createElement('a');
    link.href = '#' + field.id;
    link.textContent = message.textContent;
    link.addEventListener('click', function (event) { event.preventDefault(); field.focus(); });
    message.replaceChildren(link);
  });
  var errorSummary = document.querySelector('.flash-error');
  if (errorSummary) errorSummary.focus();

  // Retain submitter values in the request; aria-disabled avoids dropping named buttons.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (event.defaultPrevented || form.method.toLowerCase() !== 'post' || form.target === '_blank') return;
    if (form.dataset.submitting) { event.preventDefault(); return; }
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    var button = event.submitter;
    if (button && button.tagName === 'BUTTON') {
      button.dataset.originalLabel = button.innerHTML;
      button.textContent = 'Working...';
      button.setAttribute('aria-disabled', 'true');
    }
  });
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-submitting]').forEach(function (form) {
      delete form.dataset.submitting;
      form.removeAttribute('aria-busy');
      form.querySelectorAll('[data-original-label]').forEach(function (button) {
        button.innerHTML = button.dataset.originalLabel;
        delete button.dataset.originalLabel;
        button.removeAttribute('aria-disabled');
      });
    });
  });

  // Copy-to-clipboard for manual-entry secrets and other copy targets.
  document.querySelectorAll('[data-copy-target]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-copy-target'));
      if (!target) return;
      var text = target.textContent.replace(/\s+/g, '');
      var done = function () {
        var original = button.textContent;
        button.textContent = 'Copied';
        setTimeout(function () { button.textContent = original; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
      } else {
        fallbackCopy(text, done);
      }
    });
  });
  function fallbackCopy(text, done) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.left = '-9999px';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(area);
    done();
  }

  // Render otpauth provisioning URIs as scannable QR codes (via bundled qrcodegen).
  document.querySelectorAll('[data-otpauth]').forEach(function (container) {
    var uri = container.getAttribute('data-otpauth') || '';
    var canvas = container.querySelector('canvas');
    if (!canvas || !uri || typeof qrcodegen === 'undefined') return;
    var qr;
    try {
      qr = qrcodegen.QrCode.encodeText(uri, qrcodegen.QrCode.Ecc.MEDIUM);
    } catch (e) {
      container.remove();
      return;
    }
    var size = qr.size;
    var scale = Math.floor(canvas.width / (size + 8)) || 2;
    var quiet = 4 * scale;
    var ctx = canvas.getContext('2d');
    canvas.width = size * scale + quiet * 2;
    canvas.height = size * scale + quiet * 2;
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', 'Two-factor authentication QR code');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#000000';
    for (var y = 0; y < size; y++) {
      for (var x = 0; x < size; x++) {
        if (qr.getModule(x, y)) {
          ctx.fillRect(quiet + x * scale, quiet + y * scale, scale, scale);
        }
      }
    }
  });
})();

// In-app notifications bell (admin + customer).
(function () {
  var root = document.querySelector('[data-notif]');
  if (!root) { return; }
  var toggle = root.querySelector('[data-notif-toggle]');
  var panel = root.querySelector('[data-notif-panel]');
  var list = root.querySelector('[data-notif-list]');
  var empty = root.querySelector('[data-notif-empty]');
  var countEl = root.querySelector('[data-notif-count]');
  var readall = root.querySelector('[data-notif-readall]');
  var csrfEl = document.querySelector('meta[name="csrf-token"]');
  var token = csrfEl ? csrfEl.getAttribute('content') : '';
  function push(x) {
    try { return x.replace(' ', 'T') + 'Z'; } catch (e) { return x; }
  }
  function render(data) {
    list.innerHTML = '';
    var items = data.notifications || [];
    if (!items.length) {
      empty.hidden = false;
      readall.hidden = true;
    } else {
      empty.hidden = true;
      readall.hidden = false;
      items.forEach(function (n) {
        var li = document.createElement('li');
        li.className = 'notif-item' + (n.read ? ' is-read' : '');
        var a = document.createElement('a');
        a.href = n.url || '#';
        if (n.url) { a.addEventListener('click', function () { mark(n.id); }); }
        else { a.addEventListener('click', function (ev) { ev.preventDefault(); mark(n.id); }); }
        var t = document.createElement('div');
        t.className = 'notif-title';
        t.textContent = n.title || '';
        a.appendChild(t);
        if (n.body) {
          var b = document.createElement('div');
          b.className = 'notif-body';
          b.textContent = n.body;
          a.appendChild(b);
        }
        var m = document.createElement('div');
        m.className = 'notif-meta';
        m.textContent = n.created_at ? new Date(push(n.created_at)).toLocaleString() : '';
        a.appendChild(m);
        li.appendChild(a);
        list.appendChild(li);
      });
    }
    if (countEl) {
      if (data.unread_count > 0) { countEl.textContent = data.unread_count; countEl.hidden = false; }
      else { countEl.hidden = true; }
    }
  }
  function post(url) {
    return fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } });
  }
  function load() {
    fetch('/api/notifications', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d) { render(d); } })
      .catch(function () {});
  }
  function mark(id) { post('/api/notifications/' + id + '/read').then(load).catch(function () {}); }
  function markAll() { post('/api/notifications/read-all').then(load).catch(function () {}); }
  toggle.addEventListener('click', function () {
    var open = !panel.hidden;
    panel.hidden = open;
    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
    if (!open) { load(); }
  });
  readall.addEventListener('click', markAll);
  document.addEventListener('click', function (ev) { if (!root.contains(ev.target)) { panel.hidden = true; } });
})();
