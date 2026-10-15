<section class="card mx-auto max-w-lg">
  <h1 class="text-xl font-semibold"><i class="bx bx-key"></i> Recovery-Codes</h1>
  <div class="mt-4"><?php require __DIR__ . '/../partials/errors.php'; ?></div>
  <?php if (!empty($codes)): ?>
    <?php require __DIR__ . '/../partials/recovery-codes.php'; ?>
  <?php endif; ?>
  <?php if (!$hasFactor): ?>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Richten Sie zuerst eine Authenticator-App oder einen Passkey ein. Recovery-Codes werden danach automatisch erzeugt.</p>
  <?php else: ?>
    <p class="mt-2 text-sm">Verfügbare Codes: <strong><?= (int) $remaining ?></strong></p>
    <form method="post" action="/account/mfa/recovery/regenerate" class="mt-4 space-y-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="label">Zum Erneuern Passwort bestätigen (alte Codes werden ungültig)
        <input class="input mt-1 w-full" type="password" name="password" autocomplete="current-password" required>
      </label>
      <button class="btn btn-primary" type="submit">Neue Codes erzeugen</button>
    </form>
  <?php endif; ?>
</section>
