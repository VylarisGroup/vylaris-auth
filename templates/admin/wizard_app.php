<?php $cur = '/admin/apps/new'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold"><i class="bx bx-rocket"></i> Neue Anwendung – Assistent</h1>
  <p class="mt-1 text-sm text-slate-500">Schritt für Schritt eine Anwendung anbinden. Alternativ das <a class="link" href="/admin/apps">ausführliche Formular</a> verwenden.</p>
  <form method="post" action="/admin/apps" class="wiz mt-5" data-wizard>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <fieldset class="wiz-step" data-step="Name und Typ">
      <legend class="wiz-legend">Wie heißt die Anwendung?</legend>
      <label class="text-sm">Name<input class="input w-full" name="name" required minlength="2" maxlength="120" data-label="Name" autofocus></label>
      <label class="text-sm">Typ<select class="input w-full" name="type" data-label="Typ">
        <option value="confidential">Vertraulich – Server-Anwendung, erhält ein Client-Secret</option>
        <option value="public">Öffentlich – App/Single-Page-App, nur PKCE, kein Secret</option>
      </select></label>
      <p class="wiz-hint">Im Zweifel „vertraulich“: Die Anwendung hat einen eigenen Server, auf dem das Secret geheim bleibt.</p>
    </fieldset>
    <fieldset class="wiz-step" data-step="Adressen">
      <legend class="wiz-legend">Wohin darf zurückgeleitet werden?</legend>
      <label class="text-sm">Redirect-URIs (eine pro Zeile, https, exakte Übereinstimmung)<textarea class="input w-full font-mono" name="redirect_uris" rows="3" required placeholder="https://app.example.org/callback" data-label="Redirect-URIs"></textarea></label>
      <label class="text-sm">Post-Logout-URIs (optional, eine pro Zeile)<textarea class="input w-full font-mono" name="logout_uris" rows="2" placeholder="https://app.example.org/" data-label="Post-Logout-URIs"></textarea></label>
      <p class="wiz-hint">Die Redirect-URI steht in der Dokumentation der Anwendung (oft „…/callback“ oder „…/oidc/return“).</p>
    </fieldset>
    <fieldset class="wiz-step" data-step="Zugriff">
      <legend class="wiz-legend">Wer darf was?</legend>
      <label class="text-sm">Scopes<input class="input w-full" name="scopes" value="openid profile email roles" data-label="Scopes"></label>
      <label class="text-sm">Zugriff<select class="input w-full" name="access_policy" data-label="Zugriff">
        <option value="all">alle Benutzer</option>
        <option value="assigned">nur Benutzer mit Anwendungsrolle</option>
      </select></label>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="skip_consent" value="1" data-label="Einwilligung überspringen"> Einwilligung überspringen (nur für interne Anwendungen)</label>
    </fieldset>
    <fieldset class="wiz-step" data-step="Prüfen" data-summary>
      <legend class="wiz-legend">Alles richtig?</legend>
      <dl class="wiz-summary" data-wizard-summary></dl>
      <p class="wiz-hint">Nach dem Anlegen werden Client-ID und (bei vertraulichen Anwendungen) das Client-Secret <strong>einmalig</strong> angezeigt.</p>
    </fieldset>
    <div class="wiz-nav"><button class="btn btn-primary wiz-submit" type="submit">Anwendung anlegen</button></div>
  </form>
</section>
