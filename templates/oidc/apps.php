<section class="card mx-auto max-w-3xl">
  <h1 class="text-xl font-semibold"><i class="bx bx-grid-alt"></i> Verbundene Anwendungen</h1>
  <?php if (!empty($flash)): ?><p class="alert alert-ok mt-3" role="status"><?= $e($flash) ?></p><?php endif; ?>
  <ul class="list-rows mt-4 divide-y divide-slate-200 dark:divide-slate-800">
    <?php foreach ($apps as $a): ?>
    <li class="flex items-center justify-between py-3">
      <div><p class="font-medium"><?= $e($a['name']) ?></p><p class="text-xs text-slate-500">Freigegeben <?= $e($a['granted_at']) ?> UTC · <?= $e($a['scopes']) ?></p></div>
      <form method="post" action="/account/apps/revoke">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="client" value="<?= (int) $a['id'] ?>">
        <button class="link-danger" type="submit">Zugriff entziehen</button>
      </form>
    </li>
    <?php endforeach; ?>
    <?php if (!$apps): ?><li class="py-3 text-sm text-slate-500">Keine Anwendungen mit gespeicherter Einwilligung. Interne Anwendungen ohne Einwilligungsabfrage erscheinen hier nicht.</li><?php endif; ?>
  </ul>
</section>
