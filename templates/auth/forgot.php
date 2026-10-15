<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-mail-send"></i></div>
  <h1 class="text-center text-2xl font-bold">Passwort zurücksetzen</h1>
  <p class="mt-1 text-center text-sm text-slate-500">Wir senden Ihnen einen Link zum Zurücksetzen.</p>
  <form method="post" action="/password/forgot" class="mt-6 space-y-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="label">E-Mail
      <input class="input mt-1 w-full" type="email" name="email" autocomplete="email" required autofocus>
    </label>
    <button class="btn btn-primary w-full" type="submit">Link senden</button>
  </form>
</section>
