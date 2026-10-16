<?php $cur = '/admin/users/new'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold"><i class="bx bx-rocket"></i> Neuer Benutzer – Assistent</h1>
  <p class="mt-1 text-sm text-slate-500">Der Benutzer erhält per E-Mail einen Link, um sein Passwort zu setzen. Alternativ das <a class="link" href="/admin/users">ausführliche Formular</a> verwenden.</p>
  <form method="post" action="/admin/users" class="wiz mt-5" data-wizard>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <fieldset class="wiz-step" data-step="Person">
      <legend class="wiz-legend">Wer bekommt ein Konto?</legend>
      <label class="text-sm">E-Mail-Adresse<input class="input w-full" type="email" name="email" required maxlength="255" data-label="E-Mail" autofocus></label>
      <label class="text-sm">Anzeigename<input class="input w-full" name="name" required maxlength="120" data-label="Name"></label>
    </fieldset>
    <?php if (!empty($licenseRequired)): ?>
    <fieldset class="wiz-step" data-step="Lizenz">
      <legend class="wiz-legend">Welcher Lizenzschlüssel gilt?</legend>
      <label class="text-sm">Lizenzschlüssel<input class="input w-full font-mono" name="license_key" <?= empty($licenseOptional) ? 'required' : '' ?> maxlength="64" autocomplete="off" spellcheck="false" data-label="Lizenzschlüssel"></label>
      <p class="wiz-hint"><?= !empty($licenseOptional) ? 'Leer lassen, um den hinterlegten Schlüssel zu verwenden. ' : '' ?>Der Schlüssel belegt einen Platz (Gerät) in der Lizenz. Er wird beim Lizenzserver geprüft; ist er ungültig oder voll, wird kein Konto angelegt.</p>
    </fieldset>
    <?php endif; ?>
    <fieldset class="wiz-step" data-step="Prüfen" data-summary>
      <legend class="wiz-legend">Alles richtig?</legend>
      <dl class="wiz-summary" data-wizard-summary></dl>
    </fieldset>
    <div class="wiz-nav"><button class="btn btn-primary wiz-submit" type="submit">Konto anlegen</button></div>
  </form>
</section>
