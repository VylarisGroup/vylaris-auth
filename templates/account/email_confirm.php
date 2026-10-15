<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-envelope"></i></div>
  <h1 class="text-center text-xl font-semibold">E-Mail-Adresse ändern</h1>
  <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Neue Adresse: <strong><?= $e($newEmail) ?></strong></p>
  <form method="post" action="/account/email/confirm" class="mt-4 flex gap-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <button class="btn btn-primary" type="submit">Änderung bestätigen</button><a class="btn btn-secondary" href="/account/privacy">Abbrechen</a>
  </form>
</section>
