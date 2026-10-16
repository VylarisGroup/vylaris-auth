(function () {
  'use strict';
  var form = document.querySelector('form[data-setup]');
  if (!form) { return; }
  var steps = Array.prototype.slice.call(form.querySelectorAll('fieldset[data-step]'));
  var prev = form.querySelector('[data-prev]'), next = form.querySelector('[data-next]'), submit = form.querySelector('[data-submit]');
  var bar = document.createElement('ol');
  bar.className = 'steps';
  steps.forEach(function (st) {
    var li = document.createElement('li');
    li.textContent = st.getAttribute('data-title') || '';
    bar.appendChild(li);
  });
  form.insertBefore(bar, form.firstChild.nextSibling);
  form.noValidate = true;
  var cur = Math.min(Math.max((parseInt(form.getAttribute('data-start'), 10) || 1) - 1, 0), steps.length - 1);

  function show(i) {
    cur = i;
    steps.forEach(function (st, n) { st.hidden = n !== i; });
    Array.prototype.forEach.call(bar.children, function (li, n) {
      li.className = n < i ? 'done' : (n === i ? 'cur' : '');
    });
    prev.hidden = i === 0;
    next.hidden = i === steps.length - 1;
    submit.hidden = i !== steps.length - 1;
    var f = steps[i].querySelector('input:not([type=hidden]),select');
    if (f) { f.focus({ preventScroll: true }); }
  }
  function valid(i) {
    var fields = steps[i].querySelectorAll('input,select');
    for (var n = 0; n < fields.length; n++) {
      if (!fields[n].checkValidity()) { fields[n].reportValidity(); return false; }
    }
    return true;
  }
  prev.addEventListener('click', function () { if (cur > 0) { show(cur - 1); } });
  next.addEventListener('click', function () { if (valid(cur)) { show(cur + 1); } });
  form.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.tagName !== 'BUTTON' && cur < steps.length - 1) {
      ev.preventDefault();
      next.click();
    }
  });
  form.addEventListener('submit', function (ev) {
    for (var i = 0; i < steps.length; i++) {
      if (!valid(i)) { ev.preventDefault(); show(i); steps[i].querySelector(':invalid') && steps[i].querySelector(':invalid').reportValidity(); return; }
    }
    var p1 = form.elements.admin_password, p2 = form.elements.admin_password2;
    if (p1 && p2 && p1.value !== p2.value) { ev.preventDefault(); show(steps.length - 1); p2.setCustomValidity('Die Passwörter stimmen nicht überein.'); p2.reportValidity(); p2.addEventListener('input', function () { p2.setCustomValidity(''); }, { once: true }); }
  });
  show(cur);
})();
