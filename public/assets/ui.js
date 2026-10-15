/* Auth-UI: Theme-Umschalter, Mobile-Navigation, Header-Effekt, Scroll-Fortschritt, Reveal-on-Scroll. Kein Inline-Code (CSP). */
(function () {
  'use strict';
  var root = document.documentElement;
  root.classList.add('js');

  // Theme: dark (Standard) | light | system. Wird sofort angewendet (Skript im <head>), damit nichts flackert.
  function resolve(pref) {
    if (pref === 'system') {
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }
    return pref === 'light' ? 'light' : 'dark';
  }
  function applyTheme() { root.setAttribute('data-theme', resolve(root.getAttribute('data-theme-pref'))); }
  applyTheme();
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: light)');
    var onChange = function () { if (root.getAttribute('data-theme-pref') === 'system') { applyTheme(); } };
    if (mq.addEventListener) { mq.addEventListener('change', onChange); }
  }
  function saveTheme(v) {
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'vtheme=' + v + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var header = document.querySelector('.site-header');
    var bar = document.querySelector('.scroll-progress');
    var ticking = false;

    function onScroll() {
      var y = window.pageYOffset || root.scrollTop;
      if (header) { header.classList.toggle('scrolled', y > 8); }
      if (bar) {
        var max = root.scrollHeight - root.clientHeight;
        bar.style.setProperty('--p', max > 0 ? Math.min(1, y / max).toFixed(4) : 0);
      }
      ticking = false;
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; window.requestAnimationFrame(onScroll); }
    }, { passive: true });
    window.addEventListener('resize', onScroll);
    onScroll();

    // Hell/Dunkel-Umschalter
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme-pref', next);
        root.setAttribute('data-theme', next);
        saveTheme(next);
      });
    });

    // Passwort-Felder: "Passwort anzeigen"-Knopf (nur Anzeige im Browser, nichts wird gesendet oder gespeichert).
    var LAYOUT = /^(w-|min-w-|max-w-|flex$|flex-|grow|shrink|basis-|col-|mt-|mb-|ml-|mr-|my-|mx-|m-)/;
    document.querySelectorAll('input[type="password"]:not([data-no-toggle])').forEach(function (input) {
      if (input.parentNode.classList.contains('pw-wrap')) { return; }
      var wrap = document.createElement('span');
      wrap.className = 'pw-wrap';
      // Layout-Klassen (Breite/Abstand) wandern auf den Container, damit das Feld in Zeilen und Gittern unverändert passt.
      Array.prototype.slice.call(input.classList).forEach(function (c) {
        if (LAYOUT.test(c)) { wrap.classList.add(c); input.classList.remove(c); }
      });
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pw-toggle';
      btn.setAttribute('aria-pressed', 'false');
      btn.setAttribute('aria-label', 'Passwort anzeigen');
      btn.title = 'Passwort anzeigen';
      var icon = document.createElement('i');
      icon.className = 'bx bx-show';
      icon.setAttribute('aria-hidden', 'true');
      btn.appendChild(icon);
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      wrap.appendChild(btn);
      function set(show) {
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        var label = show ? 'Passwort verbergen' : 'Passwort anzeigen';
        btn.setAttribute('aria-label', label);
        btn.title = label;
        icon.className = 'bx ' + (show ? 'bx-hide' : 'bx-show');
      }
      btn.addEventListener('click', function () {
        set(input.type === 'password');
        input.focus();
      });
      // Nie sichtbar stehen lassen: beim Absenden und beim Verlassen/Zurückkehren (Seiten-Cache) wieder verbergen.
      if (input.form) { input.form.addEventListener('submit', function () { set(false); }); }
      window.addEventListener('pagehide', function () { set(false); });
    });

    // Bestätigungsdialog für riskante Formulare (data-confirm)
    document.addEventListener('submit', function (ev) {
      var b = ev.submitter && ev.submitter.getAttribute('data-confirm');
      if (b && !window.confirm(b)) { ev.preventDefault(); }
    });

    // Mobile-Navigation
    var toggle = document.querySelector('.nav-toggle');
    var panel = document.getElementById('nav-panel');
    if (toggle && panel) {
      toggle.addEventListener('click', function () {
        var open = panel.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        var icon = toggle.querySelector('i');
        if (icon) { icon.className = open ? 'bx bx-x' : 'bx bx-menu'; }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('open')) { toggle.click(); toggle.focus(); }
      });
    }

    // Reveal-on-Scroll mit leichter Staffelung
    var items = document.querySelectorAll('main .card, main [data-reveal]');
    if (!('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('in'); });
      return;
    }
    var seen = 0;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          en.target.style.setProperty('--d', Math.min(seen++ % 4, 3) * 70 + 'ms');
          en.target.classList.add('in');
          io.unobserve(en.target);
        }
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -30px 0px' });
    items.forEach(function (el) { el.classList.add('reveal'); io.observe(el); });
  });
})();
