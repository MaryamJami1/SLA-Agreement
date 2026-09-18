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
})();
