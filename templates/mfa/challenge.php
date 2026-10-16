<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-shield-quarter"></i></div>
  <h1 class="text-center text-2xl font-bold">Zweiter Faktor</h1>
  <p class="mt-1 text-center text-sm text-slate-500">Bitte bestätigen Sie Ihre Anmeldung.</p>
  <?php if (!empty($error)): ?>
  <div class="alert alert-err mt-4" role="alert"><?= $e($error) ?></div>
  <?php endif; ?>

  <?php if ($hasPasskey): ?>
  <button id="passkey-mfa" class="btn btn-primary mt-5 w-full" type="button"><i class="bx bx-fingerprint"></i> Mit Passkey bestätigen</button>
  <p id="passkey-error" class="mt-2 hidden text-sm text-red-600" role="alert"></p>
  <?php endif; ?>

  <?php if ($hasTotp): ?>
  <form method="post" action="/mfa/totp" class="mt-6 space-y-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="label">Code aus der Authenticator-App
      <input class="input mt-1 w-full text-center text-lg tracking-widest" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
    </label>
    <button class="btn btn-primary w-full" type="submit">Bestätigen</button>
  </form>
  <?php endif; ?>

  <details class="mt-6 text-sm">
    <summary class="link cursor-pointer">Recovery-Code verwenden</summary>
    <form method="post" action="/mfa/recovery" class="mt-3 space-y-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input class="input w-full font-mono" name="code" placeholder="xxxxx-xxxxx" autocomplete="off" required>
      <button class="btn btn-secondary w-full" type="submit">Mit Recovery-Code anmelden</button>
    </form>
  </details>
  <form method="post" action="/logout" class="mt-4 text-center">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button class="link text-slate-500" type="submit">Abbrechen</button>
  </form>
</section>
<script src="/assets/passkey.js?v=<?= $e($assetVer ?? "") ?>" defer></script>
