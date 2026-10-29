<div class="grid gap-6 md:grid-cols-2">
  <section class="card card-hover">
    <h1 class="text-xl font-semibold">Hallo, <?= $e($user['display_name']) ?></h1>
    <p class="mt-1 text-sm text-slate-500"><?= $e($user['email']) ?><?= $user['email_verified_at'] ? ' · bestätigt' : ' · nicht bestätigt' ?></p>
    <?php if (!empty($flash)): ?><p class="alert alert-ok mt-3" role="status"><?= $e($flash) ?></p><?php endif; ?>
    <div class="mt-4"><?php require __DIR__ . '/../partials/errors.php'; ?></div>
    <form method="post" action="/account/profile" class="space-y-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="label">Anzeigename
        <input class="input mt-1 w-full" name="name" value="<?= $e($user['display_name']) ?>" required>
      </label>
      <button class="btn btn-primary" type="submit">Speichern</button>
    </form>
    <p class="mt-4 text-sm text-slate-500">Rollen: <?= $e(implode(', ', $roles)) ?></p>
  </section>

  <section class="card card-hover">
    <h2 class="text-lg font-semibold"><i class="bx bx-lock-alt"></i> Zwei-Faktor-Schutz</h2>
    <ul class="mt-3 space-y-2 text-sm">
      <li><i class="bx bx-mobile-alt"></i> Authenticator-App: <?= $mfa['totp'] ? 'aktiv' : 'nicht eingerichtet' ?> · <a class="link" href="/account/mfa/totp">verwalten</a></li>
      <li><i class="bx bx-fingerprint"></i> Passkeys: <?= (int) $mfa['passkeys'] ?> · <a class="link" href="/account/mfa/passkeys">verwalten</a></li>
      <li><i class="bx bx-key"></i> Recovery-Codes übrig: <?= (int) $mfa['recovery'] ?> · <a class="link" href="/account/mfa/recovery">anzeigen / erneuern</a></li>
    </ul>
    <h2 class="mt-5 text-lg font-semibold"><i class="bx bx-link"></i> Verknüpfte Anmeldungen</h2>
    <p class="mt-2 text-sm">Google, Microsoft, GitHub &amp; Co. · <a class="link" href="/account/social">verwalten</a></p>
    <h2 class="mt-5 text-lg font-semibold"><i class="bx bx-shield-quarter"></i> Datenschutz</h2>
    <p class="mt-2 text-sm">Daten exportieren, E-Mail ändern, Konto löschen · <a class="link" href="/account/privacy">öffnen</a></p>
  </section>

  <?php if ((int) $user['password_set'] === 1): ?>
  <section class="card md:col-span-2">
    <h2 class="text-lg font-semibold"><i class="bx bx-key"></i> Passwort ändern</h2>
    <form method="post" action="/account/password" class="mt-3 grid gap-3 md:grid-cols-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input class="input" type="password" name="current_password" placeholder="Aktuelles Passwort" autocomplete="current-password" required>
      <input class="input" type="password" name="new_password" placeholder="Neues Passwort (min. 12 Zeichen)" autocomplete="new-password" minlength="12" required>
      <button class="btn btn-primary" type="submit">Ändern</button>
    </form>
  </section>
  <?php endif; ?>
</div>
