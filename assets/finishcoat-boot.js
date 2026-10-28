/*!
 * Finishcoat loader. Printed inline before the page script.
 * It sets up window.Finishcoat.register() for add-on modules, and in "Only people who open the review link"
 * mode it loads the page script only for a visitor who has a review code (in the address or saved in this
 * browser) or on a page with a [finishcoat board="yes"] board. Everyone else gets nothing more than this.
 */
(function () {
  'use strict';
  var C = window.FinishcoatConfig;
  if (!C || !C.src) return;
  var FC = window.Finishcoat = window.Finishcoat || {};
  FC.queue = FC.queue || [];
  if (!FC.register) FC.register = function (name, module) { FC.queue.push([name, module]); };
  if (!C.lazy) return;

  function hasCode() {
    if (/[?&]finishcoat=[^&#]/.test(location.search)) return true;
    try {
      var o = JSON.parse(localStorage.getItem('finishcoat-code:' + (C.project || 'site')) || 'null');
      return !!(o && o.c && +o.exp > Date.now());
    } catch (e) { return false; }
  }
  function go() {
    if (!hasCode() && !document.querySelector('[data-finishcoat-board]')) return;
    if (document.querySelector('script[data-finishcoat-main]')) return;
    var s = document.createElement('script');
    s.src = C.src;
    s.defer = true;
    s.setAttribute('data-finishcoat-main', '');
    (document.head || document.documentElement).appendChild(s);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();
