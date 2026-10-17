<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-envelope"></i></div>
  <h1 class="text-center text-2xl font-bold">Anmeldelink per E-Mail</h1>
  <p class="mt-1 text-center text-sm text-slate-500">Kein Passwort nötig: Wir senden Ihnen einen Link zur einmaligen Anmeldung. Ist ein zweiter Faktor eingerichtet, wird er weiterhin abgefragt.</p>
  <form method="post" action="/login/magic" class="mt-6 space-y-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="next" value="<?= $e($next ?? '') ?>">
    <label class="label">E-Mail
      <input class="input mt-1 w-full" type="email" name="email" value="<?= $e($email ?? '') ?>" autocomplete="email" required autofocus>
    </label>
    <button class="btn btn-primary w-full" type="submit"><i class="bx bx-send"></i> Link senden</button>
  </form>
  <p class="mt-6 text-center text-sm"><a class="link" href="/login">Zurück zur Anmeldung</a></p>
</section>
