/* VylarisAuth – WebAuthn-Client (Registrierung, MFA, passwortloser Login). Keine Abhängigkeiten. */
(function () {
  'use strict';

  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  function b64uToBuf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }
  function bufToB64u(buf) {
    var bytes = new Uint8Array(buf), s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Fehler'); return j; });
    });
  }
  function showError(msg) {
    var el = document.getElementById('passkey-error');
    if (el) { el.textContent = msg; el.classList.remove('hidden'); }
  }
  function supported() { return !!(window.PublicKeyCredential && navigator.credentials); }

  function creationOptions(o) {
    o.challenge = b64uToBuf(o.challenge);
    o.user.id = b64uToBuf(o.user.id);
    (o.excludeCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });
    return o;
  }
  function requestOptions(o) {
    o.challenge = b64uToBuf(o.challenge);
    (o.allowCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });
    return o;
  }
  function attestationJson(c) {
    return {
      id: c.id, rawId: bufToB64u(c.rawId), type: c.type,
      response: {
        clientDataJSON: bufToB64u(c.response.clientDataJSON),
        attestationObject: bufToB64u(c.response.attestationObject)
      }
    };
  }
  function assertionJson(c) {
    return {
      id: c.id, rawId: bufToB64u(c.rawId), type: c.type,
      response: {
        clientDataJSON: bufToB64u(c.response.clientDataJSON),
        authenticatorData: bufToB64u(c.response.authenticatorData),
        signature: bufToB64u(c.response.signature),
        userHandle: c.response.userHandle ? bufToB64u(c.response.userHandle) : null
      }
    };
  }

  function authenticate(optionsUrl, verifyUrl, extra) {
    return post(optionsUrl, extra).then(function (opts) {
      return navigator.credentials.get({ publicKey: requestOptions(opts) });
    }).then(function (cred) {
      return post(verifyUrl, { credential: assertionJson(cred) });
    }).then(function (res) { window.location.assign(res.redirect || '/account'); });
  }

  var btnLogin = document.getElementById('passkey-login');
  if (btnLogin) {
    if (!supported()) { btnLogin.classList.add('hidden'); }
    btnLogin.addEventListener('click', function () {
      authenticate('/login/passkey/options', '/login/passkey/verify', { next: btnLogin.dataset.next || '' })
        .catch(function () { alert('Anmeldung mit Passkey nicht möglich.'); });
    });
  }

  var btnMfa = document.getElementById('passkey-mfa');
  if (btnMfa) {
    if (!supported()) { btnMfa.classList.add('hidden'); }
    btnMfa.addEventListener('click', function () {
      authenticate('/mfa/webauthn/options', '/mfa/webauthn/verify')
        .catch(function (e) { showError(e.message || 'Passkey konnte nicht bestätigt werden.'); });
    });
  }

  var btnReg = document.getElementById('passkey-register');
  if (btnReg) {
    if (!supported()) { showError('Dieser Browser unterstützt keine Passkeys.'); btnReg.disabled = true; }
    btnReg.addEventListener('click', function () {
      post('/account/mfa/passkeys/options').then(function (opts) {
        return navigator.credentials.create({ publicKey: creationOptions(opts) });
      }).then(function (cred) {
        return post('/account/mfa/passkeys/register', {
          credential: attestationJson(cred),
          name: (document.getElementById('passkey-name') || {}).value || ''
        });
      }).then(function (res) {
        if (res.recoveryCodes && res.recoveryCodes.length) {
          var box = document.getElementById('passkey-codes');
          box.classList.remove('hidden');
          box.textContent = '';
          var h = document.createElement('p');
          h.className = 'font-medium';
          h.textContent = 'Recovery-Codes (nur jetzt sichtbar – bitte sicher speichern):';
          var ul = document.createElement('ul');
          ul.className = 'mt-2 grid grid-cols-2 gap-2 font-mono text-sm';
          res.recoveryCodes.forEach(function (c) {
            var li = document.createElement('li');
            li.className = 'rounded bg-slate-100 px-2 py-1 text-center dark:bg-slate-800';
            li.textContent = c;
            ul.appendChild(li);
          });
          var a = document.createElement('a');
          a.href = '/account/mfa/passkeys';
          a.className = 'mt-3 inline-block text-indigo-600 hover:underline';
          a.textContent = 'Fertig – Seite neu laden';
          box.appendChild(h); box.appendChild(ul); box.appendChild(a);
        } else {
          window.location.reload();
        }
      }).catch(function (e) { showError(e.message || 'Registrierung fehlgeschlagen.'); });
    });
  }
})();
