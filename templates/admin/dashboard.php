<?php $cur = '/admin'; require __DIR__ . '/_nav.php'; ?>
<h1 class="mb-4 text-xl font-semibold">Administration <span class="ml-1 text-xs font-normal text-slate-500">Version <?= $e($version) ?></span></h1>
<?php if (!empty($changelog)): ?>
<section class="card mb-4 border-brand-500/40" aria-labelledby="changelog-h">
  <h2 id="changelog-h" class="font-semibold"><i class="bx bx-gift"></i> Neu in Version <?= $e($version) ?></h2>
  <?php foreach ($changelog as $c): ?>
  <h3 class="mt-3 text-sm font-semibold">Version <?= $e($c['version']) ?><?php if ($c['date'] !== ''): ?> <span class="font-normal text-slate-500">· <?= $e($c['date']) ?></span><?php endif; ?></h3>
  <ul class="mt-1 list-disc space-y-1 pl-5 text-sm">
    <?php foreach ($c['items'] as $it): ?><li><?= $e($it) ?></li><?php endforeach; ?>
  </ul>
  <?php endforeach; ?>
  <form method="post" action="/admin/changelog/seen" class="mt-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button class="btn btn-primary" type="submit"><i class="bx bx-check"></i> Verstanden</button>
  </form>
</section>
<?php endif; ?>
<?php if (!empty($updateAvailable)): ?>
<p class="alert alert-ok mb-4" role="status"><i class="bx bx-cloud-download"></i> Version <?= $e($updateAvailable['version']) ?> ist verfügbar. <a class="link" href="/admin/update">Details und Installation</a></p>
<?php endif; ?>
<div class="grid grid-cols-2 gap-4 md:grid-cols-3">
  <?php foreach ([['Benutzer', 'users', 'bx-group'], ['Gesperrt', 'locked', 'bx-lock-alt'], ['Unverifiziert', 'unverified', 'bx-envelope'], ['Anwendungen', 'clients', 'bx-grid-alt'], ['Aktive Sitzungen', 'sessions', 'bx-devices'], ['Fehlanmeldungen 24 h', 'failed24h', 'bx-error']] as [$l, $k, $i]): ?>
  <div class="card card-hover"><p class="text-sm text-slate-500"><i class="bx <?= $e($i) ?>"></i> <?= $e($l) ?></p><p class="mt-1 bg-gradient-to-r from-brand-600 to-accent-600 bg-clip-text text-3xl font-bold text-transparent dark:from-brand-300 dark:to-accent-300"><?= (int) $stats[$k] ?></p></div>
  <?php endforeach; ?>
</div>
<section class="card mt-6">
  <h2 class="font-semibold">Letzte Ereignisse</h2>
  <?php $events = $events; require __DIR__ . '/_events.php'; ?>
  <a class="link mt-3 inline-block" href="/admin/audit">Alle Ereignisse</a>
</section>
