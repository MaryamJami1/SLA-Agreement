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
      setText('total-staff', String(toInt(field('waiters') && field('waiters').value) + toInt(field('chefs') && field('chefs').value)));
    }

    // ---- "If Other, specify" fields --------------------------------------------------------
    function syncOther(select) {
      var target = document.getElementById(select.getAttribute('data-other'));
      if (!target) { return; }
      var isOther = select.value === 'Other' || select.value === 'other';
      target.closest('.field').classList.toggle('hidden', !isOther);
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
    form.addEventListener('input', recalc);
    form.addEventListener('change', recalc);
    if (field('event_date')) { field('event_date').addEventListener('change', syncDay); }

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
    if (form.getAttribute('data-readonly') !== '1') {
      recalc();
    }
  });
})();
