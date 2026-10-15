<section class="card mx-auto max-w-md">
  <div class="icon-badge"><i class="bx bx-link-external"></i></div>
  <h1 class="text-center text-2xl font-bold">Zugriff erlauben?</h1>
  <p class="mt-2 text-slate-600 dark:text-slate-300"><strong><?= $e($client['name']) ?></strong> möchte:</p>
  <ul class="mt-3 list-inside list-disc space-y-1 text-sm">
    <?php foreach ($scopes as $s): ?><li><?= $e($s) ?></li><?php endforeach; ?>
  </ul>
  <form method="post" action="/oidc/consent" class="mt-6 flex gap-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button name="decision" value="allow" class="btn btn-primary flex-1" type="submit">Erlauben</button>
    <button name="decision" value="deny" class="btn btn-secondary flex-1" type="submit">Ablehnen</button>
  </form>
</section>
