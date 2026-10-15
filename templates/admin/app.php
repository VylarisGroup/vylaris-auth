<?php $cur = '/admin/apps'; require __DIR__ . '/_nav.php'; $act = '/admin/apps/' . (int) $c['id'] . '/action'; $gone = $c['revoked_at'] !== null; ?>
<?php if (!empty($newSecret)): ?>
<section class="alert alert-warn p-5 mb-6" role="alert">
  <h2 class="font-semibold"><i class="bx bx-key"></i> Neues Client-Secret</h2>
  <p class="mt-2 text-sm"><code class="select-all break-all"><?= $e($newSecret) ?></code></p>
  <p class="mt-2 text-xs">Wird nur jetzt angezeigt. Das alte Secret ist ungültig.</p>
</section>
<?php endif; ?>
<section class="card">
  <h1 class="text-xl font-semibold"><?= $e($c['name']) ?></h1>
  <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
    <dt class="text-slate-500">Client-ID</dt><dd class="break-all font-mono"><?= $e($c['client_id']) ?></dd>
    <dt class="text-slate-500">Status</dt><dd><?= $gone ? 'widerrufen' : ((int) $c['is_active'] ? 'aktiv' : 'deaktiviert') ?></dd>
    <dt class="text-slate-500">Typ</dt><dd><?= (int) $c['is_confidential'] ? 'vertraulich' : 'öffentlich (PKCE)' ?></dd>
    <dt class="text-slate-500">Issuer</dt><dd class="break-all font-mono"><?= $e($issuer) ?></dd>
    <dt class="text-slate-500">Discovery</dt><dd class="break-all font-mono"><?= $e($issuer) ?>/.well-known/openid-configuration</dd>
  </dl>
</section>
<?php if (!$gone): ?>
<section class="card mt-6">
  <h2 class="font-semibold">Einstellungen</h2>
  <form method="post" action="<?= $e($act) ?>" class="mt-3 grid gap-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="settings">
    <label class="text-sm">Name<input class="input w-full" name="name" value="<?= $e($c['name']) ?>" required maxlength="120"></label>
    <label class="text-sm">Erlaubte Scopes<input class="input w-full" name="scopes" value="<?= $e($c['allowed_scopes']) ?>"></label>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Zugriff<select class="input w-full" name="access_policy"><option value="all" <?= $c['access_policy'] === 'all' ? 'selected' : '' ?>>alle Benutzer</option><option value="assigned" <?= $c['access_policy'] === 'assigned' ? 'selected' : '' ?>>nur mit Anwendungsrolle</option></select></label>
      <label class="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" name="skip_consent" value="1" <?= (int) $c['skip_consent'] ? 'checked' : '' ?>> Einwilligung überspringen</label>
    </div>
    <div><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>
<section class="card mt-6">
  <h2 class="font-semibold">Redirect-URIs</h2>
  <form method="post" action="<?= $e($act) ?>" class="mt-3 grid gap-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="uris">
    <label class="text-sm">Redirect-URIs<textarea class="input w-full font-mono" name="redirect_uris" rows="3" required><?= $e(implode("\n", $redirects)) ?></textarea></label>
    <label class="text-sm">Post-Logout-URIs<textarea class="input w-full font-mono" name="logout_uris" rows="2"><?= $e(implode("\n", $logouts)) ?></textarea></label>
    <div><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>
<section class="card mt-6">
  <h2 class="font-semibold">Anwendungsrollen</h2>
  <ul class="list-rows mt-2 divide-y divide-slate-200 text-sm dark:divide-slate-800">
  <?php foreach ($roles as $r): ?>
    <li class="flex items-center justify-between py-2"><span><span class="font-mono"><?= $e($r['name']) ?></span> <span class="text-slate-500"><?= $e($r['description'] ?? '') ?></span></span>
      <form method="post" action="<?= $e($act) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="role_delete"><input type="hidden" name="role_id" value="<?= (int) $r['id'] ?>"><button class="text-red-600 hover:underline" type="submit">Löschen</button></form></li>
  <?php endforeach; ?>
  <?php if (!$roles): ?><li class="py-2 text-slate-500">Keine Rollen.</li><?php endif; ?>
  </ul>
  <form method="post" action="<?= $e($act) ?>" class="mt-3 grid gap-2 sm:grid-cols-3">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="role_create">
    <input class="input w-full" name="role_name" placeholder="rolle" required maxlength="59"><input class="input w-full" name="role_description" placeholder="Beschreibung" maxlength="255"><button class="btn btn-primary" type="submit">Rolle anlegen</button>
  </form>
</section>
<section class="card mt-6">
  <h2 class="font-semibold">Sicherheit</h2>
  <div class="mt-3 flex flex-wrap gap-3">
    <?php if ((int) $c['is_confidential']): ?><form method="post" action="<?= $e($act) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="rotate_secret"><button class="btn btn-secondary" type="submit">Secret erneuern</button></form><?php endif; ?>
    <form method="post" action="<?= $e($act) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="<?= (int) $c['is_active'] ? 'deactivate' : 'activate' ?>"><button class="btn btn-secondary" type="submit"><?= (int) $c['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
    <form method="post" action="<?= $e($act) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="revoke"><button class="btn btn-danger" type="submit">Endgültig widerrufen</button></form>
  </div>
</section>
<?php endif; ?>
