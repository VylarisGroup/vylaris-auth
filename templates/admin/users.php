<?php $cur = '/admin/users'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold">Benutzer</h1>
  <form method="get" action="/admin/users" class="mt-4 flex gap-2">
    <input class="input w-full" name="q" value="<?= $e($term) ?>" placeholder="E-Mail oder Name suchen" maxlength="100">
    <button class="btn btn-primary" type="submit">Suchen</button>
  </form>
  <div class="table-wrap mt-4"><table class="w-full text-left text-sm">
    <thead class="text-xs uppercase tracking-wide text-slate-400"><tr><th class="py-1 pr-3">ID</th><th class="pr-3">Name</th><th class="pr-3">E-Mail</th><th class="pr-3">Status</th><th>Verifiziert</th></tr></thead>
    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
    <?php foreach ($users as $u): ?>
      <tr><td class="py-2 pr-3"><?= (int) $u['id'] ?></td><td class="pr-3"><a class="link" href="/admin/users/<?= (int) $u['id'] ?>"><?= $e($u['display_name']) ?></a></td><td class="pr-3"><?= $e($u['email']) ?></td><td class="pr-3"><?= $e($u['status']) ?></td><td><?= $u['email_verified_at'] ? 'ja' : 'nein' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="5" class="py-2 text-slate-500">Keine Treffer.</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="mt-3 flex gap-4 text-sm">
    <?php if ($page > 1): ?><a class="link" href="/admin/users?q=<?= $e(rawurlencode($term)) ?>&amp;page=<?= $page - 1 ?>">← Zurück</a><?php endif; ?>
    <?php if (count($users) === 50): ?><a class="link" href="/admin/users?q=<?= $e(rawurlencode($term)) ?>&amp;page=<?= $page + 1 ?>">Weiter →</a><?php endif; ?>
  </div>
</section>
<section class="card mt-6">
  <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-semibold">Konto anlegen</h2><a class="btn btn-secondary btn-sm" href="/admin/users/new"><i class="bx bx-rocket"></i> Assistent starten</a></div>
  <p class="text-sm text-slate-500">Der Benutzer erhält eine E-Mail, um sein Passwort zu setzen.</p>
  <form method="post" action="/admin/users" class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input class="input w-full" type="email" name="email" placeholder="E-Mail" required maxlength="255">
    <input class="input w-full" name="name" placeholder="Anzeigename" required maxlength="120">
    <?php if (!empty($licenseRequired)): ?><input class="input w-full font-mono" name="license_key" placeholder="Lizenzschlüssel (belegt einen Platz)" required maxlength="64" autocomplete="off" spellcheck="false"><?php endif; ?>
    <button class="btn btn-primary" type="submit">Anlegen</button>
  </form>
</section>
