<?php $cur = '/admin/social/new'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold"><i class="bx bx-rocket"></i> Neuer Social-Anbieter – Assistent</h1>
  <p class="mt-1 text-sm text-slate-500">Einen Anbieter wie Google oder GitHub für die Anmeldung einrichten. Alternativ das <a class="link" href="/admin/social">ausführliche Formular</a> verwenden.</p>
  <form method="post" action="/admin/social" class="wiz mt-5" data-wizard autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <fieldset class="wiz-step" data-step="Anbieter">
      <legend class="wiz-legend">Welchen Anbieter möchten Sie anbinden?</legend>
      <label class="text-sm">Typ<select class="input w-full" name="type" data-label="Typ">
        <?php foreach ($types as $k => $t): ?><option value="<?= $e($k) ?>"><?= $e($t['label']) ?></option><?php endforeach; ?>
      </select></label>
      <label class="text-sm">Bezeichnung (Text auf dem Anmelde-Knopf, optional)<input class="input w-full" name="label" maxlength="80" data-label="Bezeichnung"></label>
      <label class="text-sm">Schlüssel (optional – nur nötig, wenn Sie mehrere Anbieter desselben Typs haben)<input class="input w-full" name="provider_key" maxlength="40" pattern="[a-z0-9][a-z0-9\-]{1,39}" data-label="Schlüssel"></label>
    </fieldset>
    <fieldset class="wiz-step" data-step="Zugangsdaten">
      <legend class="wiz-legend">Zugangsdaten vom Anbieter</legend>
      <p class="wiz-hint">Legen Sie beim Anbieter eine OAuth-/OIDC-Anwendung an. Als Redirect-URI tragen Sie dort <code class="select-all break-all">/social/&lt;Schlüssel&gt;/callback</code> unserer Domain ein; die vollständige Adresse zeigt die Detailseite nach dem Anlegen.</p>
      <label class="text-sm">Client-ID<input class="input w-full font-mono" name="client_id" required maxlength="255" data-label="Client-ID"></label>
      <label class="text-sm">Client-Secret<input class="input w-full font-mono" type="password" name="client_secret" required autocomplete="new-password" data-label="Client-Secret" data-secret="1"></label>
      <label class="text-sm">Issuer-URL (GitLab / OpenID Connect) bzw. Mandant (Microsoft) – sonst leer lassen<input class="input w-full font-mono" name="issuer" maxlength="255" placeholder="https://id.example.org" data-label="Issuer / Mandant"></label>
    </fieldset>
    <fieldset class="wiz-step" data-step="Optionen">
      <legend class="wiz-legend">Wie soll der Anbieter genutzt werden?</legend>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled" value="1" data-label="Sofort aktiv"> sofort aktivieren</label>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_signup" value="1" checked data-label="Registrierung erlaubt"> Registrierung über diesen Anbieter erlauben</label>
    </fieldset>
    <fieldset class="wiz-step" data-step="Prüfen" data-summary>
      <legend class="wiz-legend">Alles richtig?</legend>
      <dl class="wiz-summary" data-wizard-summary></dl>
    </fieldset>
    <div class="wiz-nav"><button class="btn btn-primary wiz-submit" type="submit">Anbieter anlegen</button></div>
  </form>
</section>
