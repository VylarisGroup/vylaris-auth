<?php $cur = '/admin/apps'; require __DIR__ . '/_nav.php'; ?>
<?php if (!empty($secret)): ?>
<section class="alert alert-warn p-5 mb-6" role="alert">
  <h2 class="font-semibold"><i class="bx bx-key"></i> Anwendung angelegt – Zugangsdaten</h2>
  <p class="mt-2 text-sm">Client-ID: <code class="select-all break-all"><?= $e($secret['client_id']) ?></code></p>
  <?php if ($secret['client_secret'] !== null): ?>
  <p class="mt-1 text-sm">Client-Secret: <code class="select-all break-all"><?= $e($secret['client_secret']) ?></code></p>
  <p class="mt-2 text-xs">Das Secret wird nur jetzt angezeigt und ist danach nicht mehr abrufbar. Bei Verlust Secret erneuern.</p>
  <?php else: ?><p class="mt-2 text-xs">Öffentlicher Client (PKCE, kein Secret).</p><?php endif; ?>
</section>
<?php endif; ?>
<section class="card">
  <h1 class="text-xl font-semibold">Anwendungen</h1>
  <ul class="list-rows mt-4 divide-y divide-slate-200 dark:divide-slate-800">
  <?php foreach ($apps as $a): ?>
    <li class="flex items-center justify-between py-3"><div><a class="link" href="/admin/apps/<?= (int) $a['id'] ?>"><?= $e($a['name']) ?></a><p class="text-xs text-slate-500"><?= $e($a['client_id']) ?> · <?= $a['revoked_at'] ? 'widerrufen' : ((int) $a['is_active'] ? 'aktiv' : 'deaktiviert') ?></p></div></li>
  <?php endforeach; ?>
  <?php if (!$apps): ?><li class="py-3 text-sm text-slate-500">Noch keine Anwendungen.</li><?php endif; ?>
  </ul>
</section>
<section class="card mt-6">
  <h2 class="font-semibold">Neue Anwendung registrieren</h2>
  <form method="post" action="/admin/apps" class="mt-3 grid gap-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="text-sm">Name<input class="input w-full" name="name" required minlength="2" maxlength="120"></label>
    <label class="text-sm">Redirect-URIs (eine pro Zeile, exakte Übereinstimmung, https)<textarea class="input w-full font-mono" name="redirect_uris" rows="3" required></textarea></label>
    <label class="text-sm">Post-Logout-URIs (optional, eine pro Zeile)<textarea class="input w-full font-mono" name="logout_uris" rows="2"></textarea></label>
    <label class="text-sm">Scopes<input class="input w-full" name="scopes" value="openid profile email roles"></label>
    <div class="grid gap-3 sm:grid-cols-3">
      <label class="text-sm">Typ<select class="input w-full" name="type"><option value="confidential">vertraulich (mit Secret)</option><option value="public">öffentlich (nur PKCE)</option></select></label>
      <label class="text-sm">Zugriff<select class="input w-full" name="access_policy"><option value="all">alle Benutzer</option><option value="assigned">nur mit Anwendungsrolle</option></select></label>
      <label class="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" name="skip_consent" value="1"> Einwilligung überspringen (interne App)</label>
    </div>
    <div><button class="btn btn-primary" type="submit">Registrieren</button></div>
  </form>
</section>
