<?php $cur = '/admin/social'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold">Social Login</h1>
  <p class="mt-1 text-sm text-slate-500">Externe Anbieter, mit denen sich Benutzer anmelden können. Client-Secrets werden verschlüsselt gespeichert und nie wieder angezeigt.</p>
  <ul class="list-rows mt-4 divide-y divide-slate-200 dark:divide-slate-800">
  <?php foreach ($providers as $p): ?>
    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
      <div>
        <a class="link" href="/admin/social/<?= $e($p['provider_key']) ?>"><i class="bx <?= $e($p['icon']) ?>"></i> <?= $e($p['label']) ?></a>
        <p class="text-xs text-slate-500"><?= $e($p['provider_key']) ?> · <?= (int) $p['enabled'] ? 'aktiv' : 'deaktiviert' ?> · <?= (int) $p['identities'] ?> Verknüpfung(en)</p>
      </div>
      <form method="post" action="/admin/social/<?= $e($p['provider_key']) ?>/action">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="<?= (int) $p['enabled'] ? 'disable' : 'enable' ?>">
        <button class="btn btn-secondary" type="submit"><?= (int) $p['enabled'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
      </form>
    </li>
  <?php endforeach; ?>
  <?php if (!$providers): ?><li class="py-3 text-sm text-slate-500">Noch keine Anbieter eingerichtet.</li><?php endif; ?>
  </ul>
</section>
<section class="card mt-6">
  <h2 class="font-semibold">Anbieter hinzufügen</h2>
  <form method="post" action="/admin/social" class="mt-3 grid gap-3" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Typ<select class="input w-full" name="type">
        <?php foreach ($types as $k => $t): ?><option value="<?= $e($k) ?>"><?= $e($t['label']) ?></option><?php endforeach; ?>
      </select></label>
      <label class="text-sm">Schlüssel (optional, Standard: Typ; für mehrere Anbieter desselben Typs)<input class="input w-full" name="provider_key" maxlength="40" pattern="[a-z0-9][a-z0-9\-]{1,39}"></label>
    </div>
    <label class="text-sm">Bezeichnung (Button-Text)<input class="input w-full" name="label" maxlength="80"></label>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Client-ID<input class="input w-full font-mono" name="client_id" required maxlength="255"></label>
      <label class="text-sm">Client-Secret<input class="input w-full font-mono" type="password" name="client_secret" required autocomplete="new-password"></label>
    </div>
    <label class="text-sm">Issuer-URL (GitLab / OpenID Connect) bzw. Mandant (Microsoft: common, organizations, consumers oder Verzeichnis-ID)<input class="input w-full font-mono" name="issuer" maxlength="255" placeholder="https://id.example.org"></label>
    <div class="flex flex-wrap gap-6 text-sm">
      <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1"> aktiv</label>
      <label class="flex items-center gap-2"><input type="checkbox" name="allow_signup" value="1" checked> Registrierung über diesen Anbieter erlauben</label>
    </div>
    <div><button class="btn btn-primary" type="submit">Anlegen</button></div>
  </form>
</section>
