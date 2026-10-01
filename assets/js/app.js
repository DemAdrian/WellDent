// WellDent+ — small progressive enhancements. Every page works without JS except dialogs.
(function () {
  'use strict';

  // Open a <dialog> from any [data-open="#id"] trigger, prefilling fields from data-fill (JSON).
  function openDialog(selector, fill) {
    var dlg = document.querySelector(selector);
    if (!dlg) return;
    if (fill) {
      Object.keys(fill).forEach(function (name) {
        var el = dlg.querySelector('[name="' + name + '"]');
        if (el) el.value = fill[name] == null ? '' : fill[name];
        var text = dlg.querySelector('[data-text="' + name + '"]');
        if (text) text.textContent = fill[name];
      });
    }
    dlg.dispatchEvent(new CustomEvent('dialog:open', { detail: fill || {} }));
    dlg.showModal();
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-open]');
    if (trigger) {
      e.preventDefault();
      openDialog(trigger.getAttribute('data-open'), trigger.dataset.fill ? JSON.parse(trigger.dataset.fill) : null);
      return;
    }
    if (e.target.closest('dialog .close')) {
      e.target.closest('dialog').close();
    }
  });

  // Confirm destructive actions.
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Auto-submit selects such as the inline appointment status picker.
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-autosubmit]')) e.target.form.submit();
  });

  // ?open=dialogId opens that dialog on load (used by dashboard quick actions).
  var auto = new URLSearchParams(location.search).get('open');
  if (auto && /^[\w-]+$/.test(auto)) openDialog('#' + auto);

  // ---------- theme (System → Dark → Light), stored per device ----------
  var THEME_KEY = 'welldent-theme';
  var systemDark = window.matchMedia('(prefers-color-scheme: dark)');
  function savedTheme() {
    try { return localStorage.getItem(THEME_KEY) || 'system'; } catch (err) { return 'system'; }
  }
  function applyTheme(pref) {
    var dark = pref === 'dark' || (pref === 'system' && systemDark.matches);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    var label = document.querySelector('[data-theme-label]');
    if (label) label.textContent = pref.charAt(0).toUpperCase() + pref.slice(1);
  }
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var themeFadeTimer;
  function switchTheme(pref) {
    // Briefly enable colour transitions everywhere so the switch cross-fades instead of flashing.
    var root = document.documentElement;
    root.classList.add('theme-switching');
    applyTheme(pref);
    clearTimeout(themeFadeTimer);
    themeFadeTimer = setTimeout(function () { root.classList.remove('theme-switching'); }, 350);
  }
  var themePref = savedTheme();
  applyTheme(themePref);
  systemDark.addEventListener('change', function () { switchTheme(themePref); });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-theme-toggle]')) return;
    themePref = { system: 'dark', dark: 'light', light: 'system' }[themePref] || 'system';
    try { localStorage.setItem(THEME_KEY, themePref); } catch (err) { /* private mode: applies to this page only */ }
    switchTheme(themePref);
  });

  // ---------- collapsible sidebar (icons only), stored per device ----------
  var sideToggle = document.querySelector('[data-sidebar-toggle]');
  function syncSideToggle() {
    if (!sideToggle) return;
    var collapsed = document.documentElement.getAttribute('data-sidebar') === 'collapsed';
    var label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    sideToggle.setAttribute('aria-label', label);
    sideToggle.setAttribute('title', label);
    sideToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  }
  syncSideToggle();
  if (sideToggle) {
    // Choreographed so nothing snaps: collapsing fades the labels out, then narrows the sidebar;
    // expanding widens it first, then fades the labels back in. Timings match the CSS (.15s / .28s).
    var FADE_MS = 150, RESIZE_MS = 280, sideTimers = [];
    sideToggle.addEventListener('click', function () {
      var root = document.documentElement;
      var collapse = root.getAttribute('data-sidebar') !== 'collapsed';
      var setCollapsed = function () {
        if (collapse) root.setAttribute('data-sidebar', 'collapsed'); else root.removeAttribute('data-sidebar');
        syncSideToggle();
      };
      try { localStorage.setItem('welldent-sidebar', collapse ? 'collapsed' : 'expanded'); } catch (err) { /* this page only */ }
      sideTimers.forEach(clearTimeout);
      sideTimers = [];
      if (reduceMotion.matches) {
        root.removeAttribute('data-sidebar-fade');
        setCollapsed();
        return;
      }
      root.setAttribute('data-sidebar-fade', '');
      var unfade = function () { root.removeAttribute('data-sidebar-fade'); };
      if (collapse) {
        sideTimers.push(setTimeout(setCollapsed, FADE_MS));
        sideTimers.push(setTimeout(unfade, FADE_MS + RESIZE_MS));
      } else {
        setCollapsed();
        sideTimers.push(setTimeout(unfade, RESIZE_MS));
      }
    });
  }

  // ---------- dental chart ----------
  var chart = document.querySelector('[data-dental-chart]');
  if (chart) {
    var history = JSON.parse(document.getElementById('tooth-history').textContent || '{}');
    var dlg = document.getElementById('tooth-dialog');
    var labels = JSON.parse(chart.getAttribute('data-labels'));

    function openTooth(g) {
      var no = g.getAttribute('data-tooth');
      var logs = history[no] || [];
      dlg.querySelector('[name="tooth_no"]').value = no;
      dlg.querySelector('[data-text="tooth_title"]').textContent = 'Tooth ' + no + ' · ' + g.getAttribute('data-name');
      dlg.querySelector('[name="status"]').value = g.getAttribute('data-status');
      dlg.querySelector('[name="procedure_name"]').value = '';
      dlg.querySelector('[name="notes"]').value = '';
      var list = dlg.querySelector('.history');
      list.innerHTML = '';
      if (!logs.length) {
        list.innerHTML = '<li>No entries yet — tooth is charted as healthy.</li>';
      }
      logs.forEach(function (l) {
        var li = document.createElement('li');
        li.textContent = l.at + ' · ' + (labels[l.status] || l.status) +
          (l.procedure ? ' · ' + l.procedure : '') + (l.notes ? ' — ' + l.notes : '') + (l.by ? ' (' + l.by + ')' : '');
        list.appendChild(li);
      });
      dlg.showModal();
    }

    chart.addEventListener('click', function (e) {
      var g = e.target.closest('.tooth');
      if (g) openTooth(g);
    });
    chart.addEventListener('keydown', function (e) {
      var g = e.target.closest('.tooth');
      if (g && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openTooth(g); }
    });
  }

  // ---------- paperless checklist on the patient form ----------
  var form = document.querySelector('[data-patient-form]');
  if (form) {
    var filled = function (names) {
      return names.some(function (n) { var el = form.elements[n]; return el && el.value.trim() !== ''; });
    };
    var all = function (names) {
      return names.every(function (n) { var el = form.elements[n]; return el && el.value.trim() !== ''; });
    };
    var update = function () {
      var state = {
        demographics: all(['full_name', 'birth_date', 'sex', 'phone']),
        medical: filled(['allergies', 'conditions', 'medications']),
        dental: filled(['dental_history']),
        consent: form.elements.consent_signed.checked
      };
      Object.keys(state).forEach(function (k) {
        var li = document.querySelector('[data-check="' + k + '"]');
        if (li) li.classList.toggle('done', state[k]);
      });
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
  }
})();
