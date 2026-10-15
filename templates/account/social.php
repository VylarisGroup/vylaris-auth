<section class="card mx-auto max-w-2xl">
  <h1 class="text-xl font-semibold"><i class="bx bx-link"></i> Verknüpfte Anmeldungen</h1>
  <p class="mt-1 text-sm text-slate-500">Melden Sie sich zusätzlich über Konten externer Anbieter an. Ihre Rollen und Berechtigungen bleiben an dieses Konto gebunden.</p>
  <?php if (!empty($flash)): ?><p class="alert alert-ok mt-3" role="status"><?= $e($flash) ?></p><?php endif; ?>
  <div class="mt-4"><?php require __DIR__ . '/../partials/errors.php'; ?></div>

  <h2 class="mt-4 font-semibold">Verknüpft</h2>
  <ul class="list-rows mt-2 divide-y divide-slate-200 dark:divide-slate-800">
  <?php foreach ($identities as $i): ?>
    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
      <div>
        <p class="font-medium"><?= $e($i['label'] ?? $i['provider_key']) ?></p>
        <p class="text-xs text-slate-500"><?= $e($i['display_name'] ?? '') ?><?= !empty($i['email']) ? ' · ' . $e($i['email']) : '' ?> · seit <?= $e($i['created_at']) ?></p>
      </div>
      <form method="post" action="/account/social/<?= $e($i['provider_key']) ?>/unlink" class="flex items-center gap-2">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?php if ($passwordSet): ?><input class="input input-sm w-40" type="password" name="password" placeholder="Passwort" autocomplete="current-password" required><?php endif; ?>
        <button class="btn btn-outline-danger btn-sm" type="submit">Trennen</button>
      </form>
    </li>
  <?php endforeach; ?>
  <?php if (!$identities): ?><li class="py-3 text-sm text-slate-500">Noch nichts verknüpft.</li><?php endif; ?>
  </ul>

  <h2 class="mt-6 font-semibold">Verfügbar</h2>
  <ul class="list-rows mt-2 divide-y divide-slate-200 dark:divide-slate-800">
  <?php foreach ($available as $p): ?>
    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
      <span class="font-medium"><i class="bx <?= $e($p['icon']) ?> text-xl"></i> <?= $e($p['label']) ?></span>
      <form method="post" action="/account/social/<?= $e($p['provider_key']) ?>/link" class="flex items-center gap-2">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?php if ($passwordSet): ?><input class="input input-sm w-40" type="password" name="password" placeholder="Passwort" autocomplete="current-password" required><?php endif; ?>
        <button class="btn btn-primary" type="submit">Verknüpfen</button>
      </form>
    </li>
  <?php endforeach; ?>
  <?php if (!$available): ?><li class="py-3 text-sm text-slate-500">Keine weiteren Anbieter verfügbar.</li><?php endif; ?>
  </ul>
  <?php if (!$passwordSet): ?>
  <p class="mt-4 text-sm text-slate-500">Dieses Konto hat noch kein Passwort. Sie können jederzeit über <a class="link" href="/password/forgot">„Passwort vergessen“</a> eines festlegen.</p>
  <?php endif; ?>
  <p class="mt-4 text-sm"><a class="link" href="/account">← Zurück zum Konto</a></p>
</section>
