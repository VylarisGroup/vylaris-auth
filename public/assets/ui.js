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

    // Seitenleiste: ab Desktop-Breite immer offen, auf dem Handy zunächst eingeklappt (Knopf „Menü“)
    var side = document.querySelector('details[data-sidebar]');
    if (side && window.matchMedia) {
      var desk = window.matchMedia('(min-width: 1024px)');
      var fit = function () { side.open = desk.matches; };
      fit();
      if (desk.addEventListener) { desk.addEventListener('change', fit); }
    }

    // Seitenleiste: ausgeklappte Gruppen merken (nur Komfort; localStorage kann fehlen oder werfen)
    document.querySelectorAll('details.side-group[data-side-key]').forEach(function (d) {
      var key = 'side:' + d.getAttribute('data-side-key');
      try {
        if (!d.hasAttribute('data-active')) { d.open = window.localStorage.getItem(key) === '1'; }
      } catch (err) { /* ignorieren */ }
      d.addEventListener('toggle', function () {
        try { window.localStorage.setItem(key, d.open ? '1' : '0'); } catch (err) { /* ignorieren */ }
      });
    });

    // Assistent: Formular mit Schritten (fieldset.wiz-step) -> eine Seite pro Schritt, Prüfung je Schritt, Zusammenfassung.
    // Ohne JavaScript sind alle Schritte sichtbar und das Formular funktioniert unverändert.
    document.querySelectorAll('form[data-wizard]').forEach(function (form) {
      var steps = Array.prototype.slice.call(form.querySelectorAll('fieldset.wiz-step'));
      var submit = form.querySelector('.wiz-submit');
      var nav = form.querySelector('.wiz-nav');
      if (steps.length < 2 || !submit || !nav) { return; }
      form.noValidate = true; // geprüft wird schrittweise per Skript (versteckte Felder lassen sich nicht fokussieren)
      var idx = 0;
      var progress = document.createElement('ol');
      progress.className = 'wiz-steps';
      progress.setAttribute('aria-label', 'Fortschritt');
      var dots = steps.map(function (s, i) {
        var li = document.createElement('li');
        li.className = 'wiz-dot';
        var num = document.createElement('span');
        num.className = 'wiz-num';
        num.textContent = String(i + 1);
        li.appendChild(num);
        li.appendChild(document.createTextNode(' ' + (s.getAttribute('data-step') || 'Schritt ' + (i + 1))));
        progress.appendChild(li);
        return li;
      });
      form.insertBefore(progress, steps[0]);
      var back = document.createElement('button');
      back.type = 'button';
      back.className = 'btn btn-secondary';
      back.textContent = 'Zurück';
      var next = document.createElement('button');
      next.type = 'button';
      next.className = 'btn btn-primary';
      next.textContent = 'Weiter';
      nav.insertBefore(back, nav.firstChild);
      nav.insertBefore(next, submit);

      function fields(step) { return Array.prototype.slice.call(step.querySelectorAll('input, select, textarea')); }
      function valid(step) {
        var f = fields(step);
        for (var i = 0; i < f.length; i++) {
          if (!f[i].checkValidity()) { f[i].reportValidity(); return false; }
        }
        return true;
      }
      function summarize(step) {
        var dl = step.querySelector('[data-wizard-summary]');
        if (!dl) { return; }
        while (dl.firstChild) { dl.removeChild(dl.firstChild); }
        form.querySelectorAll('[data-label]').forEach(function (f) {
          var v;
          if (f.type === 'checkbox') { v = f.checked ? 'ja' : 'nein'; }
          else if (f.tagName === 'SELECT') { v = f.options[f.selectedIndex] ? f.options[f.selectedIndex].text : ''; }
          else if (f.type === 'password' || f.hasAttribute('data-secret')) { v = f.value ? '••••••••' : '–'; }
          else { v = f.value.trim() === '' ? '–' : f.value.trim(); }
          var dt = document.createElement('dt');
          dt.textContent = f.getAttribute('data-label');
          var dd = document.createElement('dd');
          dd.textContent = v;
          dl.appendChild(dt);
          dl.appendChild(dd);
        });
      }
      function show(i) {
        idx = i;
        steps.forEach(function (s, n) { s.hidden = n !== i; });
        dots.forEach(function (d, n) {
          d.classList.toggle('current', n === i);
          d.classList.toggle('done', n < i);
          if (n === i) { d.setAttribute('aria-current', 'step'); } else { d.removeAttribute('aria-current'); }
        });
        var last = i === steps.length - 1;
        if (steps[i].hasAttribute('data-summary')) { summarize(steps[i]); }
        back.hidden = i === 0;
        next.hidden = last;
        submit.hidden = !last;
        var first = fields(steps[i])[0];
        if (first && !last) { first.focus(); }
      }
      next.addEventListener('click', function () { if (valid(steps[idx])) { show(idx + 1); } });
      back.addEventListener('click', function () { show(idx - 1); });
      // Enter springt zum nächsten Schritt statt vorzeitig abzusenden.
      form.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && idx < steps.length - 1 && ev.target.tagName !== 'TEXTAREA' && ev.target.tagName !== 'BUTTON') {
          ev.preventDefault();
          next.click();
        }
      });
      // Beim Absenden alle Schritte prüfen; bei einem Fehler dorthin springen.
      form.addEventListener('submit', function (ev) {
        for (var i = 0; i < steps.length; i++) {
          if (!valid(steps[i])) { ev.preventDefault(); show(i); valid(steps[i]); return; }
        }
      });
      show(0);
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
