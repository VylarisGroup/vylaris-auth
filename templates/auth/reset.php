<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-lock-open-alt"></i></div>
  <h1 class="text-center text-2xl font-bold">Neues Passwort festlegen</h1>
  <div class="mt-4"><?php require __DIR__ . '/../partials/errors.php'; ?></div>
  <form method="post" action="/password/reset" class="space-y-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <label class="label">Neues Passwort
      <input class="input mt-1 w-full" type="password" name="password" autocomplete="new-password" minlength="12" required autofocus>
    </label>
    <button class="btn btn-primary w-full" type="submit">Passwort speichern</button>
  </form>
</section>
