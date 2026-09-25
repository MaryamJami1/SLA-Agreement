/* AO Mess — client-side conveniences only. The server validates and computes everything. */
(function () {
  'use strict';

  // <form data-confirm="Are you sure?">: ask before submitting.
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
    }
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

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('booking-form');
    if (!form) {
      return;
    }

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
    function field(name) { return form.elements.namedItem(name); }
    function setText(id, text) { var el = document.getElementById(id); if (el) { el.textContent = text; } }

    // ---- Live totals preview ---------------------------------------------------------------
    function recalc() {
      var guests = toInt(field('guests') && field('guests').value);
      var guestCharges = toPaisa(field('per_head_rate') && field('per_head_rate').value) * guests;
      var charges = 0;
      form.querySelectorAll('tr.charge-line').forEach(function (row) {
        var unit = row.getAttribute('data-unit');
        var selected = row.querySelector('input[type=checkbox]').checked;
        var rate = toPaisa(row.querySelector('input.rate').value);
        var qtyInput = row.querySelector('input.qty');
        var amount = 0;
        if (selected) {
          if (unit === 'fixed') { amount = rate; }
          else if (unit === 'per unit') { amount = rate * toInt(qtyInput && qtyInput.value); }
          else if (unit === 'per head') { amount = rate * guests; }
        }
        charges += amount;
        row.querySelector('.line-amount').textContent = formatRs(amount);
      });
      var sub = guestCharges + charges;
      var grand = sub - toPaisa(field('discount') && field('discount').value);
      setText('t-guest', formatRs(guestCharges));
      setText('t-guest2', formatRs(guestCharges));
      setText('t-charges', formatRs(charges));
      setText('t-sub', formatRs(sub));
      setText('t-grand', formatRs(grand));
      setText('guests-readout', String(guests));
    }

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

    // ---- The wizard: five steps over one form -----------------------------------------------
    // The sheet is one long form of ~16 sections, and it must stay one form: a single POST is what
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
        function (el) { return el.type !== 'hidden' && el.name; });
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
    if (nextBtn) { nextBtn.addEventListener('click', function () { showStep(current + 1, true); }); }

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
