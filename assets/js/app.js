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

  // Picking a procedure from the price list fills in its standard fee. The amount stays editable:
  // it is only replaced while empty or still holding the fee filled in last time.
  function applyFee(proc) {
    var form = proc.form;
    var amount = form && form.elements[proc.getAttribute('data-fee-field')];
    if (!amount) return;
    var name = proc.value.trim().toLowerCase();
    var match = null;
    Array.prototype.some.call(proc.list ? proc.list.options : [], function (o) {
      if (o.hasAttribute('data-fee') && o.value.toLowerCase() === name) { match = o; return true; }
      return false;
    });
    var hint = form.querySelector('[data-fee-hint]');
    if (hint) {
      hint.hidden = !match;
      hint.textContent = match ? 'Standard fee for ' + match.value + ': ' + match.label + '. You can change the amount.' : '';
    }
    if (match && (amount.value === '' || amount.value === amount.dataset.prefilled)) {
      amount.value = match.getAttribute('data-fee');
      amount.dataset.prefilled = amount.value;
    }
  }
  function resetFee(form) {
    form.querySelectorAll('[data-fee-field]').forEach(function (proc) {
      var amount = form.elements[proc.getAttribute('data-fee-field')];
      if (amount) { amount.value = ''; delete amount.dataset.prefilled; }
    });
    var hint = form.querySelector('[data-fee-hint]');
    if (hint) hint.hidden = true;
  }
  document.addEventListener('input', function (e) {
    if (e.target.matches('[data-fee-field]')) applyFee(e.target);
  });

  // Auto-submit selects such as the inline appointment status picker.
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-autosubmit]')) e.target.form.submit();
  });

  // One searchable patient field: a type-ahead input replaces the <select>, which stays as the
  // submitted value (and as the fallback without JavaScript).
  document.querySelectorAll('[data-patient-combo]').forEach(function (wrap) {
    var select = document.getElementById(wrap.dataset.patientCombo);
    var input = wrap.querySelector('input');
    var list = wrap.querySelector('datalist');
    if (!select || !input || !list) return;
    var idByText = {};
    Array.from(select.options).forEach(function (o) {
      if (!o.value) return;
      var text = o.textContent.trim() + (o.dataset.record ? ' · ' + o.dataset.record : '');
      idByText[text] = o.value;
      var suggestion = document.createElement('option');
      suggestion.value = text;
      list.appendChild(suggestion);
      if (o.selected) input.value = text;
    });
    function sync() {
      var text = input.value.trim();
      var id = idByText[text] || '';
      select.value = id;
      input.setCustomValidity(text === '' ? 'Choose a patient.' : (id ? '' : 'Pick a patient from the suggestions.'));
    }
    input.addEventListener('input', sync);
    sync();
    var label = document.querySelector('label[for="' + select.id + '"]');
    if (label) label.htmlFor = input.id;
    select.required = false;
    select.hidden = true;
    wrap.hidden = false;
  });

  // ---------- styled dropdowns ----------
  // Each <select> gets a designed button and option list. The real select stays underneath as the
  // value that is submitted and validated, so forms behave exactly as before. Touch screens keep
  // the phone's own picker, which is easier to use there.
  var nativeValue = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
  var nativeIndex = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
  var openDropdown = null;
  var dropdownCount = 0;

  function enhanceSelect(select) {
    if (select.multiple || select.hidden || select.dataset.enhanced) return;
    select.dataset.enhanced = '1';
    var uid = 'dd' + (++dropdownCount);
    var wrap = document.createElement('div');
    wrap.className = 'dd';
    if (select.closest('.appt .actions')) wrap.classList.add('dd-sm');
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('dd-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'dd-button';
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', uid + '-list');
    var labelText = document.createElement('span');
    labelText.className = 'dd-value';
    button.appendChild(labelText);
    wrap.appendChild(button);
    // Labels now point at the button, so clicking a label opens the list.
    if (select.id) {
      document.querySelectorAll('label[for="' + select.id + '"]').forEach(function (l) {
        button.id = uid + '-btn';
        l.htmlFor = button.id;
      });
    }
    if (!button.id && select.getAttribute('aria-label')) button.setAttribute('aria-label', select.getAttribute('aria-label'));

    var list = document.createElement('ul');
    list.className = 'dd-list';
    list.id = uid + '-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    wrap.appendChild(list);

    var active = -1;
    var typed = '';
    var typedTimer;

    function render() {
      var opt = select.options[nativeIndex.get.call(select)];
      labelText.textContent = opt ? opt.textContent : '';
      wrap.classList.toggle('dd-placeholder', !opt || opt.value === '');
      button.disabled = select.disabled;
      wrap.dataset.value = opt ? opt.value : '';
    }
    // Keep the button in step when other scripts set the value (dialogs, the dental chart).
    Object.defineProperty(select, 'value', {
      configurable: true,
      get: function () { return nativeValue.get.call(this); },
      set: function (v) { nativeValue.set.call(this, v); render(); }
    });
    Object.defineProperty(select, 'selectedIndex', {
      configurable: true,
      get: function () { return nativeIndex.get.call(this); },
      set: function (v) { nativeIndex.set.call(this, v); render(); }
    });
    select.addEventListener('change', render);
    if (select.form) select.form.addEventListener('reset', function () { setTimeout(render); });

    function buildList() {
      list.innerHTML = '';
      Array.prototype.forEach.call(select.options, function (o, i) {
        if (o.hidden) return;
        var li = document.createElement('li');
        li.className = 'dd-option';
        li.id = uid + '-o' + i;
        li.dataset.index = i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', o.selected ? 'true' : 'false');
        if (o.disabled) li.setAttribute('aria-disabled', 'true');
        if (o.value === '') li.classList.add('dd-option-empty');
        li.textContent = o.textContent;
        list.appendChild(li);
      });
    }
    function items() { return Array.prototype.slice.call(list.querySelectorAll('.dd-option:not([aria-disabled])')); }
    function setActive(li) {
      list.querySelectorAll('.dd-active').forEach(function (x) { x.classList.remove('dd-active'); });
      if (!li) { button.removeAttribute('aria-activedescendant'); active = -1; return; }
      li.classList.add('dd-active');
      active = +li.dataset.index;
      button.setAttribute('aria-activedescendant', li.id);
      var top = li.offsetTop, bottom = top + li.offsetHeight;
      if (top < list.scrollTop) list.scrollTop = top - 6;
      else if (bottom > list.scrollTop + list.clientHeight) list.scrollTop = bottom - list.clientHeight + 6;
    }
    function place() {
      // Fixed position so cards, tables and dialogs never clip the list; opens upward near the bottom.
      var r = button.getBoundingClientRect();
      var below = window.innerHeight - r.bottom - 12, above = r.top - 12;
      var up = below < 220 && above > below;
      list.style.left = r.left + 'px';
      list.style.minWidth = r.width + 'px';
      list.style.maxWidth = Math.max(r.width, Math.min(360, window.innerWidth - r.left - 12)) + 'px';
      list.style.maxHeight = Math.max(140, Math.min(300, up ? above : below)) + 'px';
      list.style.top = up ? '' : (r.bottom + 6) + 'px';
      list.style.bottom = up ? (window.innerHeight - r.top + 6) + 'px' : '';
      list.classList.toggle('dd-up', up);
    }
    function open() {
      if (button.disabled || !list.hidden) return;
      if (openDropdown) openDropdown.close();
      buildList();
      list.hidden = false;
      wrap.classList.add('dd-open');
      button.setAttribute('aria-expanded', 'true');
      place();
      setActive(list.querySelector('[aria-selected="true"]:not([aria-disabled])') || items()[0]);
      openDropdown = { close: close, wrap: wrap };
    }
    function close() {
      if (list.hidden) return;
      list.hidden = true;
      wrap.classList.remove('dd-open');
      button.setAttribute('aria-expanded', 'false');
      setActive(null);
      if (openDropdown && openDropdown.wrap === wrap) openDropdown = null;
    }
    function choose(index) {
      var o = select.options[index];
      if (!o || o.disabled) return;
      close();
      button.focus();
      if (nativeIndex.get.call(select) === index) return;
      nativeIndex.set.call(select, index);
      render();
      select.dispatchEvent(new Event('input', { bubbles: true }));
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function move(step) {
      var all = items();
      if (!all.length) return;
      var pos = all.findIndex(function (li) { return +li.dataset.index === active; });
      setActive(all[Math.max(0, Math.min(all.length - 1, pos + step))]);
    }

    button.addEventListener('click', function () { list.hidden ? open() : close(); });
    list.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep focus on the button
    list.addEventListener('click', function (e) {
      var li = e.target.closest('.dd-option');
      if (li && !li.hasAttribute('aria-disabled')) choose(+li.dataset.index);
    });
    list.addEventListener('mousemove', function (e) {
      var li = e.target.closest('.dd-option:not([aria-disabled])');
      if (li && +li.dataset.index !== active) setActive(li);
    });
    button.addEventListener('keydown', function (e) {
      var isOpen = !list.hidden;
      switch (e.key) {
        case 'ArrowDown': case 'ArrowUp':
          e.preventDefault();
          if (!isOpen) open(); else move(e.key === 'ArrowDown' ? 1 : -1);
          return;
        case 'Home': case 'End':
          if (!isOpen) return;
          e.preventDefault();
          var all = items();
          setActive(e.key === 'Home' ? all[0] : all[all.length - 1]);
          return;
        case 'Enter': case ' ':
          if (e.key === ' ' && typed) break; // a space inside type-ahead text
          e.preventDefault();
          if (isOpen && active >= 0) choose(active); else open();
          return;
        case 'Escape':
          if (isOpen) { e.preventDefault(); e.stopPropagation(); close(); } // don't close a surrounding dialog
          return;
        case 'Tab':
          close();
          return;
      }
      if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        // Type-ahead: jump to the first option starting with what was typed.
        clearTimeout(typedTimer);
        typed += e.key.toLowerCase();
        typedTimer = setTimeout(function () { typed = ''; }, 700);
        var match = Array.prototype.find.call(select.options, function (o) {
          return !o.disabled && !o.hidden && o.textContent.trim().toLowerCase().indexOf(typed) === 0;
        });
        if (!match) return;
        if (isOpen) setActive(document.getElementById(uid + '-o' + match.index));
        else choose(match.index);
      }
    });
    // A failed "required" check on the hidden select highlights and focuses the button instead.
    select.addEventListener('invalid', function () {
      wrap.classList.add('dd-invalid');
      button.focus();
    });
    select.addEventListener('change', function () { wrap.classList.remove('dd-invalid'); });
    render();
  }

  // ---------- styled calendar for date fields ----------
  // The date field stays a normal <input type="date"> (typing, validation and submitted value
  // are unchanged); a calendar button opens a designed month view in place of the browser's.
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']; // the clinic's weeks start on Monday
  var CAL_ICON = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>';
  var pad2 = function (n) { return (n < 10 ? '0' : '') + n; };
  var isoDate = function (d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); };
  var parseDate = function (s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  };
  var addDays = function (d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); };
  var addMonths = function (d, n) {
    var last = new Date(d.getFullYear(), d.getMonth() + n + 1, 0).getDate(); // keep Jan 31 → Feb 28, not Mar 3
    return new Date(d.getFullYear(), d.getMonth() + n, Math.min(d.getDate(), last));
  };

  function enhanceDate(input) {
    if (input.dataset.enhanced || input.readOnly) return;
    input.dataset.enhanced = '1';
    var uid = 'dp' + (++dropdownCount);
    var wrap = document.createElement('span');
    wrap.className = 'dp';
    if (input.style.width === 'auto') wrap.classList.add('dp-inline');
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'dp-toggle';
    toggle.innerHTML = CAL_ICON;
    toggle.setAttribute('aria-label', 'Choose date');
    toggle.setAttribute('aria-haspopup', 'dialog');
    toggle.setAttribute('aria-expanded', 'false');
    wrap.appendChild(toggle);

    var pop = document.createElement('div');
    pop.className = 'dp-pop';
    pop.id = uid + '-cal';
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', 'Calendar');
    pop.hidden = true;
    wrap.appendChild(pop);
    toggle.setAttribute('aria-controls', pop.id);

    var view;   // first day of the month (or year, in month view) on screen
    var focus;  // the day keyboard navigation is on
    var mode = 'days';

    function bounds() { return { min: parseDate(input.min), max: parseDate(input.max) }; }
    function allowed(d) {
      var b = bounds();
      return !(b.min && d < b.min) && !(b.max && d > b.max);
    }
    function clamp(d) {
      var b = bounds();
      if (b.min && d < b.min) return b.min;
      if (b.max && d > b.max) return b.max;
      return d;
    }

    function render(focusGrid) {
      var selected = isoDate(parseDate(input.value) || new Date(0, 0, 1));
      var today = isoDate(new Date());
      var html = '';
      if (mode === 'days') {
        html += '<div class="dp-head">'
          + '<button type="button" class="dp-nav" data-step="-1" aria-label="Previous month">‹</button>'
          + '<button type="button" class="dp-title" aria-label="Choose month and year">' + MONTHS[view.getMonth()] + ' ' + view.getFullYear() + '</button>'
          + '<button type="button" class="dp-nav" data-step="1" aria-label="Next month">›</button></div>';
        html += '<div class="dp-grid" role="grid"><div class="dp-row dp-weekdays" role="row">';
        WEEKDAYS.forEach(function (w, i) { html += '<span role="columnheader" class="' + (i > 4 ? 'dp-weekend' : '') + '">' + w + '</span>'; });
        html += '</div>';
        var start = addDays(view, -((view.getDay() + 6) % 7)); // back to Monday
        for (var r = 0; r < 6; r++) {
          html += '<div class="dp-row" role="row">';
          for (var c = 0; c < 7; c++) {
            var d = addDays(start, r * 7 + c), iso = isoDate(d);
            var cls = ['dp-day'];
            if (d.getMonth() !== view.getMonth()) cls.push('is-other');
            if (iso === today) cls.push('is-today');
            if (iso === selected) cls.push('is-selected');
            if (c > 4) cls.push('dp-weekend');
            var ok = allowed(d);
            html += '<button type="button" role="gridcell" class="' + cls.join(' ') + '" data-date="' + iso + '"'
              + ' tabindex="' + (iso === isoDate(focus) ? '0' : '-1') + '"'
              + (iso === selected ? ' aria-selected="true"' : '')
              + (ok ? '' : ' disabled')
              + ' aria-label="' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear() + '">' + d.getDate() + '</button>';
          }
          html += '</div>';
        }
        html += '</div>';
      } else {
        html += '<div class="dp-head">'
          + '<button type="button" class="dp-nav" data-step="-12" aria-label="Previous year">‹</button>'
          + '<button type="button" class="dp-title" aria-label="Back to days">' + view.getFullYear() + '</button>'
          + '<button type="button" class="dp-nav" data-step="12" aria-label="Next year">›</button></div><div class="dp-months">';
        var sel = parseDate(input.value);
        MONTHS.forEach(function (name, i) {
          var first = new Date(view.getFullYear(), i, 1), last = new Date(view.getFullYear(), i + 1, 0);
          var b = bounds();
          var ok = !(b.min && last < b.min) && !(b.max && first > b.max);
          var cls = 'dp-month' + (sel && sel.getFullYear() === view.getFullYear() && sel.getMonth() === i ? ' is-selected' : '')
            + (focus.getFullYear() === view.getFullYear() && focus.getMonth() === i ? ' is-focus' : '');
          html += '<button type="button" class="' + cls + '" data-month="' + i + '"' + (ok ? '' : ' disabled') + '>' + name.slice(0, 3) + '</button>';
        });
        html += '</div>';
      }
      var todayOk = allowed(new Date(new Date().setHours(0, 0, 0, 0)));
      html += '<div class="dp-foot">'
        + (input.required ? '<span></span>' : '<button type="button" class="dp-link" data-action="clear">Clear</button>')
        + '<button type="button" class="dp-link dp-today" data-action="today"' + (todayOk ? '' : ' disabled') + '>Today</button></div>';
      pop.innerHTML = html;
      if (focusGrid) {
        var target = pop.querySelector(mode === 'days' ? '.dp-day[tabindex="0"]' : '.dp-month.is-focus') || pop.querySelector('.dp-day:not([disabled]), .dp-month:not([disabled])');
        if (target) target.focus({ preventScroll: true }); // scrolling would close the calendar
      }
    }

    function place() {
      var r = input.getBoundingClientRect();
      var w = 300, h = pop.offsetHeight || 360;
      var below = window.innerHeight - r.bottom - 12, above = r.top - 12;
      var up = below < h && above > below;
      pop.style.left = Math.max(12, Math.min(r.left, window.innerWidth - w - 12)) + 'px';
      pop.style.top = up ? '' : (r.bottom + 6) + 'px';
      pop.style.bottom = up ? (window.innerHeight - r.top + 6) + 'px' : '';
      pop.classList.toggle('dp-up', up);
    }
    function open(focusGrid) {
      if (!pop.hidden || input.disabled) return;
      if (openDropdown) openDropdown.close();
      focus = clamp(parseDate(input.value) || new Date(new Date().setHours(0, 0, 0, 0)));
      view = new Date(focus.getFullYear(), focus.getMonth(), 1);
      mode = 'days';
      pop.hidden = false;
      wrap.classList.add('dp-open');
      toggle.setAttribute('aria-expanded', 'true');
      render(focusGrid);
      place();
      openDropdown = { close: close, wrap: wrap };
    }
    function close(refocus) {
      if (pop.hidden) return;
      pop.hidden = true;
      wrap.classList.remove('dp-open');
      toggle.setAttribute('aria-expanded', 'false');
      if (openDropdown && openDropdown.wrap === wrap) openDropdown = null;
      if (refocus) input.focus();
    }
    function pick(iso) {
      close(true);
      if (input.value === iso) return;
      input.value = iso;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function moveFocus(d) {
      focus = clamp(d);
      if (focus.getMonth() !== view.getMonth() || focus.getFullYear() !== view.getFullYear()) {
        view = new Date(focus.getFullYear(), focus.getMonth(), 1);
      }
      render(true);
    }

    toggle.addEventListener('click', function () { pop.hidden ? open(true) : close(true); });
    input.addEventListener('click', function () { open(false); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' && e.altKey) {
        e.preventDefault();
        open(true);
      } else if (e.key === 'Escape' && !pop.hidden) {
        e.preventDefault(); e.stopPropagation();
        close(true);
      }
    });
    // Typing a date while the calendar is open moves the calendar along with it.
    input.addEventListener('input', function () {
      var d = parseDate(input.value);
      if (pop.hidden || !d) return;
      focus = d;
      view = new Date(d.getFullYear(), d.getMonth(), 1);
      mode = 'days';
      render(false);
    });
    pop.addEventListener('mousedown', function (e) {
      if (e.target.closest('button')) e.preventDefault(); // keep focus where it is; clicks still fire
    });
    pop.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b || b.disabled) return;
      if (b.dataset.date) { pick(b.dataset.date); return; }
      if (b.dataset.step) {
        var step = +b.dataset.step;
        view = mode === 'days' ? new Date(view.getFullYear(), view.getMonth() + step, 1) : new Date(view.getFullYear() + step / 12, 0, 1);
        focus = clamp(mode === 'days' ? addMonths(focus, step) : addMonths(focus, step));
        render(false);
        return;
      }
      if (b.classList.contains('dp-title')) {
        mode = mode === 'days' ? 'months' : 'days';
        view = mode === 'days' ? new Date(focus.getFullYear(), focus.getMonth(), 1) : new Date(view.getFullYear(), 0, 1);
        render(true);
        return;
      }
      if (b.dataset.month) {
        var m = +b.dataset.month;
        focus = clamp(new Date(view.getFullYear(), m, Math.min(focus.getDate(), new Date(view.getFullYear(), m + 1, 0).getDate())));
        view = new Date(view.getFullYear(), m, 1);
        mode = 'days';
        render(true);
        return;
      }
      if (b.dataset.action === 'today') pick(isoDate(new Date()));
      if (b.dataset.action === 'clear') pick('');
    });
    pop.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); return; }
      if (mode !== 'days' || !e.target.classList.contains('dp-day')) return;
      var moves = {
        ArrowLeft: function () { return addDays(focus, -1); },
        ArrowRight: function () { return addDays(focus, 1); },
        ArrowUp: function () { return addDays(focus, -7); },
        ArrowDown: function () { return addDays(focus, 7); },
        Home: function () { return addDays(focus, -((focus.getDay() + 6) % 7)); },
        End: function () { return addDays(focus, 6 - (focus.getDay() + 6) % 7); },
        PageUp: function () { return addMonths(focus, e.shiftKey ? -12 : -1); },
        PageDown: function () { return addMonths(focus, e.shiftKey ? 12 : 1); }
      };
      if (moves[e.key]) { e.preventDefault(); moveFocus(moves[e.key]()); }
    });
    // Leaving the calendar with Tab closes it.
    wrap.addEventListener('focusout', function (e) {
      if (!pop.hidden && e.relatedTarget && !wrap.contains(e.relatedTarget)) close(false);
    });
  }

  if (!window.matchMedia('(pointer: coarse)').matches) {
    document.querySelectorAll('input[type="date"]').forEach(enhanceDate);
    document.querySelectorAll('select').forEach(enhanceSelect);
    document.addEventListener('mousedown', function (e) {
      if (openDropdown && !openDropdown.wrap.contains(e.target)) openDropdown.close();
    });
    window.addEventListener('resize', function () { if (openDropdown) openDropdown.close(); });
    document.addEventListener('scroll', function (e) {
      if (openDropdown && !(e.target.closest && e.target.closest('.dd-list'))) openDropdown.close();
    }, true);
    document.addEventListener('close', function () { if (openDropdown) openDropdown.close(); }, true); // a dialog closed
  }

  // Collapsed sections marked data-print-open still print in full.
  window.addEventListener('beforeprint', function () {
    document.querySelectorAll('details[data-print-open]').forEach(function (d) { d.open = true; });
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
      resetFee(dlg.querySelector('form')); // otherwise the last tooth's charge carries over
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
