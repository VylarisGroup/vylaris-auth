<section class="card mx-auto max-w-md">
  <div class="icon-badge icon-badge-err"><i class="bx bx-trash"></i></div>
  <h1 class="text-center text-xl font-semibold">Konto endgültig löschen?</h1>
  <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Das Konto <strong><?= $e($user['email']) ?></strong> und alle zugehörigen Daten werden unwiderruflich gelöscht.</p>
  <div class="mt-3"><?php require __DIR__ . '/../partials/errors.php'; ?></div>
  <form method="post" action="/account/privacy/delete/confirm" class="mt-4 space-y-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" class="mt-1" required> Ja, mein Konto soll unwiderruflich gelöscht werden.</label>
    <div class="flex gap-3"><button class="btn btn-danger" type="submit">Endgültig löschen</button><a class="btn btn-secondary" href="/account/privacy">Abbrechen</a></div>
  </form>
</section>
