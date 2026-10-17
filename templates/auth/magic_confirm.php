<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-log-in-circle"></i></div>
  <h1 class="text-center text-2xl font-bold">Anmeldung bestätigen</h1>
  <p class="mt-1 text-center text-sm text-slate-500">Sie melden sich an als</p>
  <p class="mt-1 text-center font-semibold"><?= $e($accountEmail) ?></p>
  <form method="post" action="/login/magic/verify" class="mt-6 space-y-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <input type="hidden" name="next" value="<?= $e($next ?? '') ?>">
    <button class="btn btn-primary w-full" type="submit"><i class="bx bx-log-in"></i> Jetzt anmelden</button>
  </form>
  <p class="mt-6 text-center text-sm text-slate-500">Nicht Sie? Schließen Sie diese Seite – es passiert nichts.</p>
</section>
