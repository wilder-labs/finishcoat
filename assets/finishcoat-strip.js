/*!
 * Finishcoat: takes ?finishcoat=CODE out of the address bar as early as possible. Printed inline at the top of
 * <head>, and only when the address has that parameter, so analytics and other scripts that run later on the page
 * never see the review code. The value is kept in window.FinishcoatUrlCode for the page script.
 */
(function () {
  'use strict';
  try {
    var found = null;
    var rest = location.search.replace(/^\?/, '').split('&').filter(function (kv) {
      if (!kv) return false;
      if (kv.split('=')[0] !== 'finishcoat') return true;
      found = kv.slice('finishcoat'.length + 1);
      return false;
    });
    if (found === null) return;
    window.FinishcoatUrlCode = found;
    history.replaceState(history.state, '', location.pathname + (rest.length ? '?' + rest.join('&') : '') + location.hash);
  } catch (e) {
    /* old browser: the page script takes the code out instead */
  }
})();
