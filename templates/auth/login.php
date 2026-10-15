<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-log-in-circle"></i></div>
  <h1 class="text-center text-2xl font-bold"><?= $e($brand['loginTitle']) ?></h1>
  <?php if ($brand['loginSubtitle'] !== ''): ?><p class="mt-1 text-center text-sm text-slate-500"><?= $e($brand['loginSubtitle']) ?></p><?php endif; ?>
  <?php if (!empty($error)): ?>
  <div class="alert alert-err mt-4" role="alert"><?= $e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/login" class="mt-6 space-y-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="next" value="<?= $e($next ?? '') ?>">
    <label class="label">E-Mail
      <input class="input mt-1 w-full" type="email" name="email" value="<?= $e($email ?? '') ?>" autocomplete="username webauthn" required autofocus>
    </label>
    <label class="label">Passwort
      <input class="input mt-1 w-full" type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn-primary w-full" type="submit"><i class="bx bx-log-in"></i> Anmelden</button>
  </form>
  <?php if (!empty($passkeyEnabled)): ?>
  <button id="passkey-login" data-next="<?= $e($next ?? '') ?>" class="btn btn-secondary mt-3 w-full" type="button"><i class="bx bx-fingerprint"></i> Mit Passkey anmelden</button>
  <?php endif; ?>
  <?php require __DIR__ . '/../partials/social-buttons.php'; ?>
  <?php if (!empty($unverified)): ?>
  <form method="post" action="/verify-email/resend" class="mt-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="email" value="<?= $e($email ?? '') ?>">
    <button class="link" type="submit">Bestätigungs-E-Mail erneut senden</button>
  </form>
  <?php endif; ?>
  <div class="mt-6 flex justify-between text-sm">
    <a class="link" href="/password/forgot">Passwort vergessen?</a>
    <a class="link" href="/register">Konto erstellen</a>
  </div>
</section>
<script src="/assets/passkey.js" defer></script>
