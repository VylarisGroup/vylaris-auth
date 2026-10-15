<?php $cur = '/admin/keys'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold">Signaturschlüssel (JWKS)</h1>
  <div class="table-wrap mt-4"><table class="w-full text-left text-sm"><thead class="text-xs uppercase tracking-wide text-slate-400"><tr><th class="py-1 pr-3">kid</th><th class="pr-3">Status</th><th class="pr-3">Erstellt (UTC)</th><th>Ausgemustert</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-slate-800">
  <?php foreach ($keys as $k): ?><tr><td class="py-2 pr-3 font-mono"><?= $e($k['kid']) ?></td><td class="pr-3"><?= $e($k['status']) ?></td><td class="pr-3"><?= $e($k['created_at']) ?></td><td><?= $e($k['retired_at'] ?? '–') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <form method="post" action="/admin/keys/rotate" class="mt-4"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn-primary" type="submit">Schlüssel rotieren</button></form>
  <p class="mt-2 text-xs text-slate-500">Der alte Schlüssel bleibt 24 h im JWKS, damit ausgestellte Tokens gültig bleiben.</p>
</section>
