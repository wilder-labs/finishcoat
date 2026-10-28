/*!
 * Finishcoat page script 1.0.0
 * https://wilderlabs.studio/finishcoat/ | GPLv2 or later
 *
 * Adds the Suggestions tab to the site's pages. Behind a review code, visitors read the notes on the page, leave a
 * note (pinned to a spot or not), reply, and attach up to 3 photos. "Try my photo here" shows a photo of their own
 * in any spot on the page, for them only: nothing is sent until they press "Suggest this photo", which turns it
 * into a note pinned to that spot with the photo and a before-and-after pair. A [finishcoat board="yes"] element
 * gets the board of every note. Logged-in editors reply, set a status and delete, right on the page.
 * Everything talks to this site's admin-ajax.php only.
 *
 * Add-ons: window.Finishcoat.register(name, module). A module is called with the API object built in start().
 */
(function () {
  'use strict';
  var C = window.FinishcoatConfig;
  if (!C || window.__finishcoatMain || location.protocol === 'file:') return;
  window.__finishcoatMain = true;
  var FC = (window.Finishcoat = window.Finishcoat || {});
  FC.queue = FC.queue || [];
  if (!FC.register)
    FC.register = function (name, module) {
      FC.queue.push([name, module]);
    };

  /* ---------- words: every string comes translated from the server ---------- */
  var I18N = C.i18n || {};
  function t(key) {
    var s = Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : key;
    var args = Array.prototype.slice.call(arguments, 1),
      i = 0;
    return String(s).replace(/%%|%(?:(\d)\$)?[sd]/g, function (m, pos) {
      if (m === '%%') return '%';
      var v = pos ? args[+pos - 1] : args[i++];
      return v == null ? '' : String(v);
    });
  }

  var AJAX = C.ajax;
  var PROJECT = C.project || 'site';
  var ORIGIN = location.origin;
  var SITE = String(C.site || '');
  var path = location.pathname.replace(/\/index\.(html|php)$/, '/');
  /* iPhone Safari turns HEIC into JPEG on its own only when the accept list leaves HEIC out */
  var IOS =
    /iP(hone|ad|od)/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var ACCEPT = IOS
    ? 'image/jpeg,image/png,image/webp'
    : 'image/jpeg,image/png,image/webp,image/heic,image/heif';

  /* ---------- helpers ---------- */
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (attrs)
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v == null || v === false) return;
        if (k === 'text') n.textContent = v;
        else if (k === 'on')
          Object.keys(v).forEach(function (e) {
            n.addEventListener(e, v[e]);
          });
        else if (k === 'cls') n.className = v;
        else n.setAttribute(k, v === true ? '' : v);
      });
    (kids || []).forEach(function (c) {
      if (c != null) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  }
  function store(k, v) {
    try {
      if (v === undefined) return localStorage.getItem(k);
      if (v === null) {
        localStorage.removeItem(k);
        return null;
      }
      localStorage.setItem(k, v);
    } catch (e) {
      return null;
    }
    return null;
  }
  function when(iso) {
    var d = new Date(iso);
    if (isNaN(d)) return '';
    var s = (Date.now() - d.getTime()) / 1000;
    if (s < 60) return t('just now');
    if (s < 3600) return t('%d min ago', Math.floor(s / 60));
    if (s < 86400) return t('%d hr ago', Math.floor(s / 3600));
    return d.toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric'
    });
  }
  /* FNV-1a 32-bit of a string, 8 hex characters. Identifies a photo by its source. */
  function fnv(s) {
    var h = 0x811c9dc5;
    for (var i = 0; i < s.length; i++) {
      h ^= s.charCodeAt(i);
      h = Math.imul(h, 0x01000193);
    }
    return ('0000000' + (h >>> 0).toString(16)).slice(-8);
  }
  function sameOrigin(u) {
    if (!u) return false;
    if (/^(data|blob):/i.test(u)) return true;
    try {
      return new URL(u, location.href).origin === ORIGIN;
    } catch (e) {
      return false;
    }
  }

  /* ---------- review codes ---------- */
  var GATE = { code: '', studio: '', pub: '', hit: null };
  var CODE_ABC = /^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/;
  var CODE_KEY = 'finishcoat-code:' + PROJECT;
  function normCode(v) {
    var c = String(v == null ? '' : v)
      .toUpperCase()
      .replace(/[\s-]+/g, '');
    return CODE_ABC.test(c) ? c : '';
  }
  function getCode() {
    var raw = store(CODE_KEY),
      o = null;
    if (!raw) return '';
    try {
      o = JSON.parse(raw);
    } catch (e) {
      o = null;
    }
    if (!o || !normCode(o.c) || !(+o.exp > Date.now())) {
      store(CODE_KEY, null);
      return '';
    }
    return normCode(o.c);
  }
  function putCode(c) {
    if (normCode(c)) store(CODE_KEY, JSON.stringify({ c: normCode(c), exp: Date.now() + 90 * 86400000 }));
  }
  /* the review link: ?finishcoat=CODE. The code comes out of the address bar at once, so it is not shared by a
     screenshot or a copied address. Every other parameter and the hash stay. */
  function takeUrlCode() {
    var found = '',
      seen = false;
    var parts = location.search
      .replace(/^\?/, '')
      .split('&')
      .filter(function (kv) {
        if (!kv) return false;
        var k = kv.split('=')[0],
          key = k;
        try {
          key = decodeURIComponent(k.replace(/\+/g, ' '));
        } catch (e) {
          /* keep it as it is */
        }
        if (key !== 'finishcoat') return true;
        seen = true;
        var v = kv.slice(k.length + 1),
          val = v;
        try {
          val = decodeURIComponent(v.replace(/\+/g, ' '));
        } catch (e) {
          /* keep it as it is */
        }
        if (normCode(val)) found = normCode(val);
        return false;
      });
    if (seen) {
      try {
        history.replaceState(
          history.state,
          '',
          location.pathname + (parts.length ? '?' + parts.join('&') : '') + location.hash
        );
      } catch (e) {
        /* old browser */
      }
    }
    return { code: found, seen: seen };
  }

  /* every request is a POST to this site's admin-ajax.php, so a code never sits in an address or a server log */
  function api(action, data) {
    var fd = new URLSearchParams();
    fd.append('action', action);
    fd.append('project', PROJECT);
    Object.keys(data || {}).forEach(function (k) {
      if (data[k] != null) fd.append(k, data[k]);
    });
    if (GATE.code && !fd.has('code')) fd.append('code', GATE.code);
    if (!fd.has('nonce') && (GATE.studio || GATE.pub)) fd.append('nonce', GATE.studio || GATE.pub);
    return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) {
        return r.text().then(function (txt) {
          var j = null;
          try {
            j = JSON.parse(txt);
          } catch (e) {
            j = null;
          }
          if (!j)
            return { ok: false, message: t('The notes service did not answer. Try again in a minute.') };
          if (j.code_required && GATE.hit) GATE.hit(j);
          return j;
        });
      })
      .catch(function () {
        return { ok: false, message: t('No connection. Check your internet and try again.') };
      });
  }

  /* ---------- boot ---------- */
  var URL_CODE = takeUrlCode();
  function wrong() {
    return t('That code did not work. Check it and try again.');
  }
  /* WordPress marks the body of a logged-in visitor's page; only then can there be an editor session */
  function loggedIn() {
    return (
      !!(document.body && document.body.classList.contains('logged-in')) ||
      !!document.getElementById('wpadminbar')
    );
  }
  function whenReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  var first = URL_CODE.code || getCode();
  api('finishcoat_list', { code: first || null, i18n: C.i18n ? null : '1' }).then(function (j) {
    if (j && j.i18n && typeof j.i18n === 'object') I18N = j.i18n;
    booted(j, first, {});
  });

  /* try the code from the link, then the one this browser saved, then the editor login, then ask */
  function booted(j, sent, tried, note) {
    if (j && j.ok && j.project) {
      if (sent) {
        GATE.code = sent;
        putCode(sent);
      }
      GATE.pub = j.nonce || '';
      whenReady(function () {
        start(j);
      });
      return;
    }
    if (!j || !j.code_required) return;
    var limited = !!j.limited;
    if (sent && sent === URL_CODE.code) {
      note = wrong();
      tried.link = true;
    } else if (sent && !limited) store(CODE_KEY, null);
    if (limited) note = j.message;
    if (URL_CODE.seen && !URL_CODE.code && !note) {
      note = wrong();
      tried.link = true;
    }
    var saved = getCode();
    if (!limited && !tried.saved && saved && saved !== sent) {
      tried.saved = true;
      api('finishcoat_list', { code: saved }).then(function (k) {
        booted(k, saved, tried, note);
      });
      return;
    }
    if (!tried.studio && loggedIn()) {
      tried.studio = true;
      api('finishcoat_session', {}).then(function (s) {
        if (!s || !s.studio || !s.nonce) {
          booted(j, '', tried, note);
          return;
        }
        GATE.studio = s.nonce;
        api('finishcoat_list', {}).then(function (k) {
          booted(k, '', tried, note);
        });
      });
      return;
    }
    whenReady(function () {
      start({
        project: { slug: PROJECT, label: C.label },
        gated: true,
        note: note || '',
        open: !!tried.link
      });
    });
  }

  function start(boot) {
    var P = boot.project || {};
    var TH = C.theme || {};
    var LABEL =
      String(P.label || C.label || t('Suggestions'))
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 40) || t('Suggestions');
    var boardSlot = document.querySelector('[data-finishcoat-board]');
    var pageTitle = (document.title || path).replace(/\s+/g, ' ').trim().slice(0, 140);
    var heading1 = document.querySelector('h1,h2');
    var FONT = (getComputedStyle(document.body).fontFamily || 'system-ui,sans-serif').replace(/[<>{};]/g, '');
    var DISPLAY = heading1 ? (getComputedStyle(heading1).fontFamily || FONT).replace(/[<>{};]/g, '') : FONT;
    function col(k, d) {
      var v = typeof TH[k] === 'string' ? TH[k] : '';
      return /^#[0-9A-Fa-f]{6}$/.test(v) ? v : d;
    }

    /* ---------- styles (shadow DOM keeps them away from the page, and the page's away from them) ---------- */
    var CSS = [
      ':host{',
      '  all:initial',
      '}',
      '[hidden]{',
      '  display:none!important',
      '}',
      '*{',
      '  box-sizing:border-box',
      '}',
      '.fc-r{',
      '  --paper:' + col('paper', '#F5F2EA') + ';',
      '  --panel:' + col('panel', '#FFFFFF') + ';',
      '  --ink:' + col('ink', '#120A2E') + ';',
      '  --soft:' + col('soft', '#5B5476') + ';',
      '  --line:' + col('line', '#E6E1D6') + ';',
      '  --wash:' + col('wash', '#EEEAE1') + ';',
      '  --accent:' + col('accent', '#5B5476') + ';',
      '  --accent-ink:' + col('accentInk', '#3E3760') + ';',
      '  --btn:' + col('btn', '#120A2E') + ';',
      '  --btn-ink:' + col('btnInk', '#F5F2EA') + ';',
      '  --rad:' + (/^\d{1,2}px$/.test(TH.radius || '') ? TH.radius : '4px') + ';',
      '  --body:' + FONT + ';',
      '  --display:' + DISPLAY + ';',
      '  font-family:var(--body);',
      '  color:var(--ink);',
      '  font-size:15px;',
      '  line-height:1.5;',
      '  -webkit-font-smoothing:antialiased',
      '}',
      'button,input,textarea,select{',
      '  font:inherit;',
      '  color:inherit',
      '}',
      'button{',
      '  cursor:pointer',
      '}',
      ':focus-visible{',
      '  outline:2px solid var(--accent-ink);',
      '  outline-offset:2px',
      '}',
      '.fc-tab{',
      '  position:fixed;',
      '  right:0;',
      '  top:50%;',
      '  transform:translateY(-50%);',
      '  z-index:2147483000;',
      '  display:flex;',
      '  align-items:center;',
      '  gap:12px;',
      '  writing-mode:vertical-rl;',
      '  padding:22px 14px;',
      '  background:var(--btn);',
      '  color:var(--btn-ink);',
      '  border:0;',
      '  border-radius:10px 0 0 10px;',
      '  box-shadow:0 8px 30px rgba(0,0,0,.28);',
      '  font-size:15px;',
      '  letter-spacing:.08em;',
      '  text-transform:uppercase;',
      '  font-weight:700;',
      '  transition:padding .15s',
      '}',
      '.fc-tab:hover{',
      '  padding-right:20px',
      '}',
      '.fc-tab svg{',
      '  writing-mode:horizontal-tb;',
      '  width:22px;',
      '  height:22px;',
      '  flex:none',
      '}',
      '.fc-tab .fc-n{',
      '  writing-mode:horizontal-tb;',
      '  min-width:22px;',
      '  height:22px;',
      '  padding:0 6px;',
      '  border-radius:11px;',
      '  background:var(--btn-ink);',
      '  color:var(--btn);',
      '  font-size:12px;',
      '  line-height:22px;',
      '  text-align:center;',
      '  letter-spacing:0',
      '}',
      '.fc-tab .fc-dot{',
      '  writing-mode:horizontal-tb;',
      '  width:8px;',
      '  height:8px;',
      '  border-radius:50%;',
      '  background:var(--accent)',
      '}',
      '.fc-tab[aria-expanded="true"]{',
      '  opacity:0;',
      '  pointer-events:none',
      '}',
      '.fc-hint{',
      '  position:fixed;',
      '  right:76px;',
      '  top:50%;',
      '  transform:translateY(-50%);',
      '  z-index:2147483000;',
      '  background:var(--btn);',
      '  color:var(--btn-ink);',
      '  padding:12px 16px;',
      '  border-radius:10px;',
      '  font-size:15px;',
      '  font-weight:600;',
      '  box-shadow:0 8px 30px rgba(0,0,0,.28);',
      '  max-width:240px;',
      '  line-height:1.35;',
      '  cursor:pointer',
      '}',
      '.fc-scrim{',
      '  position:fixed;',
      '  inset:0;',
      '  z-index:2147483001;',
      '  background:rgba(20,20,18,.28);',
      '  opacity:0;',
      '  pointer-events:none;',
      '  transition:opacity .2s',
      '}',
      '.fc-scrim.fc-on{',
      '  opacity:1;',
      '  pointer-events:auto',
      '}',
      '.fc-drawer{',
      '  position:fixed;',
      '  top:0;',
      '  right:0;',
      '  bottom:0;',
      '  z-index:2147483002;',
      '  width:min(420px,100vw);',
      '  background:var(--paper);',
      '  box-shadow:-12px 0 40px rgba(0,0,0,.16);',
      '  transform:translateX(102%);',
      '  visibility:hidden;',
      '  transition:transform .25s ease,visibility 0s linear .25s;',
      '  display:flex;',
      '  flex-direction:column',
      '}',
      '.fc-drawer.fc-on{',
      '  transform:none;',
      '  visibility:visible;',
      '  transition:transform .25s ease',
      '}',
      '@media (prefers-reduced-motion:reduce){',
      '  .fc-drawer,.fc-scrim{',
      '    transition:none',
      '  }',
      '}',
      '.fc-hd{',
      '  padding:20px 22px 14px;',
      '  border-bottom:1px solid var(--line);',
      '  display:flex;',
      '  gap:12px;',
      '  align-items:flex-start',
      '}',
      '.fc-hd h2{',
      '  margin:0;',
      '  font-family:var(--display);',
      '  font-weight:400;',
      '  font-size:24px;',
      '  line-height:1.15;',
      '  flex:1',
      '}',
      '.fc-hd h2:focus{',
      '  outline:none',
      '}',
      '.fc-hd p{',
      '  margin:4px 0 0;',
      '  color:var(--soft);',
      '  font-size:13px',
      '}',
      '.fc-x{',
      '  border:0;',
      '  background:none;',
      '  width:36px;',
      '  height:36px;',
      '  margin:-6px -8px 0 0;',
      '  border-radius:50%;',
      '  font-size:22px;',
      '  line-height:36px;',
      '  color:var(--soft)',
      '}',
      '.fc-x:hover{',
      '  background:var(--wash)',
      '}',
      '.fc-who{',
      '  display:inline-block;',
      '  margin-top:8px;',
      '  font-size:12px;',
      '  padding:2px 8px;',
      '  border-radius:10px;',
      '  background:var(--wash);',
      '  color:var(--accent-ink)',
      '}',
      '.fc-body{',
      '  flex:1;',
      '  overflow:auto;',
      '  padding:14px 22px 24px',
      '}',
      '.fc-ft{',
      '  border-top:1px solid var(--line);',
      '  padding:14px 22px 18px;',
      '  background:var(--panel);',
      '  max-height:62vh;',
      '  overflow:auto',
      '}',
      '.fc-filters{',
      '  display:flex;',
      '  flex-wrap:wrap;',
      '  gap:6px;',
      '  margin:2px 0 12px',
      '}',
      '.fc-chip{',
      '  border:1px solid var(--line);',
      '  background:var(--panel);',
      '  border-radius:14px;',
      '  padding:3px 11px;',
      '  font-size:13px;',
      '  color:var(--soft)',
      '}',
      '.fc-chip[aria-pressed="true"]{',
      '  background:var(--ink);',
      '  border-color:var(--ink);',
      '  color:var(--paper)',
      '}',
      '.fc-empty{',
      '  color:var(--soft);',
      '  font-size:14px;',
      '  padding:18px 0;',
      '  margin:0',
      '}',
      '.fc-note{',
      '  background:var(--panel);',
      '  border:1px solid var(--line);',
      '  border-radius:var(--rad);',
      '  padding:12px 14px;',
      '  margin:0 0 10px;',
      '  scroll-margin:16px',
      '}',
      '.fc-note.fc-flash{',
      '  animation:fcfl 1.6s ease',
      '}',
      '@keyframes fcfl{',
      '  0%,40%{',
      '    box-shadow:0 0 0 3px var(--accent)',
      '  }',
      '  100%{',
      '    box-shadow:0 0 0 0 transparent',
      '  }',
      '}',
      '.fc-meta{',
      '  display:flex;',
      '  align-items:center;',
      '  gap:8px;',
      '  flex-wrap:wrap;',
      '  font-size:12.5px;',
      '  color:var(--soft)',
      '}',
      '.fc-meta b{',
      '  color:var(--ink);',
      '  font-weight:600',
      '}',
      '.fc-num{',
      '  display:inline-flex;',
      '  align-items:center;',
      '  justify-content:center;',
      '  min-width:22px;',
      '  height:22px;',
      '  border-radius:11px;',
      '  background:var(--btn);',
      '  color:var(--btn-ink);',
      '  font-size:11.5px;',
      '  font-weight:600;',
      '  border:0;',
      '  padding:0 6px',
      '}',
      '.fc-st{',
      '  margin-left:auto;',
      '  font-size:11.5px;',
      '  letter-spacing:.04em;',
      '  text-transform:uppercase;',
      '  padding:2px 8px;',
      '  border-radius:10px;',
      '  background:var(--wash);',
      '  color:var(--soft);',
      '  font-weight:600',
      '}',
      '.fc-st.fc-s-new{',
      '  background:var(--accent-ink);',
      '  color:#fff',
      '}',
      '.fc-st.fc-s-doing{',
      '  background:var(--ink);',
      '  color:var(--paper)',
      '}',
      '.fc-st.fc-s-done{',
      '  background:transparent;',
      '  border:1px solid var(--line)',
      '}',
      '.fc-txt{',
      '  margin:6px 0 0;',
      '  white-space:pre-wrap;',
      '  overflow-wrap:anywhere',
      '}',
      '.fc-note.fc-s-done .fc-txt{',
      '  color:var(--soft)',
      '}',
      '.fc-where{',
      '  margin-top:6px;',
      '  font-size:12.5px;',
      '  color:var(--soft)',
      '}',
      '.fc-where a{',
      '  color:var(--accent-ink)',
      '}',
      '.fc-reps{',
      '  margin:10px 0 0;',
      '  padding:0 0 0 12px;',
      '  border-left:2px solid var(--line)',
      '}',
      '.fc-rep{',
      '  margin:0 0 8px',
      '}',
      '.fc-rep.fc-studio .fc-meta b{',
      '  color:var(--accent-ink)',
      '}',
      '.fc-acts{',
      '  display:flex;',
      '  gap:10px;',
      '  margin-top:8px;',
      '  flex-wrap:wrap;',
      '  align-items:center',
      '}',
      '.fc-lnk{',
      '  border:0;',
      '  background:none;',
      '  padding:2px 0;',
      '  font-size:13px;',
      '  color:var(--accent-ink);',
      '  text-decoration:underline;',
      '  text-underline-offset:2px',
      '}',
      '.fc-lnk.fc-warn{',
      '  color:#9B2C1F',
      '}',
      'select.fc-status{',
      '  margin-left:auto;',
      '  border:1px solid var(--line);',
      '  background:var(--panel);',
      '  border-radius:var(--rad);',
      '  padding:3px 6px;',
      '  font-size:13px',
      '}',
      'label.fc-f{',
      '  display:block;',
      '  font-size:12.5px;',
      '  font-weight:600;',
      '  margin:0 0 4px',
      '}',
      'textarea,input.fc-t{',
      '  width:100%;',
      '  border:1px solid var(--line);',
      '  border-radius:var(--rad);',
      '  background:#fff;',
      '  padding:9px 11px;',
      '  font-size:15px;',
      '  color:#1c1c1a',
      '}',
      'textarea{',
      '  min-height:84px;',
      '  resize:vertical',
      '}',
      'textarea:focus,input.fc-t:focus{',
      '  outline:2px solid var(--accent);',
      '  outline-offset:0;',
      '  border-color:transparent',
      '}',
      '.fc-row{',
      '  display:flex;',
      '  gap:8px;',
      '  align-items:center;',
      '  margin-top:10px;',
      '  flex-wrap:wrap',
      '}',
      '.fc-row .fc-grow{',
      '  flex:1;',
      '  min-width:140px',
      '}',
      '.fc-btn{',
      '  border:0;',
      '  border-radius:var(--rad);',
      '  background:var(--btn);',
      '  color:var(--btn-ink);',
      '  padding:10px 16px;',
      '  font-weight:600;',
      '  font-size:14px',
      '}',
      '.fc-btn:disabled{',
      '  opacity:.55;',
      '  cursor:default',
      '}',
      '.fc-btn2{',
      '  border:1px solid var(--line);',
      '  border-radius:var(--rad);',
      '  background:var(--panel);',
      '  padding:9px 12px;',
      '  font-size:14px',
      '}',
      '.fc-btn2:hover{',
      '  border-color:var(--ink)',
      '}',
      '.fc-btn2:disabled{',
      '  opacity:.55;',
      '  cursor:default',
      '}',
      '.fc-btn2 svg{',
      '  width:16px;',
      '  height:16px;',
      '  vertical-align:-3px;',
      '  margin-right:6px',
      '}',
      '.fc-pinned{',
      '  display:flex;',
      '  gap:8px;',
      '  align-items:center;',
      '  flex-wrap:wrap;',
      '  margin-top:8px;',
      '  font-size:13px;',
      '  color:var(--soft)',
      '}',
      '.fc-msg{',
      '  font-size:13px;',
      '  margin-top:8px',
      '}',
      '.fc-msg:empty{',
      '  display:none',
      '}',
      '.fc-msg.fc-err{',
      '  color:#9B2C1F',
      '}',
      '.fc-msg.fc-ok{',
      '  color:var(--accent-ink)',
      '}',
      '.fc-hp{',
      '  position:absolute;',
      '  left:-9999px;',
      '  width:1px;',
      '  height:1px;',
      '  overflow:hidden',
      '}',
      '.fc-replying{',
      '  font-size:12.5px;',
      '  color:var(--soft);',
      '  margin:0 0 6px;',
      '  display:flex;',
      '  gap:8px',
      '}',
      '.fc-sr{',
      '  position:absolute;',
      '  width:1px;',
      '  height:1px;',
      '  overflow:hidden;',
      '  clip:rect(0 0 0 0);',
      '  white-space:nowrap',
      '}',
      '.fc-tip{',
      '  font-size:12.5px;',
      '  color:var(--soft);',
      '  margin:10px 0 0',
      '}',
      '.fc-credit{',
      '  margin:10px 0 0;',
      '  font-size:11.5px;',
      '  color:var(--soft)',
      '}',
      '.fc-credit a{',
      '  color:inherit',
      '}',
      '.fc-pins{',
      '  position:absolute;',
      '  top:0;',
      '  left:0;',
      '  width:0;',
      '  height:0;',
      '  z-index:2147482999',
      '}',
      '.fc-pin{',
      '  position:absolute;',
      '  transform:translate(-50%,-100%);',
      '  pointer-events:auto;',
      '  border:2px solid #fff;',
      '  background:var(--btn);',
      '  color:var(--btn-ink);',
      '  min-width:28px;',
      '  height:28px;',
      '  padding:0 7px;',
      '  border-radius:14px 14px 14px 2px;',
      '  font-size:12px;',
      '  font-weight:700;',
      '  box-shadow:0 3px 10px rgba(0,0,0,.25)',
      '}',
      '.fc-pin.fc-s-new{',
      '  background:var(--accent-ink);',
      '  color:#fff',
      '}',
      '.fc-pin.fc-s-done{',
      '  opacity:.55',
      '}',
      '.fc-pin.fc-off{',
      '  visibility:hidden;',
      '  pointer-events:none',
      '}',
      '.fc-pin.fc-ghost{',
      '  background:var(--accent-ink);',
      '  color:#fff;',
      '  animation:fcpp 1s ease infinite alternate',
      '}',
      '.fc-pin.fc-pulse{',
      '  animation:fcpp .5s ease 4 alternate',
      '}',
      '@keyframes fcpp{',
      '  to{',
      '    transform:translate(-50%,-100%) scale(1.18)',
      '  }',
      '}',
      '@media (prefers-reduced-motion:reduce){',
      '  .fc-pin.fc-ghost,.fc-pin.fc-pulse{',
      '    animation:none',
      '  }',
      '}',
      '.fc-pm{',
      '  position:fixed;',
      '  inset:0;',
      '  z-index:2147483003;',
      '  cursor:crosshair;',
      '  background:rgba(0,0,0,.04)',
      '}',
      '.fc-bar{',
      '  position:fixed;',
      '  left:50%;',
      '  transform:translateX(-50%);',
      '  z-index:2147483004;',
      '  background:var(--ink);',
      '  color:var(--paper);',
      '  padding:10px 12px 10px 16px;',
      '  border-radius:var(--rad);',
      '  font-size:14px;',
      '  line-height:1.4;',
      '  box-shadow:0 6px 24px rgba(0,0,0,.25);',
      '  display:flex;',
      '  gap:10px 14px;',
      '  align-items:center;',
      '  flex-wrap:wrap;',
      '  width:max-content;',
      '  max-width:calc(100vw - 32px)',
      '}',
      '.fc-bar.fc-top{',
      '  top:14px',
      '}',
      '.fc-bar.fc-bottom{',
      '  bottom:18px',
      '}',
      '.fc-bar span{',
      '  flex:1 1 220px',
      '}',
      '.fc-bar button{',
      '  border:1px solid rgba(255,255,255,.45);',
      '  background:none;',
      '  color:inherit;',
      '  border-radius:var(--rad);',
      '  padding:5px 11px;',
      '  font-size:13px;',
      '  font-weight:600',
      '}',
      '.fc-bar button:hover{',
      '  border-color:currentColor',
      '}',
      '.fc-bar button.fc-main{',
      '  background:var(--paper);',
      '  border-color:var(--paper);',
      '  color:var(--ink)',
      '}',
      '.fc-bar :focus-visible{',
      '  outline-color:var(--paper)',
      '}',
      '.fc-hl{',
      '  position:fixed;',
      '  z-index:2147483002;',
      '  pointer-events:none;',
      '  outline:2px dashed var(--accent);',
      '  outline-offset:2px;',
      '  border-radius:2px',
      '}',
      '.fc-try{',
      '  position:absolute;',
      '  z-index:2147482998;',
      '  pointer-events:auto;',
      '  display:flex;',
      '  align-items:center;',
      '  gap:8px;',
      '  background:var(--btn);',
      '  color:var(--btn-ink);',
      '  border:2px solid #fff;',
      '  border-radius:22px;',
      '  padding:8px 14px;',
      '  font-size:13.5px;',
      '  font-weight:700;',
      '  box-shadow:0 6px 22px rgba(0,0,0,.35);',
      '  white-space:nowrap',
      '}',
      '.fc-try svg{',
      '  width:18px;',
      '  height:18px',
      '}',
      '.fc-fi{',
      '  position:absolute;',
      '  width:1px;',
      '  height:1px;',
      '  opacity:0;',
      '  overflow:hidden;',
      '  clip:rect(0 0 0 0);',
      '  pointer-events:none',
      '}',
      '.fc-pbox{',
      '  margin-top:10px',
      '}',
      '.fc-fnote{',
      '  margin:0 0 8px;',
      '  font-size:12.5px;',
      '  color:var(--soft)',
      '}',
      '.fc-files{',
      '  display:flex;',
      '  flex-wrap:wrap;',
      '  gap:8px;',
      '  margin:0;',
      '  padding:0;',
      '  list-style:none',
      '}',
      '.fc-fth{',
      '  position:relative;',
      '  width:64px;',
      '  height:64px;',
      '  border-radius:var(--rad);',
      '  overflow:hidden;',
      '  background:var(--wash);',
      '  border:1px solid var(--line)',
      '}',
      '.fc-fth img{',
      '  display:block;',
      '  width:100%;',
      '  height:100%;',
      '  object-fit:cover',
      '}',
      '.fc-fth .fc-ext{',
      '  display:flex;',
      '  align-items:center;',
      '  justify-content:center;',
      '  width:100%;',
      '  height:100%;',
      '  font-size:11px;',
      '  font-weight:600;',
      '  color:var(--soft);',
      '  text-transform:uppercase',
      '}',
      '.fc-fth .fc-rm{',
      '  position:absolute;',
      '  top:3px;',
      '  right:3px;',
      '  width:24px;',
      '  height:24px;',
      '  padding:0;',
      '  border:0;',
      '  border-radius:50%;',
      '  background:rgba(20,20,18,.72);',
      '  color:#fff;',
      '  font-size:16px;',
      '  line-height:24px;',
      '  text-align:center',
      '}',
      '.fc-fth .fc-rm:disabled{',
      '  display:none',
      '}',
      '.fc-consent{',
      '  display:flex;',
      '  gap:8px;',
      '  align-items:flex-start;',
      '  margin-top:10px;',
      '  font-size:13px;',
      '  line-height:1.4;',
      '  cursor:pointer',
      '}',
      '.fc-consent input{',
      '  flex:none;',
      '  width:16px;',
      '  height:16px;',
      '  margin:1px 0 0;',
      '  accent-color:var(--btn)',
      '}',
      '.fc-prog{',
      '  height:4px;',
      '  margin-top:10px;',
      '  border-radius:2px;',
      '  background:var(--wash);',
      '  overflow:hidden',
      '}',
      '.fc-prog i{',
      '  display:block;',
      '  height:100%;',
      '  width:0;',
      '  background:var(--accent-ink);',
      '  transition:width .2s',
      '}',
      '.fc-phs{',
      '  display:flex;',
      '  flex-wrap:wrap;',
      '  gap:10px;',
      '  margin-top:10px',
      '}',
      '.fc-ph{',
      '  display:block;',
      '  width:64px;',
      '  height:64px;',
      '  border-radius:6px;',
      '  overflow:hidden;',
      '  background:var(--wash);',
      '  border:1px solid var(--line)',
      '}',
      '.fc-ph img{',
      '  display:block;',
      '  width:100%;',
      '  height:100%;',
      '  object-fit:cover',
      '}',
      '.fc-pair{',
      '  display:flex;',
      '  flex-direction:column;',
      '  gap:6px;',
      '  margin-top:10px',
      '}',
      '.fc-pair .fc-pl{',
      '  font-size:12.5px;',
      '  color:var(--soft)',
      '}',
      '.fc-pair .fc-pi{',
      '  display:flex;',
      '  align-items:center;',
      '  gap:8px',
      '}',
      '.fc-pair figure{',
      '  margin:0;',
      '  display:flex;',
      '  flex-direction:column;',
      '  gap:2px;',
      '  font-size:11px;',
      '  color:var(--soft);',
      '  text-transform:uppercase;',
      '  letter-spacing:.04em',
      '}',
      '.fc-pair .fc-ph{',
      '  width:96px;',
      '  height:72px',
      '}',
      '.fc-arrow{',
      '  color:var(--soft);',
      '  font-size:16px',
      '}',
      '.fc-gate{',
      '  flex:1;',
      '  overflow:auto;',
      '  padding:22px 22px 28px',
      '}',
      '.fc-gate label.fc-f{',
      '  font-size:14px;',
      '  margin-bottom:8px',
      '}',
      '.fc-gaterow{',
      '  display:flex;',
      '  gap:8px;',
      '  align-items:stretch',
      '}',
      '.fc-gaterow input.fc-t{',
      '  flex:1 1 auto;',
      '  min-width:0;',
      '  width:auto;',
      '  font-size:16px;',
      '  letter-spacing:.08em;',
      '  text-transform:uppercase',
      '}',
      '.fc-gaterow input.fc-t::placeholder{',
      '  letter-spacing:normal;',
      '  text-transform:none',
      '}',
      '.fc-forget{',
      '  margin:10px 0 0;',
      '  font-size:12.5px;',
      '  color:var(--soft)',
      '}',
      '.fc-cl{',
      '  color:var(--soft)',
      '}',
      '.fc-board{',
      '  margin:40px 0 24px;',
      '  max-width:100%',
      '}',
      '.fc-board .fc-bhd{',
      '  display:flex;',
      '  align-items:flex-end;',
      '  justify-content:space-between;',
      '  gap:16px;',
      '  flex-wrap:wrap;',
      '  border-bottom:1px solid var(--line);',
      '  padding-bottom:12px;',
      '  margin-bottom:16px',
      '}',
      '.fc-board h2{',
      '  font-family:var(--display);',
      '  font-weight:400;',
      '  font-size:clamp(24px,3vw,34px);',
      '  margin:0;',
      '  line-height:1.1;',
      '  color:var(--ink)',
      '}',
      '.fc-board .fc-lede{',
      '  margin:6px 0 0;',
      '  color:var(--soft);',
      '  max-width:60ch',
      '}',
      '.fc-board .fc-counts{',
      '  display:flex;',
      '  gap:16px;',
      '  font-size:13px;',
      '  color:var(--soft)',
      '}',
      '.fc-board .fc-counts b{',
      '  display:block;',
      '  font-family:var(--display);',
      '  font-weight:400;',
      '  font-size:24px;',
      '  color:var(--ink)',
      '}',
      '.fc-grid{',
      '  display:grid;',
      '  grid-template-columns:repeat(auto-fill,minmax(280px,1fr));',
      '  gap:12px;',
      '  align-items:start',
      '}',
      '.fc-grid .fc-note{',
      '  margin:0',
      '}',
      '.fc-tools{',
      '  display:flex;',
      '  gap:14px;',
      '  flex-wrap:wrap;',
      '  margin-bottom:14px',
      '}',
      '.fc-tools .fc-filters{',
      '  margin:0',
      '}',
      '.fc-more{',
      '  margin-top:12px',
      '}',
      '@media (max-width:640px){',
      '  .fc-tab{',
      '    top:auto;',
      '    bottom:calc(96px + env(safe-area-inset-bottom,0px));',
      '    transform:none;',
      '    padding:16px 10px;',
      '    font-size:14px',
      '  }',
      '  .fc-hint{',
      '    top:auto;',
      '    bottom:calc(110px + env(safe-area-inset-bottom,0px));',
      '    transform:none;',
      '    right:62px',
      '  }',
      '  .fc-hd h2{',
      '    font-size:21px',
      '  }',
      '  .fc-bar.fc-bottom{',
      '    bottom:calc(12px + env(safe-area-inset-bottom,0px))',
      '  }',
      '}',
      '@media print{',
      '  .fc-tab,.fc-drawer,.fc-scrim,.fc-pins,.fc-bar,.fc-try,.fc-hint{',
      '    display:none!important',
      '  }',
      '}'
    ].join('\n');

    function shadowHost(id, where, before) {
      var host = el('div', { id: id, 'data-finishcoat': '' });
      if (before) where.insertBefore(host, before);
      else where.appendChild(host);
      var root = host.attachShadow({ mode: 'open' });
      root.appendChild(el('style', { text: CSS }));
      var r = el('div', { cls: 'fc-r' });
      root.appendChild(r);
      return { host: host, root: root, r: r };
    }
    function svgIcon(d) {
      var ns = 'http://www.w3.org/2000/svg',
        v = document.createElementNS(ns, 'svg');
      v.setAttribute('viewBox', '0 0 24 24');
      v.setAttribute('fill', 'none');
      v.setAttribute('stroke', 'currentColor');
      v.setAttribute('stroke-width', '2');
      v.setAttribute('stroke-linecap', 'round');
      v.setAttribute('stroke-linejoin', 'round');
      v.setAttribute('aria-hidden', 'true');
      d.forEach(function (x) {
        var e = document.createElementNS(ns, x[0]);
        Object.keys(x[1]).forEach(function (k) {
          e.setAttribute(k, x[1][k]);
        });
        v.appendChild(e);
      });
      return v;
    }
    function bubbleIcon() {
      return svgIcon([
        ['path', { d: 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z' }],
        ['path', { d: 'M8.5 11h7M8.5 14h4.5' }]
      ]);
    }
    function photoIcon() {
      return svgIcon([
        ['rect', { x: '3', y: '4', width: '18', height: '16', rx: '2' }],
        ['circle', { cx: '9', cy: '10', r: '2' }],
        ['path', { d: 'M21 17l-5-5-9 8' }]
      ]);
    }

    /* ---------- the code box ---------- */
    function gateBox(note, onOk) {
      var inp = el('input', {
        cls: 'fc-t',
        id: 'fc-code',
        type: 'text',
        autocomplete: 'off',
        autocapitalize: 'characters',
        spellcheck: 'false',
        maxlength: '20',
        'aria-describedby': 'fc-code-help'
      });
      var go = el('button', { cls: 'fc-btn', type: 'submit', text: t('Continue') });
      var gm = el('div', {
        cls: 'fc-msg' + (note ? ' fc-err' : ''),
        role: 'status',
        'aria-live': 'polite',
        text: note || ''
      });
      var box = el(
        'form',
        {
          cls: 'fc-gate',
          novalidate: true,
          on: {
            submit: function (e) {
              e.preventDefault();
              check();
            }
          }
        },
        [
          el('label', { cls: 'fc-f', 'for': 'fc-code', text: t('Enter your review code') }),
          el('div', { cls: 'fc-gaterow' }, [inp, go]),
          el('p', { cls: 'fc-tip', id: 'fc-code-help', text: t('Your web designer sent you this code.') }),
          gm
        ]
      );
      function say(x) {
        gm.className = 'fc-msg fc-err';
        gm.textContent = x;
      }
      function check() {
        if (go.disabled) return;
        if (!inp.value.trim()) {
          say(t('Enter your review code.'));
          inp.focus();
          return;
        }
        var c = normCode(inp.value);
        if (!c) {
          say(wrong());
          inp.focus();
          return;
        }
        go.disabled = true;
        go.textContent = t('Checking...');
        gm.className = 'fc-msg';
        gm.textContent = '';
        api('finishcoat_list', { code: c }).then(function (j) {
          go.disabled = false;
          go.textContent = t('Continue');
          if (j && j.ok && j.project) {
            putCode(c);
            GATE.code = c;
            GATE.pub = j.nonce || '';
            onOk(j);
            return;
          }
          say(
            j && j.code_required
              ? j.limited
                ? j.message
                : wrong()
              : (j && j.message) || t('Could not load notes.')
          );
          inp.focus();
          inp.select();
        });
      }
      box.__focus = function () {
        try {
          inp.focus({ preventScroll: true });
        } catch (e) {
          inp.focus();
        }
      };
      return box;
    }

    /* no working code yet: only the tab and the code box */
    if (boot.gated) {
      var gUi = shadowHost('finishcoat-ui', document.body);
      var gOpen = false,
        gLast = null,
        gDone = false;
      var gTab = el(
        'button',
        {
          cls: 'fc-tab',
          type: 'button',
          'aria-expanded': 'false',
          'aria-controls': 'fc-drawer',
          on: {
            click: function () {
              gShow(true);
            }
          }
        },
        [bubbleIcon(), el('span', { text: LABEL })]
      );
      var gScrim = el('div', {
        cls: 'fc-scrim',
        on: {
          click: function () {
            gShow(false);
          }
        }
      });
      var gBox = gateBox(boot.note, function (j) {
        gDone = true;
        gUi.host.remove();
        j.__open = gOpen;
        start(j);
      });
      var gDrawer = el(
        'div',
        {
          cls: 'fc-drawer',
          id: 'fc-drawer',
          role: 'dialog',
          'aria-modal': 'false',
          'aria-labelledby': 'fc-dh'
        },
        [
          el('div', { cls: 'fc-hd' }, [
            el('h2', { id: 'fc-dh', tabindex: '-1', text: LABEL }),
            el('button', {
              cls: 'fc-x',
              type: 'button',
              'aria-label': t('Close %s', LABEL),
              text: '×',
              on: {
                click: function () {
                  gShow(false);
                }
              }
            })
          ]),
          gBox
        ]
      );
      gUi.r.appendChild(gTab);
      gUi.r.appendChild(gScrim);
      gUi.r.appendChild(gDrawer);
      var gShow = function (on) {
        if (gDone) return;
        if (on && !gOpen) gLast = document.activeElement;
        gOpen = on;
        gDrawer.classList.toggle('fc-on', on);
        gScrim.classList.toggle('fc-on', on && matchMedia('(max-width:900px)').matches);
        gTab.setAttribute('aria-expanded', String(on));
        if (on) setTimeout(gBox.__focus, 60);
        else {
          try {
            (gLast && gLast.focus ? gLast : gTab).focus({ preventScroll: true });
          } catch (e) {
            /* gone */
          }
        }
      };
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && gOpen && !gDone) gShow(false);
      });
      if (boot.open) gShow(true);
      return;
    }

    /* ---------- state ---------- */
    var S = {
      notes: [],
      photos: [],
      statuses: { 'new': t('New'), noted: t('Noted'), doing: t('In progress'), done: t('Done') },
      name: '',
      studio: false,
      open: false,
      filter: 'open',
      replyTo: 0,
      anchor: null,
      target: null,
      shownAt: 0,
      loaded: false,
      files: [],
      suggest: null,
      busy: false,
      boardPage: 'all',
      boardStatus: 'all',
      boardLimit: 24
    };
    var MAX_MB = 15,
      MAX_FILES = 3;
    function limits() {
      MAX_MB = +P.photo_max_mb > 0 ? +P.photo_max_mb : 15;
      MAX_FILES = +P.photo_max_files > 0 ? Math.min(3, Math.floor(+P.photo_max_files)) : 3;
    }
    limits();

    /* ---------- event bus for add-on modules ---------- */
    var BUS = {};
    function on(name, fn) {
      (BUS[name] = BUS[name] || []).push(fn);
    }
    function off(name, fn) {
      BUS[name] = (BUS[name] || []).filter(function (f) {
        return f !== fn;
      });
    }
    function emit(name, data) {
      (BUS[name] || []).slice().forEach(function (f) {
        try {
          f(data);
        } catch (e) {
          /* a module failed; the page goes on */
        }
      });
    }

    /* ---------- tab, drawer, pins ---------- */
    var ui = shadowHost('finishcoat-ui', document.body);
    var pinsUi = shadowHost('finishcoat-pins', document.documentElement);
    pinsUi.host.style.cssText = 'position:absolute;top:0;left:0;width:0;height:0;z-index:2147482999;';
    var pinLayer = el('div', { cls: 'fc-pins' });
    pinsUi.r.appendChild(pinLayer);

    var tabN = el('span', { cls: 'fc-n', text: '0' });
    var tabDot = el('span', { cls: 'fc-dot', hidden: true });
    var tab = el(
      'button',
      {
        cls: 'fc-tab',
        type: 'button',
        'aria-expanded': 'false',
        'aria-controls': 'fc-drawer',
        on: {
          click: function () {
            openDrawer();
          }
        }
      },
      [bubbleIcon(), el('span', { text: LABEL }), tabN, tabDot]
    );
    var scrim = el('div', {
      cls: 'fc-scrim',
      on: {
        click: function () {
          closeDrawer();
        }
      }
    });
    var listBox = el('div', { cls: 'fc-list' });
    var filters = el('div', { cls: 'fc-filters', role: 'group', 'aria-label': t('Show') });
    var whoTag = el('span', { cls: 'fc-who', hidden: true });
    var heading = el('h2', { id: 'fc-dh', tabindex: '-1', text: LABEL });
    var closeBtn = el('button', {
      cls: 'fc-x',
      type: 'button',
      'aria-label': t('Close %s', LABEL),
      text: '×',
      on: {
        click: function () {
          closeDrawer();
        }
      }
    });

    /* composer */
    var PLACEHOLDER = t('What would you change, keep or ask about on this page?');
    var ta = el('textarea', { id: 'fc-body', maxlength: '2000', placeholder: PLACEHOLDER });
    var nameIn = el('input', {
      cls: 'fc-t',
      id: 'fc-name',
      type: 'text',
      maxlength: '60',
      autocomplete: 'name',
      placeholder: t('Your name')
    });
    var hp = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' });
    var pinBtn = el('button', {
      cls: 'fc-btn2',
      type: 'button',
      text: t('Pin it to a spot'),
      on: {
        click: function () {
          startPin();
        }
      }
    });
    var sendBtn = el('button', { cls: 'fc-btn', type: 'submit', text: t('Send') });
    var msg = el('div', { cls: 'fc-msg', role: 'status', 'aria-live': 'polite' });
    var pinnedRow = el('div', { cls: 'fc-pinned', hidden: true });
    var replyRow = el('div', { cls: 'fc-replying', hidden: true });
    var nameWrap = el('div', { cls: 'fc-grow' }, [
      el('label', { cls: 'fc-sr', 'for': 'fc-name', text: t('Your name') }),
      nameIn
    ]);
    var closedLine = el('p', { cls: 'fc-empty', hidden: true, text: t('New notes are paused right now.') });
    /* real file inputs kept in the layout (not display:none), so a button click opens the picker on iPhone */
    var fileIn = el('input', {
      cls: 'fc-fi',
      type: 'file',
      accept: ACCEPT,
      multiple: true,
      tabindex: '-1',
      'aria-hidden': 'true',
      on: {
        change: function () {
          picked(fileIn);
        }
      }
    });
    var tryIn = el('input', {
      cls: 'fc-fi',
      type: 'file',
      accept: ACCEPT,
      tabindex: '-1',
      'aria-hidden': 'true',
      on: {
        change: function () {
          tried(tryIn);
        }
      }
    });
    var addBtn = el('button', {
      cls: 'fc-btn2',
      type: 'button',
      text: t('Add photos'),
      on: {
        click: function () {
          fileIn.click();
        }
      }
    });
    var tryBtn = el(
      'button',
      {
        cls: 'fc-btn2',
        type: 'button',
        on: {
          click: function () {
            startPick();
          }
        }
      },
      [photoIcon(), el('span', { text: t('Try my photo here') })]
    );
    var fileNote = el('p', { cls: 'fc-fnote' });
    var thumbs = el('ul', { cls: 'fc-files', 'aria-label': t('Photos to send') });
    var consent = el('input', { type: 'checkbox', id: 'fc-consent' });
    var consentRow = el('label', { cls: 'fc-consent', 'for': 'fc-consent' }, [
      consent,
      el('span', { text: t('I own these photos or have permission to use them.') })
    ]);
    var progBar = el('i');
    var prog = el('div', { cls: 'fc-prog', hidden: true, 'aria-hidden': 'true' }, [progBar]);
    var photoBox = el('div', { cls: 'fc-pbox', hidden: true }, [fileNote, thumbs, consentRow, prog]);
    var form = el(
      'form',
      {
        novalidate: true,
        on: {
          submit: function (e) {
            e.preventDefault();
            send();
          }
        }
      },
      [
        replyRow,
        el('label', { cls: 'fc-f', 'for': 'fc-body', text: t('Leave a suggestion') }),
        ta,
        el('div', { cls: 'fc-hp', 'aria-hidden': 'true' }, [el('label', null, ['Website', hp])]),
        pinnedRow,
        photoBox,
        el('div', { cls: 'fc-row' }, [nameWrap, pinBtn, addBtn, sendBtn]),
        el('div', { cls: 'fc-row' }, [tryBtn]),
        el('p', {
          cls: 'fc-tip',
          text: matchMedia('(hover: hover) and (pointer: fine)').matches
            ? t(
                'Want a different photo? Point at any photo on the page and click Try my photo here. Only you see it until you suggest it.'
              )
            : t(
                'Want a different photo? Tap Try my photo here, then tap a photo on the page. Only you see it until you suggest it.'
              )
        }),
        msg,
        fileIn,
        tryIn
      ]
    );
    var forgetRow = el('p', { cls: 'fc-forget', hidden: true }, [
      el('button', {
        cls: 'fc-lnk',
        type: 'button',
        text: t('Forget this code'),
        on: {
          click: function () {
            store(CODE_KEY, null);
            location.reload();
          }
        }
      })
    ]);
    var credit = C.credit
      ? el('p', { cls: 'fc-credit' }, [
          el('a', {
            href: 'https://wilderlabs.studio/finishcoat/',
            target: '_blank',
            rel: 'noopener',
            text: t('Made with Finishcoat')
          })
        ])
      : null;
    var dBody = el('div', { cls: 'fc-body' }, [filters, listBox]);
    var dFt = el('div', { cls: 'fc-ft' }, [closedLine, form, forgetRow, credit]);
    var drawer = el(
      'div',
      {
        cls: 'fc-drawer',
        id: 'fc-drawer',
        role: 'dialog',
        'aria-modal': 'false',
        'aria-labelledby': 'fc-dh'
      },
      [
        el('div', { cls: 'fc-hd' }, [
          el('div', { style: 'flex:1' }, [
            heading,
            el('p', {
              text: SITE
                ? t('Notes on this page go straight to %s.', SITE)
                : t('Notes on this page go straight to the site owner.')
            }),
            whoTag
          ]),
          closeBtn
        ]),
        dBody,
        dFt
      ]
    );
    ui.r.appendChild(tab);
    ui.r.appendChild(scrim);
    ui.r.appendChild(drawer);

    S.name = store('finishcoat-name') || '';
    nameIn.value = S.name;
    [
      ['open', t('Open')],
      ['done', t('Done')],
      ['all', t('All')]
    ].forEach(function (f) {
      filters.appendChild(
        el('button', {
          cls: 'fc-chip',
          type: 'button',
          'data-f': f[0],
          'aria-pressed': String(S.filter === f[0]),
          text: f[1],
          on: {
            click: function () {
              S.filter = f[0];
              renderDrawer();
            }
          }
        })
      );
    });

    function closed() {
      return P.active === false && !S.studio;
    }
    function linkOf(n) {
      return ORIGIN + n.page + '?finishcoat_note=' + n.id;
    }
    function pageNotes() {
      return S.notes
        .filter(function (n) {
          return !n.parent && n.page === path;
        })
        .sort(function (a, b) {
          return a.id - b.id;
        });
    }
    function repliesOf(id) {
      return S.notes
        .filter(function (n) {
          return n.parent === id;
        })
        .sort(function (a, b) {
          return a.id - b.id;
        });
    }
    function numberOf(id) {
      var l = pageNotes();
      for (var i = 0; i < l.length; i++) if (l[i].id === id) return i + 1;
      return 0;
    }
    function notePhotos(n) {
      var ids = Array.isArray(n.photos) ? n.photos : [];
      return S.photos
        .filter(function (p) {
          return p.note === n.id || ids.indexOf(p.id) >= 0;
        })
        .sort(function (a, b) {
          return a.id - b.id;
        });
    }
    function photoRow(n) {
      var list = notePhotos(n);
      if (!list.length) return null;
      var box = el('div');
      var plain = el('div', { cls: 'fc-phs' });
      list.forEach(function (p) {
        var big = el(
          'a',
          {
            cls: 'fc-ph',
            href: p.url,
            target: '_blank',
            rel: 'noopener',
            title: t('Open full size'),
            'aria-label': t('Photo from %s, open full size', p.author || t('the client'))
          },
          [el('img', { src: p.thumb || p.url, alt: '', loading: 'lazy', decoding: 'async' })]
        );
        if (p.status === 'suggested') {
          var pair = el('div', { cls: 'fc-pi' });
          if (p.before)
            pair.appendChild(
              el('figure', null, [
                el('span', { cls: 'fc-ph' }, [
                  el('img', { src: p.before, alt: t('The photo on the page now'), loading: 'lazy' })
                ]),
                t('Before')
              ])
            );
          if (p.before) pair.appendChild(el('span', { cls: 'fc-arrow', 'aria-hidden': 'true', text: '→' }));
          pair.appendChild(el('figure', null, [big, t('After')]));
          box.appendChild(
            el('div', { cls: 'fc-pair' }, [
              el('span', { cls: 'fc-pl', text: t('Suggested for “%s”', p.spot || t('a photo')) }),
              pair
            ])
          );
        } else plain.appendChild(big);
      });
      if (plain.firstChild) box.appendChild(plain);
      return box;
    }

    function noteCard(n, opts) {
      opts = opts || {};
      var num = opts.board ? 0 : numberOf(n.id);
      var card = el('article', {
        cls: 'fc-note fc-s-' + (n.status || ''),
        id: 'fc-n-' + n.id,
        'data-id': n.id
      });
      card.appendChild(
        el('div', { cls: 'fc-meta' }, [
          num
            ? n.anchor
              ? el('button', {
                  cls: 'fc-num',
                  type: 'button',
                  title: t('Show the spot on the page'),
                  'aria-label': t('Note %d, show the spot on the page', num),
                  text: String(num),
                  on: {
                    click: function () {
                      showPin(n.id);
                    }
                  }
                })
              : el('span', { cls: 'fc-num', text: String(num) })
            : null,
          el('b', { text: n.author || t('Someone') }),
          n.code_label ? el('span', { cls: 'fc-cl', text: '· ' + n.code_label }) : null,
          el('span', { text: when(n.time) }),
          S.studio && !opts.board
            ? statusSelect(n)
            : el('span', { cls: 'fc-st fc-s-' + n.status, text: S.statuses[n.status] || n.status })
        ])
      );
      card.appendChild(el('p', { cls: 'fc-txt', text: n.body }));
      var ph = photoRow(n);
      if (ph) card.appendChild(ph);
      if (opts.board)
        card.appendChild(
          el('div', { cls: 'fc-where' }, [
            t('On') + ' ',
            el('a', { href: linkOf(n), text: n.title || n.page }),
            n.anchor && n.anchor.lbl ? ' · ' + t('Pinned to “%s”', n.anchor.lbl) : ''
          ])
        );
      else if (n.anchor && n.anchor.lbl)
        card.appendChild(el('div', { cls: 'fc-where', text: t('Pinned to “%s”', n.anchor.lbl) }));
      var reps = repliesOf(n.id);
      if (reps.length) {
        var rb = el('div', { cls: 'fc-reps' });
        reps.forEach(function (r) {
          var rr = el('div', { cls: 'fc-rep' + (r.role === 'studio' ? ' fc-studio' : '') }, [
            el('div', { cls: 'fc-meta' }, [
              el('b', { text: r.author }),
              r.code_label ? el('span', { cls: 'fc-cl', text: '· ' + r.code_label }) : null,
              el('span', { text: when(r.time) })
            ]),
            el('p', { cls: 'fc-txt', text: r.body })
          ]);
          var rp = photoRow(r);
          if (rp) rr.appendChild(rp);
          if (S.studio && !opts.board) rr.appendChild(el('div', { cls: 'fc-acts' }, [delBtn(r)]));
          rb.appendChild(rr);
        });
        card.appendChild(rb);
      }
      var acts = el('div', { cls: 'fc-acts' });
      if (opts.board)
        acts.appendChild(el('a', { cls: 'fc-lnk', href: linkOf(n), text: t('Open on the page') }));
      else {
        if (!closed())
          acts.appendChild(
            el('button', {
              cls: 'fc-lnk',
              type: 'button',
              text: t('Reply'),
              on: {
                click: function () {
                  startReply(n);
                }
              }
            })
          );
        if (S.studio) acts.appendChild(delBtn(n));
      }
      card.appendChild(acts);
      return card;
    }
    function statusSelect(n) {
      var s = el('select', {
        cls: 'fc-status',
        'aria-label': t('Status'),
        on: {
          change: function () {
            var v = s.value;
            s.disabled = true;
            api('finishcoat_status', { id: n.id, status: v }).then(function (j) {
              s.disabled = false;
              if (j.ok) {
                n.status = v;
                renderAll();
              } else {
                s.value = n.status;
                alertMsg(j.message);
                load();
              }
            });
          }
        }
      });
      Object.keys(S.statuses).forEach(function (k) {
        s.appendChild(el('option', { value: k, selected: k === n.status, text: S.statuses[k] }));
      });
      return s;
    }
    function delBtn(n) {
      var b = el('button', { cls: 'fc-lnk fc-warn', type: 'button', text: t('Delete') });
      b.addEventListener('click', function () {
        if (b.getAttribute('data-sure') !== '1') {
          b.setAttribute('data-sure', '1');
          b.textContent = t('Delete for good?');
          setTimeout(function () {
            b.removeAttribute('data-sure');
            b.textContent = t('Delete');
          }, 4000);
          return;
        }
        api('finishcoat_delete', { id: n.id }).then(function (j) {
          if (!j.ok) {
            alertMsg(j.message);
            load();
            return;
          } // a stale list (deleted elsewhere) catches up
          var gone = S.notes
            .filter(function (x) {
              return x.id === n.id || x.parent === n.id;
            })
            .map(function (x) {
              return x.id;
            });
          if (S.replyTo && gone.indexOf(S.replyTo) >= 0) cancelReply();
          S.photos = S.photos.filter(function (p) {
            return gone.indexOf(p.note) < 0;
          });
          S.notes = S.notes.filter(function (x) {
            return gone.indexOf(x.id) < 0;
          });
          renderAll();
        });
      });
      return b;
    }
    function alertMsg(x) {
      msg.className = 'fc-msg fc-err';
      msg.textContent = x || t('That did not work.');
    }

    function renderDrawer() {
      var list = pageNotes();
      var open = list.filter(function (n) {
        return n.status !== 'done';
      });
      Array.prototype.forEach.call(filters.children, function (b) {
        b.setAttribute('aria-pressed', String(b.getAttribute('data-f') === S.filter));
      });
      var show =
        S.filter === 'open'
          ? open
          : S.filter === 'done'
            ? list.filter(function (n) {
                return n.status === 'done';
              })
            : list;
      listBox.textContent = '';
      forgetRow.hidden = !GATE.code;
      closedLine.hidden = !closed();
      form.hidden = closed();
      if (closed() && S.replyTo) cancelReply();
      photoUi();
      if (!S.loaded) listBox.appendChild(el('p', { cls: 'fc-empty', text: t('Loading notes...') }));
      else if (!list.length)
        listBox.appendChild(
          el('p', {
            cls: 'fc-empty',
            text: closed()
              ? t('No notes on this page.')
              : t(
                  'No notes on this page yet. Write one below. Use Pin it to a spot if it is about one part of the page.'
                )
          })
        );
      else if (!show.length)
        listBox.appendChild(
          el('p', {
            cls: 'fc-empty',
            text: S.filter === 'open' ? t('Everything on this page is marked done.') : t('Nothing here yet.')
          })
        );
      show.forEach(function (n) {
        listBox.appendChild(noteCard(n));
      });
      tabN.textContent = String(open.length);
      tabDot.hidden = !list.some(function (n) {
        return n.status === 'new';
      });
      tab.setAttribute('aria-label', t('%1$s, %2$d open on this page', LABEL, open.length));
    }

    function renderPins() {
      pinLayer.textContent = '';
      pageNotes().forEach(function (n, i) {
        if (!n.anchor) return;
        pinLayer.appendChild(
          el('button', {
            cls: 'fc-pin fc-s-' + n.status,
            type: 'button',
            'data-id': n.id,
            'aria-label': t('Note %1$d from %2$s', i + 1, n.author || t('Someone')),
            text: String(i + 1),
            on: {
              click: function () {
                openDrawer(n.id);
              }
            }
          })
        );
      });
      if (S.anchor) showGhost();
      placePins();
    }
    function resolve(a) {
      var docW = Math.max(document.documentElement.scrollWidth, 1),
        docH = Math.max(document.documentElement.scrollHeight, 1);
      var x = null;
      try {
        x = a.sel ? document.querySelector(a.sel) : null;
      } catch (e) {
        x = null;
      }
      if (x) {
        var r = x.getBoundingClientRect();
        // off: the spot is on the page but hidden right now (a faded slide or scene, a closed tab), so its pin waits
        if (r.width > 0 && r.height > 0)
          return {
            x: r.left + scrollX + a.rx * r.width,
            y: r.top + scrollY + a.ry * r.height,
            off: !seen(x)
          };
      }
      return { x: a.dx * docW, y: a.dy * docH, off: false };
    }
    function placePins() {
      var docW = document.documentElement.clientWidth;
      Array.prototype.forEach.call(pinLayer.children, function (p) {
        var a = p.__a;
        if (!a) {
          var id = +p.getAttribute('data-id');
          var n = S.notes.filter(function (x) {
            return x.id === id;
          })[0];
          a = n ? n.anchor : null;
        }
        if (!a) return;
        var xy = resolve(a);
        p.style.left = Math.max(16, Math.min(docW - 16, xy.x)) + 'px';
        p.style.top = Math.max(30, xy.y) + 'px';
        p.classList.toggle('fc-off', !!xy.off && !p.classList.contains('fc-ghost'));
      });
    }
    // pages that fade scenes or slides as you scroll: keep pins in step with what is on screen
    var placing = 0;
    window.addEventListener(
      'scroll',
      function () {
        if (!placing)
          placing = requestAnimationFrame(function () {
            placing = 0;
            placePins();
          });
      },
      { passive: true }
    );
    window.addEventListener('resize', placePins);
    window.addEventListener('load', placePins);
    setInterval(placePins, 1500);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(placePins);

    function showPin(id) {
      var p = pinLayer.querySelector('[data-id="' + id + '"]');
      if (!p) return;
      if (matchMedia('(max-width:640px)').matches) closeDrawer(true);
      window.scrollTo({ top: Math.max(0, parseFloat(p.style.top) - innerHeight / 2), behavior: 'smooth' });
      p.classList.remove('fc-pulse');
      void p.offsetWidth;
      p.classList.add('fc-pulse');
    }

    var lastFocus = null;
    function openDrawer(focusId) {
      if (!S.open) lastFocus = document.activeElement;
      S.open = true;
      drawer.classList.add('fc-on');
      if (matchMedia('(max-width:900px)').matches) scrim.classList.add('fc-on');
      tab.setAttribute('aria-expanded', 'true');
      if (!S.shownAt) S.shownAt = Date.now();
      previewBar();
      renderDrawer();
      load();
      emit('panel:open', { focus: focusId || 0 });
      setTimeout(function () {
        if (focusId) {
          var c = drawer.querySelector('#fc-n-' + focusId);
          if (c) {
            c.scrollIntoView({ block: 'center' });
            c.classList.remove('fc-flash');
            void c.offsetWidth;
            c.classList.add('fc-flash');
          }
        }
        if (gateEl) {
          gateEl.__focus();
          return;
        }
        try {
          (focusId ? drawer.querySelector('#fc-n-' + focusId) || heading : heading).focus({
            preventScroll: !!focusId
          });
        } catch (e) {
          /* fine */
        }
      }, 60);
    }
    function closeDrawer(keepFocus) {
      S.open = false;
      drawer.classList.remove('fc-on');
      scrim.classList.remove('fc-on');
      tab.setAttribute('aria-expanded', 'false');
      previewBar();
      if (!keepFocus) {
        try {
          (lastFocus && lastFocus.focus ? lastFocus : tab).focus({ preventScroll: true });
        } catch (e) {
          /* the page element is gone */
        }
      }
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && S.open && !pm) closeDrawer();
    });

    function startReply(n) {
      if (S.suggest) dropSuggest();
      S.replyTo = n.id;
      replyRow.textContent = '';
      replyRow.appendChild(
        el('span', { text: t('Replying to %s', n.author || t('note %d', numberOf(n.id))) })
      );
      replyRow.appendChild(
        el('button', { cls: 'fc-lnk', type: 'button', text: t('Cancel'), on: { click: cancelReply } })
      );
      replyRow.hidden = false;
      photoUi();
      ta.placeholder = t('Write a reply');
      ta.focus();
    }
    function cancelReply() {
      S.replyTo = 0;
      replyRow.hidden = true;
      photoUi();
      ta.placeholder = PLACEHOLDER;
    }

    /* ---------- pin mode ---------- */
    var pm = null;
    function cssPath(x) {
      if (!x || x === document.body || x === document.documentElement) return '';
      var parts = [],
        n = x;
      while (n && n.nodeType === 1 && n !== document.body && n !== document.documentElement) {
        if (n.id && /^[A-Za-z][\w-]*$/.test(n.id) && document.querySelectorAll('#' + n.id).length === 1) {
          parts.unshift('#' + n.id);
          break;
        }
        var tag = n.tagName.toLowerCase();
        if (!/^[a-z][a-z0-9-]*$/.test(tag)) return '';
        var i = 1,
          s = n.previousElementSibling;
        while (s) {
          if (s.tagName === n.tagName) i++;
          s = s.previousElementSibling;
        }
        parts.unshift(tag + ':nth-of-type(' + i + ')');
        n = n.parentElement;
        if (n === document.body) parts.unshift('body');
      }
      return parts.join(' > ');
    }
    /* the whole text of an element, line breaks and runs of spaces collapsed (a heading split over two lines is one label) */
    function textOf(n) {
      return (n.innerText || n.textContent || '').replace(/\s+/g, ' ').trim();
    }
    function shorten(x) {
      return x.length > 60 ? x.slice(0, 57).replace(/\s\S*$/, '') + '…' : x;
    }
    function labelFor(x) {
      var txt = '';
      if (x.tagName === 'IMG') txt = x.getAttribute('alt') || t('a photo');
      var h =
        !txt && x.closest
          ? x.closest('h1,h2,h3,h4,h5,h6,label,button,a,legend,figcaption,th,td,li,p,summary,[aria-label]')
          : null;
      if (h && seen(h)) txt = h.getAttribute('aria-label') || textOf(h);
      if (!txt) {
        /* the first heading a visitor can see: slides, tabs and day/night layers keep hidden ones in the same box */
        var box = x.closest ? x.closest('section,article,fieldset,figure,header,footer,aside,form') : null;
        var hs = box ? box.querySelectorAll('h1,h2,h3,h4,legend') : [],
          hd = null;
        for (var i = 0; i < hs.length && !hd; i++) if (seen(hs[i])) hd = hs[i];
        txt = hd ? textOf(hd) : textOf(x);
      }
      return shorten(txt) || t('this spot');
    }
    function ours(x) {
      return !!(x && x.closest && x.closest('[data-finishcoat],[data-finishcoat-skip]'));
    }
    function under(x, y) {
      var all = document.elementsFromPoint(x, y);
      for (var i = 0; i < all.length; i++) {
        var e = all[i];
        if (ours(e) || e === document.documentElement || e === document.body || !seen(e)) continue;
        return e;
      }
      return null;
    }
    /* a full-page layer for choosing a spot: pin mode (any spot) or photo mode (a photo for Try my photo here) */
    function pickLayer(kind, onPick) {
      if (pm) return;
      closeDrawer(true);
      var hl = el('div', { cls: 'fc-hl', hidden: true });
      var layer = el('div', { cls: 'fc-pm' });
      var say = el('span', {
        text:
          kind === 'photo'
            ? t('Click or tap a photo on the page to try your own photo there.')
            : t('Click the spot your note is about.')
      });
      var bar = el('div', { cls: 'fc-bar fc-top', role: 'status' }, [
        say,
        el('button', {
          type: 'button',
          text: t('Cancel'),
          on: {
            click: function () {
              endPick();
              openDrawer();
            }
          }
        })
      ]);
      ui.r.appendChild(hl);
      ui.r.appendChild(layer);
      ui.r.appendChild(bar);
      pm = { hl: hl, layer: layer, bar: bar };
      var hov = 0;
      layer.addEventListener('mousemove', function (e) {
        var x = e.clientX,
          y = e.clientY;
        if (hov) cancelAnimationFrame(hov);
        hov = requestAnimationFrame(function () {
          hov = 0;
          if (!pm) return;
          var ph = kind === 'photo' ? photoAt(x, y) : null;
          var target = kind === 'photo' ? ph && ph.el : under(x, y);
          if (!target) {
            hl.hidden = true;
            return;
          }
          var r = target.getBoundingClientRect();
          hl.hidden = false;
          hl.style.cssText =
            'left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
        });
      });
      layer.addEventListener('click', function (e) {
        if (kind === 'photo') {
          var ph = photoAt(e.clientX, e.clientY);
          if (!ph) {
            say.textContent = t('That spot has no photo. Click or tap a photo.');
            return;
          }
          endPick();
          onPick(ph, e.clientX, e.clientY);
          return;
        }
        var target = under(e.clientX, e.clientY);
        endPick();
        onPick(target, e.clientX, e.clientY);
      });
      document.addEventListener('keydown', pickKey, true);
    }
    function pickKey(e) {
      if (e.key === 'Escape' && pm) {
        e.preventDefault();
        endPick();
        openDrawer();
      }
    }
    function endPick() {
      if (!pm) return;
      pm.hl.remove();
      pm.layer.remove();
      pm.bar.remove();
      pm = null;
      document.removeEventListener('keydown', pickKey, true);
    }
    function startPin() {
      pickLayer('pin', function (target, x, y) {
        S.anchor = anchorAt(target, x, y, '');
        if (S.suggest) dropSuggest();
        showGhost();
        emit('pin:target', { anchor: S.anchor, el: target || null });
        openDrawer();
        setTimeout(function () {
          ta.focus();
        }, 80);
      });
    }
    function anchorAt(target, cx, cy, lbl) {
      var docW = Math.max(document.documentElement.scrollWidth, 1),
        docH = Math.max(document.documentElement.scrollHeight, 1);
      var a = {
        sel: '',
        rx: 0,
        ry: 0,
        dx: +((cx + scrollX) / docW).toFixed(4),
        dy: +((cy + scrollY) / docH).toFixed(4),
        vw: innerWidth,
        lbl: lbl || t('this spot')
      };
      if (target) {
        var r = target.getBoundingClientRect();
        a.sel = cssPath(target);
        a.rx = r.width ? +Math.max(0, Math.min(1, (cx - r.left) / r.width)).toFixed(4) : 0;
        a.ry = r.height ? +Math.max(0, Math.min(1, (cy - r.top) / r.height)).toFixed(4) : 0;
        if (!lbl) a.lbl = labelFor(target);
      }
      return a;
    }
    function showGhost() {
      var g = pinLayer.querySelector('.fc-ghost');
      if (g) g.remove();
      pinnedRow.textContent = '';
      if (!S.anchor) {
        pinnedRow.hidden = true;
        pinBtn.textContent = t('Pin it to a spot');
        return;
      }
      g = el('button', {
        cls: 'fc-pin fc-ghost',
        type: 'button',
        'aria-label': t('Your new note goes here'),
        text: '+',
        on: {
          click: function () {
            openDrawer();
          }
        }
      });
      g.__a = S.anchor;
      pinLayer.appendChild(g);
      placePins();
      pinnedRow.appendChild(
        el('span', {
          text: S.suggest ? t('Pinned to the photo “%s”', S.anchor.lbl) : t('Pinned to “%s”', S.anchor.lbl)
        })
      );
      pinnedRow.appendChild(
        el('button', {
          cls: 'fc-lnk',
          type: 'button',
          text: S.suggest ? t('Do not suggest it') : t('Remove pin'),
          on: {
            click: function () {
              if (S.suggest) dropSuggest();
              else {
                S.anchor = null;
                showGhost();
              }
            }
          }
        })
      );
      pinnedRow.hidden = false;
      pinBtn.textContent = t('Move the pin');
    }

    /* ---------- photos: choosing files ---------- */
    function shortName(f) {
      var n = String((f && f.name) || 'photo');
      return '“' + (n.length > 40 ? n.slice(0, 26) + '…' + n.slice(-10) : n) + '”';
    }
    function fileProblem(f) {
      var ty = String(f.type || '').toLowerCase();
      var ok =
        /^image\/(jpe?g|pjpeg|png|webp|heic|heif)$/.test(ty) ||
        (!ty && /\.(jpe?g|png|webp|heic|heif)$/i.test(f.name || ''));
      if (!ok) return t('Only photos (JPEG, PNG, WebP) can be sent. %s was left out.', shortName(f));
      if (f.size > MAX_MB * 1048576)
        return t('%1$s is too big to send. The limit is %2$d MB a photo.', shortName(f), MAX_MB);
      if (!f.size) return t('%s is empty. Try another photo.', shortName(f));
      return '';
    }
    function picked(inp) {
      var list = Array.prototype.slice.call(inp.files || []);
      inp.value = '';
      if (!list.length || S.busy) return;
      msg.className = 'fc-msg';
      msg.textContent = '';
      var bad = '';
      var ok = list.filter(function (f) {
        var b = fileProblem(f);
        if (b && !bad) bad = b;
        return !b;
      });
      var room = MAX_FILES - S.files.length;
      if (ok.length > room) {
        ok = ok.slice(0, Math.max(0, room));
        if (!bad) bad = t('You can send up to %d photos with a note.', MAX_FILES);
      }
      ok.forEach(function (f) {
        var u = '';
        try {
          u = URL.createObjectURL(f);
        } catch (e) {
          u = '';
        }
        S.files.push({ file: f, url: u });
      });
      renderFiles();
      if (bad) alertMsg(bad);
    }
    function removeFile(f) {
      if (S.busy) return;
      if (S.suggest && f === S.suggest.item) {
        dropSuggest();
        return;
      }
      if (f.url) URL.revokeObjectURL(f.url);
      S.files = S.files.filter(function (x) {
        return x !== f;
      });
      renderFiles();
      (S.files.length ? thumbs.querySelector('.fc-rm') : addBtn).focus();
    }
    function clearFiles() {
      S.files.forEach(function (f) {
        if (f.url && (!S.suggest || f !== S.suggest.item)) URL.revokeObjectURL(f.url);
      });
      S.files = [];
      renderFiles();
    }
    function renderFiles() {
      thumbs.textContent = '';
      var n = S.files.length;
      fileNote.textContent = S.suggest
        ? t('Your photo for “%s”. Only you see it on the page. Send to suggest it.', S.suggest.target.lbl)
        : n === 1
          ? t('1 photo to send')
          : t('%d photos to send', n);
      if (S.suggest && S.suggest.beforeUrl)
        thumbs.appendChild(
          el('li', { cls: 'fc-fth', title: t('Before') }, [
            el('img', { alt: t('The photo on the page now'), src: S.suggest.beforeUrl })
          ])
        );
      S.files.forEach(function (f) {
        var nm = String(f.file.name || 'photo');
        var ext = (/\.([a-z0-9]{2,5})$/i.exec(nm) || [0, 'photo'])[1];
        var box = el('li', { cls: 'fc-fth', title: nm });
        var im = f.url ? el('img', { alt: nm, src: f.url }) : el('span', { cls: 'fc-ext', text: ext });
        /* HEIC does not preview on most desktops: show the file type instead */
        if (f.url)
          im.addEventListener('error', function () {
            if (im.parentNode) im.parentNode.replaceChild(el('span', { cls: 'fc-ext', text: ext }), im);
          });
        box.appendChild(im);
        box.appendChild(
          el('button', {
            cls: 'fc-rm',
            type: 'button',
            'aria-label': t('Remove %s', nm),
            text: '×',
            disabled: S.busy,
            on: {
              click: function () {
                removeFile(f);
              }
            }
          })
        );
        thumbs.appendChild(box);
      });
      photoUi();
    }
    function photoUi() {
      addBtn.hidden = !!S.suggest || S.files.length >= MAX_FILES;
      tryBtn.hidden = !!S.replyTo || closed();
      pinBtn.hidden = !!S.replyTo;
      photoBox.hidden = !S.files.length;
    }
    function busy(v) {
      S.busy = v;
      sendBtn.disabled = v;
      sendBtn.textContent = v ? t('Sending...') : t('Send');
      addBtn.disabled = v;
      pinBtn.disabled = v;
      tryBtn.disabled = v;
      Array.prototype.forEach.call(thumbs.querySelectorAll('.fc-rm'), function (b) {
        b.disabled = v;
      });
    }

    /* ---------- send: a note or reply, with photos when there are any ---------- */
    function send() {
      if (S.busy) return;
      /* the server drops notes sent faster than its minimum fill time, so a quick person just waits a moment */
      var wait = 1700 - (Date.now() - (S.shownAt || Date.now()));
      if (S.shownAt && wait > 0 && !S.studio) {
        busy(true);
        setTimeout(function () {
          busy(false);
          send();
        }, wait);
        return;
      }
      var body = ta.value.trim();
      var name = nameIn.value.trim();
      msg.className = 'fc-msg';
      msg.textContent = '';
      var files = S.files.slice();
      if (!body && !files.length) {
        alertMsg(t('Write a note first.'));
        ta.focus();
        return;
      }
      if (!S.studio && !name) {
        alertMsg(t('Add your name so we know who it is from.'));
        nameIn.focus();
        return;
      }
      if (files.length && !consent.checked) {
        alertMsg(t('Tick the box to confirm you can share these photos.'));
        consent.focus();
        return;
      }
      var sug = S.suggest;
      var data = {
        action: 'finishcoat_post',
        project: PROJECT,
        page: path,
        title: pageTitle,
        body: body,
        author: name,
        parent: S.replyTo || '',
        anchor: !S.replyTo && S.anchor ? JSON.stringify(S.anchor) : '',
        target: sug && !S.replyTo ? JSON.stringify(sug.target) : '',
        consent: files.length ? '1' : '',
        website: hp.value,
        t: String(Date.now() - (S.shownAt || Date.now()))
      };
      if (GATE.code) data.code = GATE.code;
      data.nonce = GATE.studio || GATE.pub;
      busy(true);
      var fd = new FormData();
      Object.keys(data).forEach(function (k) {
        fd.append(k, data[k]);
      });
      files.forEach(function (f) {
        fd.append('photos[]', f.file, f.file.name || 'photo.jpg');
      });
      if (sug && sug.before && !S.replyTo) fd.append('before', sug.before, 'before.jpg');

      var total = 0;
      files.forEach(function (f) {
        total += f.file.size;
      });
      if (files.length) {
        prog.hidden = false;
        progBar.style.width = '0';
        msg.textContent = t('Uploading...');
      }
      var xhr = new XMLHttpRequest();
      xhr.open('POST', AJAX);
      if (files.length && xhr.upload)
        xhr.upload.addEventListener('progress', function (e) {
          if (!e.lengthComputable || !e.total) return;
          var pct = Math.round((e.loaded / e.total) * 100);
          progBar.style.width = pct + '%';
          msg.className = 'fc-msg';
          msg.textContent = pct >= 100 ? t('Saving...') : t('Uploading... %d%%', pct);
        });
      xhr.onload = function () {
        var j = null;
        try {
          j = JSON.parse(xhr.responseText);
        } catch (e) {
          j = null;
        }
        if (!j)
          j = {
            ok: false,
            message:
              xhr.status === 413
                ? t('Those photos are too big to send at once. Try fewer.')
                : t('The notes service did not answer. Try again in a minute.')
          };
        if (j.code_required && GATE.hit) GATE.hit(j);
        done(j);
      };
      xhr.onerror = function () {
        done({ ok: false, message: t('No connection. Check your internet and try again.') });
      };
      xhr.send(fd);

      function done(j) {
        busy(false);
        prog.hidden = true;
        if (!j || !j.ok) {
          alertMsg((j && j.message) || t('That did not work.'));
          // replying to a note someone deleted meanwhile: catch up, and drop the reply if its note is gone (the text stays)
          if (data.parent)
            load().then(function () {
              if (
                S.replyTo &&
                !S.notes.some(function (x) {
                  return x.id === S.replyTo;
                })
              )
                cancelReply();
            });
          return;
        }
        if (!S.studio) {
          S.name = name;
          store('finishcoat-name', name);
        }
        var wasReply = !!data.parent;
        if (j.note) S.notes.unshift(j.note);
        var got = Array.isArray(j.photos) ? j.photos : [];
        if (got.length) {
          var ids = got.map(function (p) {
            return p.id;
          });
          S.photos = S.photos
            .filter(function (p) {
              return ids.indexOf(p.id) < 0;
            })
            .concat(got);
        }
        if (ta.value.trim() === body) ta.value = '';
        if (S.replyTo === (+data.parent || 0) && wasReply) cancelReply();
        files.forEach(function (f) {
          if (f.url)
            setTimeout(function () {
              URL.revokeObjectURL(f.url);
            }, 60000);
        });
        S.files = [];
        consent.checked = false;
        if (sug) {
          putBack();
          S.suggest = null;
        }
        if (!wasReply) {
          S.anchor = null;
          showGhost();
        }
        if (S.filter === 'done') S.filter = 'open';
        renderFiles();
        renderAll();
        msg.className = 'fc-msg fc-ok';
        msg.textContent = wasReply
          ? t('Reply sent.')
          : sug
            ? t('Thanks. Your photo suggestion is in, pinned to that spot.')
            : t('Thanks. Your note is in and the site owner has been told.');
        if (j.note) {
          var c = drawer.querySelector('#fc-n-' + (j.note.parent || j.note.id));
          if (c) c.scrollIntoView({ block: 'nearest' });
        }
        emit('note:sent', { note: j.note, photos: got });
      }
    }

    /* ---------- photos on the page: Try my photo here (only this browser sees it) ---------- */
    var fpCache = typeof WeakMap === 'function' ? new WeakMap() : null;
    function fpOf(e, s) {
      if (!s) return '';
      var c = fpCache && fpCache.get(e);
      if (c && c.s === s) return c.fp;
      var fp = fnv(s);
      if (fpCache) fpCache.set(e, { s: s, fp: fp });
      return fp;
    }
    /* split a computed background list at top-level commas (data URIs carry commas inside url("...")) */
    function layers(v) {
      var out = [],
        depth = 0,
        from = 0;
      for (var i = 0; i < v.length; i++) {
        var c = v.charCodeAt(i);
        if (c === 34 || c === 39) {
          var j = v.indexOf(v.charAt(i), i + 1);
          while (j > 0 && v.charCodeAt(j - 1) === 92) j = v.indexOf(v.charAt(i), j + 1);
          if (j < 0) break;
          i = j;
        } else if (c === 40) depth++;
        else if (c === 41) depth--;
        else if (c === 44 && depth === 0) {
          out.push(v.slice(from, i).trim());
          from = i + 1;
        }
      }
      out.push(v.slice(from).trim());
      return out;
    }
    function urlOf(layer) {
      var s = layer.replace(/^url\(\s*/, '').replace(/\s*\)$/, '');
      var q = s.charAt(0);
      if ((q === '"' || q === "'") && s.charAt(s.length - 1) === q)
        s = s.slice(1, -1).replace(/\\(.)/g, '$1');
      return s;
    }
    var NOBG = /^(INPUT|SELECT|TEXTAREA|BUTTON|OPTION|HTML|BODY)$/;
    /* the first url() layer that is a photo (sized or not tiled); paper textures that tile are skipped */
    function bgOf(e) {
      if (!e || e.nodeType !== 1 || NOBG.test(e.tagName)) return null;
      var cs = getComputedStyle(e);
      var bi = cs.backgroundImage;
      if (!bi || bi.indexOf('url(') < 0) return null;
      var ls = layers(bi),
        sz = layers(cs.backgroundSize || 'auto'),
        rp = layers(cs.backgroundRepeat || 'repeat');
      for (var i = 0; i < ls.length; i++) {
        if (ls[i].indexOf('url(') !== 0) continue;
        var s = sz[i % sz.length] || 'auto',
          r = rp[i % rp.length] || 'repeat';
        if (!/cover|contain|%/.test(s) && !/no-repeat|space|round/.test(r)) continue;
        return { layer: i, layers: ls, url: urlOf(ls[i]) };
      }
      return null;
    }
    function seen(e) {
      if (e.checkVisibility) return e.checkVisibility({ opacityProperty: true, visibilityProperty: true });
      var r = e.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    }
    function imgOf(e) {
      if (e.tagName === 'IMG') return e;
      if (e.tagName === 'PICTURE') return e.querySelector('img');
      var pic = e.closest ? e.closest('picture') : null;
      return pic ? pic.querySelector('img') : null;
    }
    function photoOf(e) {
      var im = imgOf(e);
      if (im) {
        var r = im.getBoundingClientRect();
        return r.width >= 24 && r.height >= 24 && (im.currentSrc || im.src) && seen(im)
          ? { el: im, kind: 'img' }
          : null;
      }
      for (var d = 0, n = e; n && d < 4; d++, n = n.parentElement) {
        if (NOBG.test(n.tagName)) break;
        if (bgOf(n)) {
          var b = n.getBoundingClientRect();
          if (b.width >= 40 && b.height >= 40 && seen(n)) return { el: n, kind: 'bg' };
        }
      }
      return null;
    }
    function ownText(e) {
      for (var c = e.firstChild; c; c = c.nextSibling)
        if (c.nodeType === 3 && /\S/.test(c.nodeValue)) return true;
      return false;
    }
    /* look through plain overlays (veils, gradients, frames) to the photo below; stop at text or controls */
    function photoAt(x, y) {
      var all = document.elementsFromPoint(x, y);
      for (var i = 0; i < all.length; i++) {
        var e = all[i];
        if (ours(e)) continue;
        if (e === document.documentElement || e === document.body) break;
        var p = photoOf(e);
        if (p) return p;
        if (
          ownText(e) ||
          /^(A|BUTTON|INPUT|SELECT|TEXTAREA|LABEL|SUMMARY|VIDEO|CANVAS|IFRAME)$/.test(e.tagName)
        )
          break;
      }
      return null;
    }
    function photoLabel(e, kind) {
      var x = (e.getAttribute('alt') || e.getAttribute('aria-label') || e.getAttribute('title') || '')
        .replace(/\s+/g, ' ')
        .trim();
      if (!x) {
        var fig = e.closest('figure');
        var cap = fig ? fig.querySelector('figcaption') : null;
        if (cap) x = textOf(cap);
      }
      if (!x) {
        var own = kind === 'bg' ? e.querySelector('h1,h2,h3,h4') : null;
        var box = e.closest('section,article,header,aside,li,figure,form');
        var hd = own || (box ? box.querySelector('h1,h2,h3,h4,legend') : null);
        x = hd ? textOf(hd) : '';
      }
      if (!x)
        x =
          kind === 'bg'
            ? t('Background photo')
            : t('Photo %d', Array.prototype.indexOf.call(document.images, e) + 1);
      return shorten(x);
    }
    function origFp(e, kind) {
      if (e.__fc) return e.__fc.fp;
      if (kind === 'bg') {
        var b = bgOf(e);
        return b ? fpOf(e, b.url) : '';
      }
      return fpOf(e, e.currentSrc || e.src || '');
    }
    function keepProp(e, k) {
      return [e.style.getPropertyValue(k), e.style.getPropertyPriority(k)];
    }
    function putProp(e, k, v) {
      if (v && v[0]) e.style.setProperty(k, v[0], v[1]);
      else e.style.removeProperty(k);
    }
    function putAttr(e, k, v) {
      if (v == null) e.removeAttribute(k);
      else if (e.getAttribute(k) !== v) e.setAttribute(k, v);
    }
    /* show a local photo in place, cropped like the original: the element keeps the box the original had */
    function swapTo(e, kind, url) {
      var o = e.__fc;
      if (!o) {
        o = { kind: kind, fp: origFp(e, kind), styled: e.hasAttribute('style') };
        if (kind === 'img') {
          o.src = e.getAttribute('src');
          o.srcset = e.getAttribute('srcset');
          o.sizes = e.getAttribute('sizes');
          o.ar = keepProp(e, 'aspect-ratio');
          o.of = keepProp(e, 'object-fit');
          o.w = e.naturalWidth || +e.getAttribute('width') || 0;
          o.h = e.naturalHeight || +e.getAttribute('height') || 0;
          o.fill = getComputedStyle(e).objectFit === 'fill';
          var pic = e.parentElement && e.parentElement.tagName === 'PICTURE' ? e.parentElement : null;
          o.sources = pic
            ? Array.prototype.map.call(pic.querySelectorAll('source'), function (s) {
                return [s, s.getAttribute('srcset')];
              })
            : [];
        } else {
          var b = bgOf(e);
          o.layers = b ? b.layers : ['none'];
          o.layer = b ? b.layer : 0;
          o.bg = keepProp(e, 'background-image');
        }
        e.__fc = o;
      }
      o.url = url;
      if (kind === 'img') {
        e.setAttribute('src', url);
        e.removeAttribute('srcset');
        e.removeAttribute('sizes');
        o.sources.forEach(function (s) {
          s[0].setAttribute('srcset', '');
        });
        if (o.w && o.h && !o.ar[0]) e.style.setProperty('aspect-ratio', o.w + ' / ' + o.h);
        if (o.fill && !o.of[0]) e.style.setProperty('object-fit', 'cover');
      } else {
        var ls = o.layers.slice();
        ls[o.layer] = 'url("' + String(url).replace(/["\\\n]/g, '\\$&') + '")';
        e.style.setProperty('background-image', ls.join(', '), 'important');
      }
      e.setAttribute('data-finishcoat-preview', '');
    }
    function restore(e) {
      var o = e && e.__fc;
      if (!o) return;
      if (o.kind === 'img') {
        putAttr(e, 'src', o.src);
        putAttr(e, 'srcset', o.srcset);
        putAttr(e, 'sizes', o.sizes);
        o.sources.forEach(function (s) {
          putAttr(s[0], 'srcset', s[1]);
        });
        putProp(e, 'aspect-ratio', o.ar);
        putProp(e, 'object-fit', o.of);
      } else putProp(e, 'background-image', o.bg);
      if (!o.styled && e.getAttribute('style') === '') e.removeAttribute('style');
      e.removeAttribute('data-finishcoat-preview');
      e.__fc = null;
    }
    /* a small picture of the original, made in this browser, for the before-and-after pair. Only photos from this
       site can be drawn; for any other photo the pair shows the new photo alone. */
    function captureBefore(e, kind, cb) {
      var src = kind === 'img' ? e.currentSrc || e.src || '' : (bgOf(e) || {}).url || '';
      if (!sameOrigin(src) || !window.HTMLCanvasElement) {
        cb(null);
        return;
      }
      function draw(im) {
        try {
          var w = im.naturalWidth,
            h = im.naturalHeight;
          if (!w || !h) {
            cb(null);
            return;
          }
          var k = Math.min(1, 480 / Math.max(w, h));
          var cv = document.createElement('canvas');
          cv.width = Math.max(1, Math.round(w * k));
          cv.height = Math.max(1, Math.round(h * k));
          cv.getContext('2d').drawImage(im, 0, 0, cv.width, cv.height);
          cv.toBlob(
            function (b) {
              cb(b || null);
            },
            'image/jpeg',
            0.85
          );
        } catch (x) {
          cb(null);
        }
      }
      if (kind === 'img' && e.complete && e.naturalWidth) {
        draw(e);
        return;
      }
      var im = new Image();
      im.onload = function () {
        draw(im);
      };
      im.onerror = function () {
        cb(null);
      };
      im.src = src;
    }

    /* one spot at a time: { el, kind, target, item: { file, url }, before: Blob|null, beforeUrl } */
    var PV = null;
    var tryFor = null;
    var pvBar = el('div', {
      cls: 'fc-bar fc-bottom',
      role: 'region',
      'aria-label': t('Your photo on the page'),
      hidden: true
    });
    ui.r.appendChild(pvBar);
    function startPick() {
      pickLayer('photo', function (ph) {
        tryFor = ph;
        tryIn.click();
      });
    }
    function tried(inp) {
      var f = (inp.files || [])[0];
      inp.value = '';
      var ph = tryFor;
      tryFor = null;
      if (!f || !ph || !ph.el.isConnected) return;
      var bad = fileProblem(f);
      if (bad) {
        alertMsg(bad);
        openDrawer();
        return;
      }
      var url = '';
      try {
        url = URL.createObjectURL(f);
      } catch (e) {
        url = '';
      }
      if (!url) return;
      if (PV && PV.el !== ph.el) putBack();
      if (S.suggest) dropSuggest(true);
      var old = PV && PV.el === ph.el ? PV : null;
      var r = ph.el.getBoundingClientRect();
      var lbl = photoLabel(ph.el, ph.kind);
      var next = {
        el: ph.el,
        kind: ph.kind,
        item: { file: f, url: url },
        target: { sel: cssPath(ph.el), fp: origFp(ph.el, ph.kind), lbl: lbl, kind: ph.kind },
        anchor: anchorAt(ph.el, r.left + r.width / 2, r.top + r.height / 2, lbl),
        before: old ? old.before : null,
        beforeUrl: old ? old.beforeUrl : ''
      };
      if (old && old.item.url) URL.revokeObjectURL(old.item.url);
      PV = next;
      var go = function () {
        swapTo(ph.el, ph.kind, url);
        previewBar();
      };
      if (old) {
        go();
        return;
      }
      captureBefore(ph.el, ph.kind, function (b) {
        if (PV !== next) return;
        next.before = b;
        try {
          next.beforeUrl = b ? URL.createObjectURL(b) : '';
        } catch (e) {
          next.beforeUrl = '';
        }
        go();
      });
    }
    function previewBar() {
      pvBar.textContent = '';
      pvBar.hidden = !PV || S.open || !!pm;
      if (pvBar.hidden) return;
      pvBar.appendChild(
        el('span', { text: t('Only you can see this photo here. Nothing is sent until you suggest it.') })
      );
      pvBar.appendChild(
        el('button', {
          cls: 'fc-main',
          type: 'button',
          text: t('Suggest this photo'),
          on: { click: suggest }
        })
      );
      pvBar.appendChild(
        el('button', {
          type: 'button',
          text: t('Try another'),
          on: {
            click: function () {
              tryFor = { el: PV.el, kind: PV.kind };
              tryIn.click();
            }
          }
        })
      );
      pvBar.appendChild(
        el('button', {
          type: 'button',
          text: t('Put the original back'),
          on: {
            click: function () {
              if (S.suggest) dropSuggest();
              putBack();
            }
          }
        })
      );
    }
    function putBack() {
      if (!PV) return;
      restore(PV.el);
      if (PV.item.url && !(S.suggest && S.suggest.item === PV.item)) URL.revokeObjectURL(PV.item.url);
      if (PV.beforeUrl) URL.revokeObjectURL(PV.beforeUrl);
      PV = null;
      previewBar();
    }
    /* Suggest this photo: a note pinned to the spot, with this photo and the before-and-after pair */
    function suggest() {
      if (!PV) return;
      if (S.replyTo) cancelReply();
      clearFiles();
      S.suggest = PV;
      S.files = [PV.item];
      S.anchor = PV.anchor;
      showGhost();
      renderFiles();
      ta.placeholder = t('Add a note about this photo (optional)');
      openDrawer();
      setTimeout(function () {
        ta.focus();
      }, 80);
    }
    /* stop suggesting; the photo stays tried on the page */
    function dropSuggest(keepPreview) {
      if (!S.suggest) return;
      S.suggest = null;
      S.files = [];
      S.anchor = null;
      ta.placeholder = PLACEHOLDER;
      showGhost();
      renderFiles();
      if (!keepPreview) previewBar();
    }

    /* a Try my photo here button on the photo under the mouse (mouse and trackpad only) */
    (function hoverButton() {
      if (!matchMedia('(hover: hover) and (pointer: fine)').matches) return;
      var cur = null;
      var btn = el('button', { cls: 'fc-try', type: 'button', hidden: true }, [
        photoIcon(),
        el('span', { text: t('Try my photo here') })
      ]);
      pinsUi.r.appendChild(btn);
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!cur) return;
        tryFor = cur;
        btn.hidden = true;
        tryIn.click();
      });
      var raf = 0,
        lx = 0,
        ly = 0;
      function update() {
        raf = 0;
        if (pm || closed() || (S.open && innerWidth <= 900)) {
          btn.hidden = true;
          cur = null;
          return;
        }
        var ph = photoAt(lx, ly);
        if (ph) {
          var r = ph.el.getBoundingClientRect();
          if (r.width >= 140 && r.height >= 90) {
            cur = ph;
            btn.hidden = false;
            var bw = btn.offsetWidth || 170;
            btn.style.left = Math.min(r.right, innerWidth - 70) - bw - 14 + scrollX + 'px';
            btn.style.top = Math.max(r.top, 8) + 14 + scrollY + 'px';
            return;
          }
        }
        btn.hidden = true;
        cur = null;
      }
      document.addEventListener(
        'mousemove',
        function (e) {
          if (ours(e.target) && e.target !== pinsUi.host) return;
          lx = e.clientX;
          ly = e.clientY;
          if (!raf) raf = requestAnimationFrame(update);
        },
        { passive: true }
      );
      window.addEventListener(
        'scroll',
        function () {
          if (!raf) raf = requestAnimationFrame(update);
        },
        { passive: true }
      );
      document.addEventListener('mouseleave', function () {
        btn.hidden = true;
        cur = null;
      });
    })();

    /* ---------- the board: a [finishcoat board="yes"] element on the page ---------- */
    var board = null;
    function chip(label, isOn, fn) {
      return el('button', {
        cls: 'fc-chip',
        type: 'button',
        'aria-pressed': String(isOn),
        text: label,
        on: { click: fn }
      });
    }
    function buildBoard() {
      var sh = shadowHost('finishcoat-board', boardSlot);
      sh.host.style.cssText = 'display:block;width:100%;max-width:100%;';
      var counts = el('div', { cls: 'fc-counts' });
      var pageF = el('div', { cls: 'fc-filters', role: 'group', 'aria-label': t('Page') });
      var statF = el('div', { cls: 'fc-filters', role: 'group', 'aria-label': t('Status') });
      var grid = el('div', { cls: 'fc-grid' });
      var more = el('div', { cls: 'fc-more' });
      sh.r.appendChild(
        el('section', { cls: 'fc-board', 'aria-labelledby': 'fc-bh' }, [
          el('div', { cls: 'fc-bhd' }, [
            el('div', null, [
              el('h2', { id: 'fc-bh', text: t('%s board', LABEL) }),
              el('p', {
                cls: 'fc-lede',
                text: t(
                  'Every note left on this site, in one place. Use the %s tab on the right edge of any page to add one.',
                  LABEL
                )
              })
            ]),
            counts
          ]),
          el('div', { cls: 'fc-tools' }, [statF, pageF]),
          grid,
          more
        ])
      );
      board = { counts: counts, pageF: pageF, statF: statF, grid: grid, more: more };
    }
    function renderBoard() {
      if (!board) return;
      var top = S.notes.filter(function (n) {
        return !n.parent;
      });
      var c = {};
      Object.keys(S.statuses).forEach(function (k) {
        c[k] = 0;
      });
      top.forEach(function (n) {
        if (c[n.status] != null) c[n.status]++;
      });
      board.counts.textContent = '';
      /* every status, so the row adds up to All */
      [[t('All'), top.length]]
        .concat(
          Object.keys(S.statuses).map(function (k) {
            return [S.statuses[k], c[k]];
          })
        )
        .forEach(function (x) {
          board.counts.appendChild(el('div', null, [el('b', { text: String(x[1]) }), x[0]]));
        });
      var pages = {};
      top.forEach(function (n) {
        if (!pages[n.page]) pages[n.page] = { t: n.title || n.page, n: 0 };
        pages[n.page].n++;
      });
      if (S.boardPage !== 'all' && !pages[S.boardPage]) S.boardPage = 'all';
      board.pageF.textContent = '';
      var keys = Object.keys(pages).sort();
      if (keys.length > 1) {
        board.pageF.appendChild(
          chip(t('All pages'), S.boardPage === 'all', function () {
            S.boardPage = 'all';
            S.boardLimit = 24;
            renderBoard();
          })
        );
        keys.forEach(function (p) {
          board.pageF.appendChild(
            chip(pages[p].t + ' (' + pages[p].n + ')', S.boardPage === p, function () {
              S.boardPage = p;
              S.boardLimit = 24;
              renderBoard();
            })
          );
        });
      }
      board.statF.textContent = '';
      [['all', t('Everything')]]
        .concat(
          Object.keys(S.statuses).map(function (k) {
            return [k, S.statuses[k]];
          })
        )
        .concat([['photos', t('Photos')]])
        .forEach(function (s) {
          board.statF.appendChild(
            chip(s[1], S.boardStatus === s[0], function () {
              S.boardStatus = s[0];
              S.boardLimit = 24;
              renderBoard();
            })
          );
        });
      var show = top.filter(function (n) {
        return (
          (S.boardPage === 'all' || n.page === S.boardPage) &&
          (S.boardStatus === 'all' ||
            (S.boardStatus === 'photos'
              ? notePhotos(n).length > 0 ||
                repliesOf(n.id).some(function (r) {
                  return notePhotos(r).length > 0;
                })
              : n.status === S.boardStatus))
        );
      });
      board.grid.textContent = '';
      board.more.textContent = '';
      if (!S.loaded) {
        board.grid.appendChild(el('p', { cls: 'fc-empty', text: t('Loading notes...') }));
        return;
      }
      if (!top.length) {
        board.grid.appendChild(el('p', { cls: 'fc-empty', text: t('No suggestions yet.') }));
        return;
      }
      if (!show.length) {
        board.grid.appendChild(el('p', { cls: 'fc-empty', text: t('Nothing matches those filters.') }));
        return;
      }
      show.slice(0, S.boardLimit).forEach(function (n) {
        board.grid.appendChild(noteCard(n, { board: true }));
      });
      if (show.length > S.boardLimit)
        board.more.appendChild(
          el('button', {
            cls: 'fc-btn2',
            type: 'button',
            text: t('Show %d more', Math.min(24, show.length - S.boardLimit)),
            on: {
              click: function () {
                S.boardLimit += 24;
                renderBoard();
              }
            }
          })
        );
    }

    function renderAll() {
      renderDrawer();
      renderPins();
      renderBoard();
    }

    /* ---------- the code stopped working while the page was open (revoked or regenerated) ---------- */
    var gateEl = null;
    GATE.hit = function (j) {
      if (gateEl || S.studio) return;
      if (GATE.code && !j.limited) {
        store(CODE_KEY, null);
        GATE.code = '';
      }
      gateEl = gateBox(j.limited ? j.message : '', function (k) {
        gateEl.remove();
        gateEl = null;
        dBody.hidden = false;
        dFt.hidden = false;
        apply(k);
        renderAll();
        try {
          heading.focus({ preventScroll: true });
        } catch (e) {
          /* fine */
        }
      });
      dBody.hidden = true;
      dFt.hidden = true;
      drawer.appendChild(gateEl);
      if (S.open) setTimeout(gateEl.__focus, 60);
    };

    /* ---------- load ---------- */
    var loading = null;
    function load() {
      if (loading) return loading;
      loading = api('finishcoat_list', {}).then(function (j) {
        loading = null;
        if (!j.ok) return;
        apply(j);
        renderAll();
      });
      return loading;
    }
    function apply(j) {
      S.notes = Array.isArray(j.notes) ? j.notes : [];
      S.photos = Array.isArray(j.photos) ? j.photos : [];
      if (j.statuses) S.statuses = j.statuses;
      if (j.nonce) GATE.pub = j.nonce;
      if (j.project) {
        P.active = j.project.active;
        P.photo_max_mb = j.project.photo_max_mb;
        P.photo_max_files = j.project.photo_max_files;
        limits();
      }
      S.loaded = true;
    }

    if (boardSlot) buildBoard();
    apply(boot);
    renderAll();

    /* editors: reply as themselves, set a status, delete */
    function studioOn(s) {
      S.studio = true;
      GATE.studio = s.nonce;
      nameWrap.hidden = true;
      whoTag.textContent = t('Studio mode: replying as %s', s.name || t('the site owner'));
      whoTag.hidden = false;
      renderAll();
    }
    if (GATE.studio || loggedIn())
      api('finishcoat_session', {}).then(function (s) {
        if (s && s.studio && s.nonce) studioOn(s);
      });

    /* ?finishcoat_note=ID opens that note (links in the emails and on the board) */
    (function deepLink() {
      var m = /[?&]finishcoat_note=(\d+)/.exec(location.search);
      if (!m) return;
      var id = +m[1];
      var n = S.notes.filter(function (x) {
        return x.id === id;
      })[0];
      if (!n) return;
      if (n.parent) id = n.parent;
      S.filter = 'all';
      openDrawer(id);
      if (!matchMedia('(max-width:640px)').matches)
        setTimeout(function () {
          showPin(id);
        }, 300);
    })();

    /* first visit: point at the tab for a few seconds */
    (function hint() {
      if (store('finishcoat-hint') || S.open || boardSlot) return;
      setTimeout(function () {
        if (S.open || pm) return;
        store('finishcoat-hint', '1');
        var h = el('div', {
          cls: 'fc-hint',
          role: 'note',
          text: t('Notes or photos? Leave them here.'),
          on: {
            click: function () {
              h.remove();
              openDrawer();
            }
          }
        });
        ui.r.appendChild(h);
        setTimeout(function () {
          if (h.parentNode) h.remove();
        }, 9000);
        tab.addEventListener('click', function () {
          if (h.parentNode) h.remove();
        });
      }, 1200);
    })();

    if (boot.__open || URL_CODE.code) openDrawer();
    setInterval(function () {
      if (!document.hidden && (S.open || board)) load();
    }, 90000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && S.loaded) load();
    });

    /* ---------- add-on modules ---------- */
    var API = {
      version: C.ver || '1.0.0',
      project: PROJECT,
      panel: { root: ui.root, drawer: drawer, form: form, open: openDrawer, close: closeDrawer },
      pins: { layer: pinLayer, root: pinsUi.root },
      findPhoto: photoAt,
      findSpot: under,
      anchorAt: anchorAt,
      api: api,
      upload: function (fd) {
        if (GATE.code && !fd.has('code')) fd.append('code', GATE.code);
        if (!fd.has('nonce')) fd.append('nonce', GATE.studio || GATE.pub);
        return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) {
            return r.json();
          })
          .catch(function () {
            return { ok: false };
          });
      },
      on: on,
      off: off,
      emit: emit,
      state: function () {
        return { notes: S.notes, photos: S.photos, studio: S.studio, path: path };
      },
      render: renderAll,
      t: t,
      el: el
    };
    function runModule(item) {
      try {
        if (typeof item[1] === 'function') item[1](API);
      } catch (e) {
        /* a module failed; the page goes on */
      }
    }
    FC.queue.splice(0).forEach(runModule);
    FC.register = function (name, module) {
      runModule([name, module]);
    };
  }
})();
