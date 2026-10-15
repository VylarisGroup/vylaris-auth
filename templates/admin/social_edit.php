<?php $cur = '/admin/social'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold"><i class="bx <?= $e($types[$p['type']]['icon'] ?? 'bx-log-in-circle') ?>"></i> <?= $e($p['label']) ?></h1>
  <p class="mt-1 text-sm text-slate-500">Typ <?= $e($types[$p['type']]['label'] ?? $p['type']) ?> · Schlüssel <code><?= $e($p['provider_key']) ?></code> · <?= (int) $identities ?> Verknüpfung(en)</p>
  <p class="mt-3 text-sm">Redirect-URI beim Anbieter eintragen:</p>
  <p class="mt-1"><code class="select-all break-all rounded bg-slate-100 px-2 py-1 text-sm dark:bg-slate-800"><?= $e($redirectUri) ?></code></p>
  <?php if (!empty($types[$p['type']]['setup'])): ?><p class="mt-2 text-xs text-slate-500">Entwicklerkonsole: <?= $e($types[$p['type']]['setup']) ?></p><?php endif; ?>
  <form method="post" action="/admin/social/<?= $e($p['provider_key']) ?>" class="mt-4 grid gap-3" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="text-sm">Bezeichnung<input class="input w-full" name="label" value="<?= $e($p['label']) ?>" maxlength="80"></label>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Client-ID<input class="input w-full font-mono" name="client_id" value="<?= $e($p['client_id']) ?>" required maxlength="255"></label>
      <label class="text-sm">Client-Secret (leer lassen = unverändert)<input class="input w-full font-mono" type="password" name="client_secret" autocomplete="new-password"></label>
    </div>
    <?php if (in_array($p['type'], ['microsoft', 'gitlab', 'oidc'], true)): ?>
    <label class="text-sm"><?= $p['type'] === 'microsoft' ? 'Mandant' : 'Issuer-URL' ?><input class="input w-full font-mono" name="issuer" value="<?= $e($p['issuer'] ?? '') ?>" maxlength="255"></label>
    <?php endif; ?>
    <div class="flex flex-wrap gap-6 text-sm">
      <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" <?= (int) $p['enabled'] ? 'checked' : '' ?>> aktiv</label>
      <label class="flex items-center gap-2"><input type="checkbox" name="allow_signup" value="1" <?= (int) $p['allow_signup'] ? 'checked' : '' ?>> Registrierung über diesen Anbieter erlauben</label>
    </div>
    <div><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
  <form method="post" action="/admin/social/<?= $e($p['provider_key']) ?>/action" class="mt-6 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4 dark:border-slate-800">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="delete">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required> Anbieter samt <?= (int) $identities ?> Verknüpfung(en) endgültig löschen</label>
    <button class="btn btn-outline-danger btn-sm" type="submit">Löschen</button>
  </form>
</section>
