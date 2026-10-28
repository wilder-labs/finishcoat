/* Finishcoat admin: the Copy review link buttons, the confirm prompts on code actions, and Cancel on photo deletes. */
(function () {
  'use strict';
  var T = window.finishcoatAdmin || {};
  function flash(btn, text) {
    var old = btn.getAttribute('data-label') || btn.textContent;
    btn.setAttribute('data-label', old);
    btn.textContent = text;
    setTimeout(function () { btn.textContent = old; }, 1800);
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.finishcoat-copy') : null;
    if (btn) {
      var link = btn.getAttribute('data-copy') || '';
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(link).then(function () { flash(btn, T.copied || 'Copied'); }, function () { window.prompt(T.failed || '', link); });
      } else {
        window.prompt(T.failed || '', link);
      }
      return;
    }
    var cancel = e.target.closest ? e.target.closest('.finishcoat-cancel') : null;
    if (cancel) {
      var d = cancel.closest('details');
      if (d) { d.open = false; var sum = d.querySelector('summary'); if (sum) sum.focus(); }
      return;
    }
    var ask = e.target.closest ? e.target.closest('[data-confirm]') : null;
    if (ask && !window.confirm(ask.getAttribute('data-confirm'))) e.preventDefault();
  });
})();
