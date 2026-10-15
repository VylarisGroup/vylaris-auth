<section class="card mx-auto max-w-md text-center">
  <div class="icon-badge"><i class="bx bx-log-out"></i></div>
  <h1 class="mt-3 text-xl font-semibold">Abmelden?</h1>
  <p class="mt-2 text-slate-600 dark:text-slate-300"><?= $client ? $e($client['name']) . ' möchte Sie' : 'Möchten Sie sich' ?> von allen <?= $e($brand['org']) ?>-Anwendungen abmelden<?= $client ? ' lassen' : '' ?>?</p>
  <form method="post" action="/oidc/logout" class="mt-6 flex gap-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="client_id" value="<?= $e($client['client_id'] ?? '') ?>">
    <input type="hidden" name="post_logout_redirect_uri" value="<?= $e($uri) ?>">
    <input type="hidden" name="state" value="<?= $e($state) ?>">
    <button class="btn btn-primary flex-1" type="submit">Abmelden</button>
    <a href="/account" class="btn btn-secondary flex-1">Abbrechen</a>
  </form>
</section>
