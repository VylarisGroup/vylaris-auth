<div class="mx-auto max-w-3xl space-y-6">
  <section class="card">
    <h1 class="text-xl font-semibold"><i class="bx bx-shield-quarter"></i> Datenschutz &amp; Ihre Daten</h1>
    <p class="mt-1 text-sm text-slate-500">Hier üben Sie Ihre Rechte nach der DSGVO selbst aus. Siehe auch die <a class="link" href="/privacy">Datenschutzerklärung</a>.</p>
    <?php if (!empty($flash)): ?><p class="alert alert-ok mt-3" role="status"><?= $e($flash) ?></p><?php endif; ?>
    <div class="mt-3"><?php require __DIR__ . '/../partials/errors.php'; ?></div>
    <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
      <dt class="text-slate-500">Name</dt><dd><?= $e($user['display_name']) ?></dd>
      <dt class="text-slate-500">E-Mail</dt><dd><?= $e($user['email']) ?></dd>
      <dt class="text-slate-500">Konto erstellt</dt><dd><?= $e($user['created_at']) ?> UTC</dd>
      <dt class="text-slate-500">Aktive Sitzungen</dt><dd><?= (int) $counts['sessions'] ?> · <a class="link" href="/account/sessions">ansehen</a></dd>
      <dt class="text-slate-500">Freigaben für Anwendungen</dt><dd><?= (int) $counts['apps'] ?> · <a class="link" href="/account/apps">verwalten</a></dd>
      <dt class="text-slate-500">Verknüpfte Anmeldungen</dt><dd><?= (int) $counts['social'] ?> · <a class="link" href="/account/social">verwalten</a></dd>
    </dl>
  </section>

  <section class="card">
    <h2 class="font-semibold"><i class="bx bx-download"></i> Meine Daten exportieren (Auskunft &amp; Übertragbarkeit)</h2>
    <p class="mt-1 text-sm text-slate-500">Sie erhalten alle zu Ihrem Konto gespeicherten Daten als JSON-Datei. Aus Sicherheitsgründen sind Geheimnisse (Passwort-Hash, Schlüsselmaterial) nicht enthalten.</p>
    <form method="post" action="/account/privacy/export" class="mt-3 flex flex-wrap items-end gap-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <?php if ((int) $user['password_set'] === 1): ?><label class="text-sm">Passwort zur Bestätigung<input class="input w-full" type="password" name="password" autocomplete="current-password" required></label><?php endif; ?>
      <button class="btn btn-secondary" type="submit"><i class="bx bx-download"></i> JSON herunterladen</button>
    </form>
  </section>

  <section class="card">
    <h2 class="font-semibold"><i class="bx bx-envelope"></i> E-Mail-Adresse ändern (Berichtigung)</h2>
    <form method="post" action="/account/email" class="mt-3 grid gap-3 sm:grid-cols-2">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="text-sm">Neue E-Mail-Adresse<input class="input w-full" type="email" name="new_email" autocomplete="email" required maxlength="255"></label>
      <?php if ((int) $user['password_set'] === 1): ?><label class="text-sm">Passwort zur Bestätigung<input class="input w-full" type="password" name="password" autocomplete="current-password" required></label><?php endif; ?>
      <div class="sm:col-span-2"><button class="btn btn-secondary" type="submit">Bestätigungslink senden</button></div>
    </form>
  </section>

  <section class="card border-red-300 dark:border-red-900">
    <h2 class="font-semibold text-red-700 dark:text-red-300"><i class="bx bx-trash"></i> Konto löschen (Recht auf Löschung)</h2>
    <p class="mt-1 text-sm text-slate-500">Löscht Ihr Konto, Ihre Anmeldungen, Zweitfaktoren, Freigaben und Verknüpfungen unwiderruflich und meldet Sie überall ab. Sicherheitsprotokolle bleiben ohne Personenbezug erhalten. Zur Sicherheit senden wir Ihnen einen Bestätigungslink per E-Mail.</p>
    <?php if (!empty($blocker)): ?>
      <p class="alert alert-warn mt-3"><?= $e($blocker) ?></p>
    <?php else: ?>
    <form method="post" action="/account/privacy/delete" class="mt-3 flex flex-wrap items-end gap-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <?php if ((int) $user['password_set'] === 1): ?><label class="text-sm">Passwort zur Bestätigung<input class="input w-full" type="password" name="password" autocomplete="current-password" required></label><?php endif; ?>
      <button class="btn btn-outline-danger" type="submit">Löschung anfordern</button>
    </form>
    <?php endif; ?>
  </section>
</div>
