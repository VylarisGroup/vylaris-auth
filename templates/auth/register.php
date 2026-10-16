<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-user-plus"></i></div>
  <h1 class="text-center text-2xl font-bold">Konto erstellen</h1>
  <div class="mt-4"><?php require __DIR__ . '/../partials/errors.php'; ?></div>
  <form method="post" action="/register" class="space-y-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="label">Name
      <input class="input mt-1 w-full" name="name" value="<?= $e($old['name']) ?>" autocomplete="name" required>
    </label>
    <label class="label">E-Mail
      <input class="input mt-1 w-full" type="email" name="email" value="<?= $e($old['email']) ?>" autocomplete="email" required>
    </label>
    <?php if (!empty($licenseRequired) && empty($licenseOptional)): ?>
    <label class="label">Lizenzschlüssel
      <input class="input mt-1 w-full font-mono" name="license_key" value="<?= $e($old['license_key'] ?? '') ?>" autocomplete="off" spellcheck="false" maxlength="64" placeholder="XX-XXXX-XXXX-XXXX" required>
      <span class="mt-1 block text-xs text-slate-500">Jedes Konto belegt einen Platz Ihrer Lizenz.</span>
    </label>
    <?php endif; ?>
    <label class="label">Passwort
      <input class="input mt-1 w-full" type="password" name="password" autocomplete="new-password" minlength="12" required>
      <span class="mt-1 block text-xs text-slate-500">Mindestens 12 Zeichen. Eine Passphrase ist ideal.</span>
    </label>
    <label class="flex items-start gap-2 text-sm">
      <input type="checkbox" name="accept_privacy" value="1" class="mt-1" required>
      <span>Ich habe die <a class="link" href="/privacy" target="_blank" rel="noopener">Datenschutzerklärung</a> zur Kenntnis genommen<?php if (!empty($brand['termsUrl'])): ?> und akzeptiere die <a class="link" href="<?= $e($brand['termsUrl']) ?>" target="_blank" rel="noopener">Nutzungsbedingungen</a><?php endif; ?>.</span>
    </label>
    <button class="btn btn-primary w-full" type="submit">Registrieren</button>
  </form>
  <p class="mt-6 text-sm"><a class="link" href="/login">Bereits ein Konto? Anmelden</a></p>
</section>
