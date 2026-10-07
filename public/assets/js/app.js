/* Booking Organizer — client-side conveniences only. The server validates and computes everything. */
(function () {
  'use strict';

  // <form data-confirm="Are you sure?">: ask before submitting.
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
    }
  });

  // Capitalize the first letter of free-text fields as the user types.
  // Skipped for email/password/url/etc.; opt out per field with data-no-caps.
  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || el.hasAttribute('data-no-caps')) { return; }
    var isText = el.tagName === 'TEXTAREA' ||
      (el.tagName === 'INPUT' && (el.type === 'text' || el.type === ''));
    if (!isText || el.readOnly || el.disabled) { return; }
    if (/email|e-mail|user(name)?|login|pass|url|website|code|token/i.test(el.name || '')) { return; }
    if (el.autocomplete === 'email' || el.autocomplete === 'username') { return; }
    var v = el.value;
    var first = v.search(/\S/);
    if (first < 0) { return; }
    var ch = v.charAt(first);
    var up = ch.toUpperCase();
    if (ch === up || ch.toLowerCase() === ch.toUpperCase()) { return; }
    var s = el.selectionStart, end = el.selectionEnd;
    el.value = v.slice(0, first) + up + v.slice(first + 1);
    try { el.setSelectionRange(s, end); } catch (err) { /* unsupported type */ }
  });

  // ---- Alert dialog --------------------------------------------------------------------------
  // A warning the user must not scroll past — a venue already taken on that date — is shown as a
  // banner AND raised in a modal dialog, so it cannot be missed. The banner stays on the page once
  // the dialog is dismissed, and is what a browser with JavaScript off still shows on its own.
  function openAlertDialog(title, messages) {
    var lastFocus = document.activeElement;

    var overlay = document.createElement('div');
    overlay.className = 'dialog-overlay';

    var dialog = document.createElement('div');
    dialog.className = 'dialog';
    dialog.setAttribute('role', 'alertdialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'dialog-title');

    var head = document.createElement('div');
    head.className = 'dialog-head';
    var icon = document.createElement('span');
    icon.className = 'dialog-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '!';
    var heading = document.createElement('h2');
    heading.className = 'dialog-title';
    heading.id = 'dialog-title';
    heading.textContent = title;
    head.appendChild(icon);
    head.appendChild(heading);

    var body = document.createElement('div');
    body.className = 'dialog-body';
    messages.forEach(function (text) {
      var p = document.createElement('p');
      p.textContent = text;          // textContent, never innerHTML: venue names are user-entered
      body.appendChild(p);
    });

    var foot = document.createElement('div');
    foot.className = 'dialog-foot';
    var ok = document.createElement('button');
    ok.type = 'button';
    ok.className = 'btn primary';
    ok.textContent = 'Got it';
    foot.appendChild(ok);

    dialog.appendChild(head);
    dialog.appendChild(body);
    dialog.appendChild(foot);
    overlay.appendChild(dialog);

    function close() {
      document.removeEventListener('keydown', onKey, true);
      overlay.remove();
      document.body.classList.remove('dialog-open');
      if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }
    function onKey(e) {
      if (e.key === 'Escape') { close(); }
      // One focusable control, so keep Tab inside the dialog.
      if (e.key === 'Tab') { e.preventDefault(); ok.focus(); }
    }

    ok.addEventListener('click', close);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { close(); } });
    document.addEventListener('keydown', onKey, true);

    document.body.appendChild(overlay);
    document.body.classList.add('dialog-open');
    ok.focus();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var urgent = document.querySelectorAll('.flash.js-alert');
    if (!urgent.length) {
      return;
    }
    var messages = Array.prototype.map.call(urgent, function (el) {
      return (el.textContent || '').replace(/\s+/g, ' ').trim();
    });
    var title = urgent[0].getAttribute('data-alert-title') || 'Please check this';
    openAlertDialog(title, messages);
  });

  // Print button on the document pages.
  document.addEventListener("DOMContentLoaded", function () {
    var printBtn = document.getElementById("print-btn");
    if (printBtn) {
      printBtn.addEventListener("click", function () { window.print(); });
    }
  });

  // ---- Vendor service picker (Vendors pages) ----------------------------------------------------
  // <div class="service-picker" data-follows="select-id"> holds one .service-group per category.
  // Only the group of the chosen category is shown; the others are disabled so they don't post.
  // Services the vendor already has (data-owned) stay ticked and locked.
  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.service-picker'), function (picker) {
      var select = picker.hasAttribute('data-follows') ? document.getElementById(picker.getAttribute('data-follows')) : null;
      var empty = picker.querySelector('.service-picker-empty');

      function show() {
        var chosen = select.value;
        Array.prototype.forEach.call(picker.querySelectorAll('.service-group'), function (group) {
          var on = group.getAttribute('data-category') === chosen;
          group.hidden = !on;
          Array.prototype.forEach.call(group.querySelectorAll('input, select'), function (el) {
            el.disabled = !on || el.hasAttribute('data-owned');
          });
        });
        if (empty) { empty.hidden = chosen !== ''; }
      }
      if (select) {
        select.addEventListener('change', show);
        show();
      }

      picker.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-tick]');
        if (!btn) { return; }
        var on = btn.getAttribute('data-tick') === 'all';
        var group = btn.closest('.service-group');
        Array.prototype.forEach.call(group.querySelectorAll('input[type=checkbox]:not([data-owned])'), function (box) {
          box.checked = on;
        });
      });
    });
  });

  // ---- Chart tooltips (Dashboard) ---------------------------------------------------------------
  // Each column group on a chart has one full-height target carrying its figures in data-tip. The
  // tooltip is a convenience: the same figures are in the target's label and in the table under the
  // chart, so nothing here is the only way to read a value.
  document.addEventListener('DOMContentLoaded', function () {
    var targets = document.querySelectorAll('.chart-hit[data-tip]');
    if (!targets.length) {
      return;
    }
    var tip = document.createElement('div');
    tip.className = 'chart-tip';
    tip.setAttribute('role', 'status');
    tip.hidden = true;
    document.body.appendChild(tip);

    function fill(target) {
      var data;
      try { data = JSON.parse(target.getAttribute('data-tip')); } catch (e) { return false; }
      tip.textContent = '';
      var title = document.createElement('p');
      title.className = 'tip-title';
      title.textContent = data.title;
      tip.appendChild(title);
      (data.rows || []).forEach(function (row) {
        var line = document.createElement('div');
        line.className = 'tip-row';
        var key = document.createElement('span');
        key.className = 'tip-key' + (row[0] ? ' ' + row[0] : '');
        var name = document.createElement('span');
        name.className = 'tip-name';
        name.textContent = row[1];       // textContent throughout: labels are data, never markup
        var value = document.createElement('span');
        value.className = 'tip-value';
        value.textContent = row[2];
        line.appendChild(key);
        line.appendChild(name);
        line.appendChild(value);
        tip.appendChild(line);
      });
      return true;
    }
    // Beside the pointer (or the column, for keyboard focus), flipped when it would leave the window.
    function place(x, y) {
      var gap = 14;
      var w = tip.offsetWidth, h = tip.offsetHeight;
      var left = x + gap + w > window.innerWidth ? x - gap - w : x + gap;
      var top = y + gap + h > window.innerHeight ? y - gap - h : y + gap;
      tip.style.left = Math.max(4, left) + 'px';
      tip.style.top = Math.max(4, top) + 'px';
    }
    function hide() { tip.hidden = true; }

    Array.prototype.forEach.call(targets, function (target) {
      // The native <title> tooltip is the no-script fallback; with this one running it would double up.
      var title = target.querySelector('title');
      if (title) { title.remove(); }

      function show(x, y) {
        if (!fill(target)) { return; }
        tip.hidden = false;
        place(x, y);
      }
      target.addEventListener('pointerenter', function (e) { show(e.clientX, e.clientY); });
      target.addEventListener('pointermove', function (e) {
        if (tip.hidden) { show(e.clientX, e.clientY); } else { place(e.clientX, e.clientY); }
      });
      target.addEventListener('pointerleave', hide);
      target.addEventListener('focus', function () {
        var box = target.getBoundingClientRect();
        show(box.right - 10, box.top + 8);
      });
      target.addEventListener('blur', hide);
    });
    window.addEventListener('scroll', hide, true);
  });

  // ---- Money parsing/formatting (display only; mirrors app/money.php) -------------------
  function toPaisa(text) {
    var s = String(text || '').trim().replace(/^rs\.?\s*/i, '').replace(/[,\s]/g, '');
    var m = /^(\d+)(?:\.(\d{1,2}))?$/.exec(s);
    if (!m) { return 0; }
    return parseInt(m[1], 10) * 100 + parseInt((m[2] || '0').padEnd(2, '0'), 10);
  }
  function toInt(text) {
    var s = String(text || '').replace(/,/g, '').trim();
    return /^\d+$/.test(s) ? parseInt(s, 10) : 0;
  }
  function groupSouthAsian(digits) {
    if (digits.length <= 3) { return digits; }
    var last3 = digits.slice(-3);
    var rest = digits.slice(0, -3).replace(/\B(?=(\d{2})+$)/g, ',');
    return rest + ',' + last3;
  }
  function formatRs(paisa) {
    var sign = paisa < 0 ? '-' : '';
    var abs = Math.abs(paisa);
    var out = 'Rs. ' + sign + groupSouthAsian(String(Math.floor(abs / 100)));
    if (abs % 100) { out += '.' + String(abs % 100).padStart(2, '0'); }
    return out;
  }

  // ---- Vendor service picker (booking page, admin) ----------------------------------------------
  // Shows the unit and the price-list rate of the chosen service, starts per-person services at the
  // guest count, and shows qty × rate. Display only: the server computes and stores the amount.
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('vendor-line-form');
    if (!form) {
      return;
    }
    var service = form.querySelector('#vl_service');
    var unit = form.querySelector('#vl_unit');
    var qty = form.querySelector('#vl_qty');
    var rate = form.querySelector('#vl_rate');
    var amount = form.querySelector('#vl_amount');
    var guests = parseInt(form.getAttribute('data-guests') || '0', 10);

    function recalc() {
      var opt = service.options[service.selectedIndex];
      amount.value = opt && opt.value ? formatRs(toInt(qty.value) * toPaisa(opt.getAttribute('data-rate') || '')) : '';
    }
    service.addEventListener('change', function () {
      var opt = service.options[service.selectedIndex];
      unit.value = opt && opt.value ? opt.getAttribute('data-unit') : '';
      rate.value = opt && opt.value ? formatRs(toPaisa(opt.getAttribute('data-rate') || '')) : '';
      if (opt && opt.getAttribute('data-unit-key') === 'per person' && guests > 0) {
        qty.value = String(guests);
      }
      recalc();
    });
    qty.addEventListener('input', recalc);
  });

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('booking-form');
    if (!form) {
      return;
    }

    function field(name) { return form.elements.namedItem(name); }
    function setText(id, text) { var el = document.getElementById(id); if (el) { el.textContent = text; } }

    // ---- Live totals preview ---------------------------------------------------------------
    // A user's form has no price inputs: the stored prices ride on data attributes instead.
    function priceOf(name) {
      return field(name) ? field(name).value : form.getAttribute('data-' + name.replace(/_rate$/, '').replace(/_/g, '-'));
    }
    function recalc() {
      var guests = toInt(field('guests') && field('guests').value);
      var guestCharges = toPaisa(priceOf('per_head_rate')) * guests;
      var charges = 0;
      form.querySelectorAll('.charge-line').forEach(function (row) {
        var unit = row.getAttribute('data-unit');
        var selected = row.querySelector('input[type=checkbox]').checked;
        var rateInput = row.querySelector('input.rate');
        var rate = toPaisa(rateInput ? rateInput.value : row.getAttribute('data-rate'));
        var qtyInput = row.querySelector('input.qty');
        var amount = 0;
        if (selected) {
          if (unit === 'fixed') { amount = rate; }
          else if (unit === 'per unit') { amount = rate * toInt(qtyInput && qtyInput.value); }
          else if (unit === 'per head') { amount = rate * guests; }
        }
        charges += amount;
        var amountCell = row.querySelector('.line-amount');
        if (amountCell) { amountCell.textContent = formatRs(amount); }
      });
      // Extra dishes on the menu: the rate typed on the booking (admin), else the one the form shows.
      form.querySelectorAll('input[name="menu_extra[]"]:checked').forEach(function (box) {
        var rateBox = form.querySelector('input[name="menu_extra_rate[' + box.value + ']"]');
        var rate = toPaisa(rateBox ? rateBox.value : box.getAttribute('data-rate'));
        charges += box.getAttribute('data-unit') === 'fixed' ? rate : rate * guests;
      });
      var sub = guestCharges + charges;
      var grand = sub - toPaisa(priceOf('discount'));
      setText('t-guest', formatRs(guestCharges));
      setText('t-guest2', formatRs(guestCharges));
      setText('t-charges', formatRs(charges));
      setText('t-sub', formatRs(sub));
      setText('t-grand', formatRs(grand));
      setText('guests-readout', String(guests));
      // "n ticked" beside each closed group of Items to provide.
      form.querySelectorAll('.item-group').forEach(function (group) {
        var n = group.querySelectorAll('tr.charge-line input[type=checkbox]:checked').length;
        var count = group.querySelector('.item-group-count');
        if (count) { count.textContent = n ? n + ' ticked' : ''; }
      });
    }
    // Printing the sheet shows every group, open or not.
    window.addEventListener('beforeprint', function () {
      form.querySelectorAll('.item-group').forEach(function (group) { group.open = true; });
    });

    // ---- "If Other, specify" fields --------------------------------------------------------
    function syncOther(select) {
      var target = document.getElementById(select.getAttribute('data-other'));
      if (!target) { return; }
      var isOther = select.value === 'Other' || select.value === 'other';
      target.closest('.field').classList.toggle('hidden', !isOther);
    }

    // ---- Venue location follows the chosen venue ---------------------------------------------
    // The server stores whatever is in the box (and falls back to the venue's own location when it
    // is left empty), so this only saves typing. A location the user has edited by hand is kept.
    function syncVenueLocation(select) {
      var target = document.getElementById(select.getAttribute('data-location-target'));
      if (!target || target.getAttribute('data-touched') === '1') { return; }
      var option = select.options[select.selectedIndex];
      target.value = (option && option.getAttribute('data-location')) || '';
    }

    // ---- Event weekday from the date ---------------------------------------------------------
    function syncDay() {
      var d = field('event_date') && field('event_date').value;
      var day = '—';
      if (/^\d{4}-\d{2}-\d{2}$/.test(d || '')) {
        var parts = d.split('-');
        day = new Date(+parts[0], +parts[1] - 1, +parts[2]).toLocaleDateString('en-GB', { weekday: 'long' });
      }
      setText('event-day', day);
    }

    // ---- Menu package -------------------------------------------------------------------------
    // Every package's dishes are in the markup (so the form works without script); this shows only
    // the chosen one. Choosing a priced package fills in its per-head rate (the server does the same
    // for a user's form, which has no rate box), and a package's minimum guests is a warning only.
    var menuSelect = field('menu_package_id');
    var basePerHead = form.getAttribute('data-per-head') || '';
    function chosenMenuOption() { return menuSelect && menuSelect.options[menuSelect.selectedIndex]; }
    function syncMenuMin() {
      var warn = document.getElementById('menu-min-warning');
      var opt = chosenMenuOption();
      if (!warn || !opt) { return; }
      var min = parseInt(opt.getAttribute('data-min') || '0', 10);
      var guests = toInt(field('guests') && field('guests').value);
      warn.hidden = !(min > 0 && guests < min);
      var unit = opt.getAttribute('data-unit') || 'guests';
      warn.textContent = warn.hidden ? '' : 'This package is for at least ' + min + ' ' + unit + '; the booking has ' + guests + '.';
    }
    function syncMenu(applyRate) {
      if (!menuSelect) { return; }
      form.querySelectorAll('.menu-package').forEach(function (box) {
        box.hidden = box.getAttribute('data-package') !== menuSelect.value;
      });
      var rate = chosenMenuOption() && chosenMenuOption().getAttribute('data-rate');
      var rateBox = field('per_head_rate');
      if (applyRate && rate && rateBox) {
        rateBox.value = rate;
      } else if (applyRate && !rateBox) {
        // A user's form has no rate box: preview the package's rate, or the booking's own without one.
        var perHead = rate || basePerHead;
        form.setAttribute('data-per-head', perHead);
        var readout = document.getElementById('per-head-readout');
        if (readout) { readout.textContent = toPaisa(perHead) > 0 ? formatRs(toPaisa(perHead)) : 'set by Booking Organizer'; }
      }
      syncMenuMin();
    }
    if (menuSelect) {
      syncMenu(false);
      menuSelect.addEventListener('change', function () { syncMenu(true); });
      if (field('guests')) { field('guests').addEventListener('input', syncMenuMin); }
    }
    form.querySelectorAll('.menu-extras').forEach(function (group) {
      group.addEventListener('change', function () {
        var n = group.querySelectorAll('input[type=checkbox]:checked').length;
        group.querySelector('.menu-extra-count').textContent = n ? n + ' ticked' : '';
      });
    });

    form.querySelectorAll('select[data-other]').forEach(function (s) {
      s.addEventListener('change', function () { syncOther(s); });
    });
    form.querySelectorAll('select[data-location-target]').forEach(function (s) {
      var target = document.getElementById(s.getAttribute('data-location-target'));
      if (target) {
        target.addEventListener('input', function () { target.setAttribute('data-touched', '1'); });
      }
      s.addEventListener('change', function () { syncVenueLocation(s); });
    });
    form.addEventListener('input', recalc);
    form.addEventListener('change', recalc);
    if (field('event_date')) { field('event_date').addEventListener('change', syncDay); }

    // ---- Event slots follow the date and venue -----------------------------------------------
    // The server renders the slots for the date and venue the page opened with; this refreshes them
    // from booking/slots.php whenever either changes. Slots come from the admin's configuration for
    // that venue, so nothing about them is known here. Only availability comes back, never who holds
    // a slot. The save re-checks the slot under a lock: this is guidance, not the guard.
    var slotField = document.getElementById('slot-field');
    var slotGrid = document.getElementById('slot-grid');
    var slotMsg = document.getElementById('slot-message');
    var slotRequest = 0;
    var MSG_PICK = 'Choose the date of the event and a venue to see the available slots.';
    var MSG_NONE_SET = 'No event slots are set up for this venue yet. Choose another venue.';
    var MSG_ALL_BOOKED = 'No time slots available for this venue on the selected date. Please select another date or venue.';

    function validDate(d) { return /^\d{4}-\d{2}-\d{2}$/.test(d || ''); }
    function setSlotMessage(text, isError) {
      if (!slotMsg) { return; }
      slotMsg.textContent = text || '';
      slotMsg.hidden = !text;
      slotMsg.classList.toggle('is-error', !!isError);
    }
    function chosenSlot() {
      var r = slotGrid && slotGrid.querySelector('input[name="slot_id"]:checked');
      return r ? r.value : '';
    }
    function slotCard(s, chosen) {
      var selectable = s.available || s.own;
      var label = document.createElement('label');
      label.className = 'slot-card' + (selectable ? '' : ' is-booked');
      var input = document.createElement('input');
      input.type = 'radio';
      input.name = 'slot_id';
      input.value = String(s.id);
      input.disabled = !selectable;
      input.checked = selectable && String(s.id) === chosen;
      var icon = document.createElement('span');
      icon.className = 'slot-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = s.icon || '';
      var body = document.createElement('span');
      body.className = 'slot-body';
      var name = document.createElement('span');
      name.className = 'slot-name';
      name.textContent = s.name;             // textContent, never innerHTML: names are admin-entered
      var time = document.createElement('span');
      time.className = 'slot-time';
      time.textContent = s.time;
      body.appendChild(name);
      body.appendChild(time);
      var state = document.createElement('span');
      state.className = 'slot-state';
      state.textContent = s.own && s.disabled ? 'Your booking · no longer offered' : (selectable ? 'Available' : 'Booked');
      label.appendChild(input);
      label.appendChild(icon);
      label.appendChild(body);
      label.appendChild(state);
      return label;
    }
    function renderSlots(slots, chosen) {
      slotGrid.textContent = '';
      slots.forEach(function (s) { slotGrid.appendChild(slotCard(s, chosen)); });
      slotGrid.hidden = slots.length === 0;
      var open = slots.filter(function (s) { return s.available || s.own; }).length;
      if (!slots.length) {
        setSlotMessage(MSG_NONE_SET);
      } else if (!open) {
        setSlotMessage(MSG_ALL_BOOKED);
      } else if (chosen && !chosenSlot()) {
        setSlotMessage('The slot you had chosen is not available on this date. Choose another slot.', true);
      } else {
        setSlotMessage('');
      }
      refreshSteps();
    }
    function refreshSlots() {
      if (!slotField) { return; }
      var venue = field('venue_id');
      var v = venue ? venue.value : '';
      var d = field('event_date') ? field('event_date').value : '';
      var isOther = v === 'other';
      slotField.classList.toggle('hidden', isOther);
      var start = form.querySelector('.start-time-field');
      if (start) { start.classList.toggle('hidden', !isOther); }
      if (isOther) { return; }

      var chosen = chosenSlot();
      var context = document.getElementById('slot-context');
      if (!/^\d+$/.test(v) || !validDate(d)) {
        slotRequest++;                        // drop any answer still on its way
        slotGrid.textContent = '';
        slotGrid.hidden = true;
        if (context) { context.textContent = ''; }
        setSlotMessage(MSG_PICK);
        refreshSteps();
        return;
      }
      if (context) {
        var parts = d.split('-');
        context.textContent = venue.options[venue.selectedIndex].textContent.trim() + ' · ' +
          new Date(+parts[0], +parts[1] - 1, +parts[2])
            .toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
      }
      var req = ++slotRequest;
      slotField.classList.add('is-loading');
      slotField.setAttribute('aria-busy', 'true');
      var booking = slotField.getAttribute('data-booking');
      var q = '?venue_id=' + encodeURIComponent(v) + '&date=' + encodeURIComponent(d) +
        (booking ? '&booking=' + encodeURIComponent(booking) : '');
      fetch(slotField.getAttribute('data-endpoint') + q, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (!r.ok) { throw new Error('HTTP ' + r.status); }
          return r.json();
        })
        .then(function (data) {
          if (req !== slotRequest) { return; }  // the user has moved on to another date or venue
          renderSlots(data.slots || [], chosen);
        })
        .catch(function () {
          if (req !== slotRequest) { return; }
          setSlotMessage('Couldn’t load the slots for this venue. Check the connection and change the date or venue to try again.', true);
        })
        .then(function () {
          if (req !== slotRequest) { return; }
          slotField.classList.remove('is-loading');
          slotField.removeAttribute('aria-busy');
        });
    }
    // "Continue" from the Event step needs a slot once a venue and date are chosen (the save insists too).
    function slotMissing() {
      if (!slotField || slotField.classList.contains('hidden') || chosenSlot()) { return false; }
      var v = field('venue_id') ? field('venue_id').value : '';
      return /^\d+$/.test(v) && validDate(field('event_date') && field('event_date').value);
    }
    if (slotField) {
      if (field('venue_id')) { field('venue_id').addEventListener('change', refreshSlots); }
      if (field('event_date')) { field('event_date').addEventListener('change', refreshSlots); }
      slotGrid.addEventListener('change', function () {
        if (slotMsg && slotMsg.classList.contains('is-error')) { setSlotMessage(''); }
      });
    }

    // ---- The wizard: three steps over one form ----------------------------------------------
    // The sheet is one long form of 7–9 sections, and it must stay one form: a single POST is what
    // reserves the SLA number and writes the totals. So the steps are presentation only — every
    // section stays in the DOM, and the `is-wizard` class added here (never in the markup) is what
    // hides the inactive ones. No script, no hiding: the whole agreement shows, just as it prints.
    var stepper = form.querySelector('.wizard-steps');
    var panels = Array.prototype.slice.call(form.querySelectorAll('.step-panel'));
    var blocks = Array.prototype.slice.call(form.querySelectorAll('.block[data-nav]'));
    var stepBtns = stepper ? Array.prototype.slice.call(stepper.querySelectorAll('.step')) : [];
    var prevBtn = form.querySelector('[data-wizard="prev"]');
    var nextBtn = form.querySelector('[data-wizard="next"]');
    var saveBtn = form.querySelector('button[type="submit"]');
    var position = document.getElementById('step-position');
    var current = 0;

    function realInputs(block) {
      return Array.prototype.filter.call(
        block.querySelectorAll('input, select, textarea'),
        // A menu package that isn't chosen is hidden; its pre-ticked choices aren't the user's entries.
        function (el) { return el.type !== 'hidden' && el.name && !el.closest('[hidden]'); });
    }
    function filledCount(block) {
      return realInputs(block).filter(function (el) {
        if (el.type === 'checkbox' || el.type === 'radio') { return el.checked; }
        return String(el.value || '').trim() !== '';
      }).length;
    }
    function errorCount(block) { return block.querySelectorAll('.field-error').length; }
    function setCollapsed(block, collapsed) {
      block.classList.toggle('collapsed', collapsed);
      var btn = block.querySelector('.block-toggle');
      if (btn) { btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true'); }
    }
    function panelIndexOf(el) {
      var p = el && el.closest ? el.closest('.step-panel') : null;
      return p ? panels.indexOf(p) : -1;
    }

    // Each section heading becomes a real toggle button, so it is reachable by keyboard.
    function buildToggles() {
      blocks.forEach(function (block, i) {
        var head = block.querySelector('.block-head');
        if (!head) { return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'block-toggle';
        btn.setAttribute('aria-expanded', 'true');
        btn.setAttribute('aria-controls', block.id);
        var num = document.createElement('span');
        num.className = 'block-num';
        num.textContent = String(i + 1);
        btn.appendChild(num);
        var label = document.createElement('span');
        label.className = 'block-label';
        label.textContent = head.textContent;
        btn.appendChild(label);
        var count = document.createElement('span');
        count.className = 'block-count';
        btn.appendChild(count);
        var chev = document.createElement('span');
        chev.className = 'block-chevron';
        chev.setAttribute('aria-hidden', 'true');
        btn.appendChild(chev);
        head.textContent = '';
        head.appendChild(btn);
        btn.addEventListener('click', function () {
          setCollapsed(block, !block.classList.contains('collapsed'));
        });
      });
    }

    function buildStepper() {
      if (!stepper || !panels.length) { return; }
      stepBtns.forEach(function (btn, n) {
        var meta = btn.querySelector('.step-meta');
        // Keep the server-rendered hint: it is what an untouched step goes back to saying.
        if (meta) { meta.setAttribute('data-hint', meta.textContent); }
        btn.addEventListener('click', function () { showStep(n, true); });
      });
      stepper.hidden = false;
    }

    function showStep(i, moveFocus) {
      if (!panels.length) { return; }
      current = Math.max(0, Math.min(i, panels.length - 1));
      var last = current === panels.length - 1;
      panels.forEach(function (p, n) { p.classList.toggle('active', n === current); });
      stepBtns.forEach(function (b, n) {
        b.classList.toggle('current', n === current);
        if (n === current) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); }
      });
      if (prevBtn) { prevBtn.hidden = current === 0; }
      if (nextBtn) {
        nextBtn.hidden = last;
        nextBtn.classList.toggle('primary', !last);
      }
      // Moving on is the primary action until the last step, where saving is.
      if (saveBtn) { saveBtn.classList.toggle('primary', last); }
      if (position) {
        var named = stepBtns[current] && stepBtns[current].querySelector('.step-name');
        position.textContent = 'Step ' + (current + 1) + ' of ' + panels.length +
          (named ? ' · ' + named.textContent : '');
      }
      if (moveFocus) {
        try {
          (stepper || panels[current]).scrollIntoView({ block: 'start' });
        } catch (e) { /* older browsers: the step still changed, it just did not scroll */ }
        panels[current].focus({ preventScroll: true });
      }
    }

    function refreshSteps() {
      panels.forEach(function (panel, n) {
        var mine = blocks.filter(function (b) { return b.closest('.step-panel') === panel; });
        var filled = 0, errs = 0, started = 0;
        mine.forEach(function (block) {
          var f = filledCount(block);
          var e = errorCount(block);
          filled += f;
          errs += e;
          if (f > 0) { started++; }
          var count = block.querySelector('.block-count');
          if (count) {
            count.textContent = e ? e + (e === 1 ? ' problem' : ' problems') : (f ? f + ' filled' : 'empty');
            count.className = 'block-count' + (e ? ' has-error' : (f ? ' has-value' : ''));
          }
        });
        var btn = stepBtns[n];
        if (!btn) { return; }
        btn.classList.toggle('has-error', errs > 0);
        btn.classList.toggle('is-done', errs === 0 && mine.length > 0 && started === mine.length);
        var meta = btn.querySelector('.step-meta');
        if (!meta) { return; }
        if (errs) {
          meta.textContent = errs + (errs === 1 ? ' problem' : ' problems');
        } else if (filled) {
          meta.textContent = filled + (filled === 1 ? ' field filled' : ' fields filled');
        } else {
          meta.textContent = meta.getAttribute('data-hint') || '';
        }
      });
    }

    // The strip under the title: who, when, where, how many, how much — visible on every step.
    function refreshSummary() {
      function put(id, value) {
        var el = document.getElementById(id);
        if (el) { el.textContent = value ? value : '—'; }
      }
      put('sum-client', field('client_name') && field('client_name').value.trim());
      var d = field('event_date') && field('event_date').value;
      var when = '';
      if (/^\d{4}-\d{2}-\d{2}$/.test(d || '')) {
        var parts = d.split('-');
        when = new Date(+parts[0], +parts[1] - 1, +parts[2])
          .toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
      }
      put('sum-date', when);
      var venue = field('venue_id');
      var chosen = venue && venue.options ? venue.options[venue.selectedIndex] : null;
      var venueName = chosen && chosen.value ? chosen.textContent.trim() : '';
      if (chosen && chosen.value === 'other') {
        venueName = (field('venue_other') && field('venue_other').value.trim()) || 'Other';
      }
      put('sum-venue', venueName);
      put('sum-guests', field('guests') && field('guests').value.trim());
      var net = document.getElementById('t-grand');
      if (net) { put('sum-net', net.textContent); }
    }

    if (panels.length) { form.classList.add('is-wizard'); }
    buildToggles();
    buildStepper();
    if (prevBtn) { prevBtn.addEventListener('click', function () { showStep(current - 1, true); }); }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        if (panelIndexOf(slotField) === current && slotMissing()) {
          var open = slotGrid.querySelector('input[name="slot_id"]:not(:disabled)');
          if (open) { setSlotMessage('Choose an available event slot to continue.', true); }
          try { slotField.scrollIntoView({ block: 'center' }); } catch (e) { /* still shown, just not scrolled to */ }
          if (open) { open.focus(); }
          return;
        }
        showStep(current + 1, true);
      });
    }

    // Optional sections start collapsed, but only when they are empty and error-free —
    // never hide something the user typed or something the server complained about.
    blocks.forEach(function (block) {
      if (block.getAttribute('data-collapsible') === '1' && !filledCount(block) && !errorCount(block)) {
        setCollapsed(block, true);
      }
    });
    showStep(0, false);
    refreshSteps();
    refreshSummary();
    form.addEventListener('input', function () { refreshSteps(); refreshSummary(); });
    form.addEventListener('change', function () { refreshSteps(); refreshSummary(); });

    // A section holding an error must never be hidden: open its step, open the section, go to it.
    // Scrolling is a convenience — never let it stop the handlers registered below from binding.
    var firstError = form.querySelector('.field-error');
    if (firstError) {
      var owner = firstError.closest('.block');
      if (owner) { setCollapsed(owner, false); }
      var errStep = panelIndexOf(firstError);
      if (errStep >= 0) { showStep(errStep, false); }
      try {
        firstError.scrollIntoView({ block: 'center' });
      } catch (e) { /* older browsers: the error is still visible, just not scrolled to */ }
    }

    // ---- Unsaved-changes warning -------------------------------------------------------------
    var dirty = false;
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
      if (dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    });

    // Recalculate only when the form is editable (read-only pages show the stored server totals).
    // The summary strip mirrors #t-grand, so it is refreshed after, not before, that first pass.
    if (form.getAttribute('data-readonly') !== '1') {
      recalc();
      refreshSummary();
    }
  });
})();
