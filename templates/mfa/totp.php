<section class="card mx-auto max-w-lg">
  <h1 class="text-xl font-semibold"><i class="bx bx-mobile-alt"></i> Authenticator-App</h1>
  <div class="mt-4"><?php require __DIR__ . '/../partials/errors.php'; ?></div>

  <?php if (!empty($codes)): ?>
  <?php require __DIR__ . '/../partials/recovery-codes.php'; ?>
  <?php endif; ?>

  <?php if ($enabled): ?>
    <p class="mt-2 text-emerald-700 dark:text-emerald-300"><i class="bx bx-check-circle"></i> Aktiv. Bei der Anmeldung wird ein Code abgefragt.</p>
    <form method="post" action="/account/mfa/totp/disable" class="mt-6 space-y-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="label">Zum Entfernen Passwort bestätigen
        <input class="input mt-1 w-full" type="password" name="password" autocomplete="current-password" required>
      </label>
      <button class="btn btn-outline-danger btn-sm" type="submit">Authenticator-App entfernen</button>
    </form>
  <?php elseif ($pending): ?>
    <ol class="mt-2 list-decimal space-y-2 pl-5 text-sm">
      <li>Scannen Sie den QR-Code oder geben Sie den Schlüssel manuell in Ihrer App ein.</li>
      <li>Geben Sie den angezeigten 6-stelligen Code zur Bestätigung ein.</li>
    </ol>
    <?php if ($qr): ?><img class="mx-auto my-4 h-56 w-56 rounded bg-white p-2" src="<?= $e($qr) ?>" alt="QR-Code für die Authenticator-App"><?php endif; ?>
    <p class="mt-3 break-all rounded bg-slate-100 p-2 text-center font-mono text-sm dark:bg-slate-800"><?= $e($pending['secret']) ?></p>
    <form method="post" action="/account/mfa/totp/confirm" class="mt-4 space-y-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input class="input w-full text-center text-lg tracking-widest" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required>
      <button class="btn btn-primary w-full" type="submit">Bestätigen und aktivieren</button>
    </form>
  <?php else: ?>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Schützen Sie Ihr Konto mit Einmalcodes aus einer Authenticator-App.</p>
    <form method="post" action="/account/mfa/totp/start" class="mt-4">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-primary" type="submit">Einrichtung starten</button>
    </form>
  <?php endif; ?>
</section>
