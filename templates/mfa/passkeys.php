<section class="card mx-auto max-w-lg">
  <h1 class="text-xl font-semibold"><i class="bx bx-fingerprint"></i> Passkeys</h1>
  <div class="mt-4"><?php require __DIR__ . '/../partials/errors.php'; ?></div>
  <ul class="list-rows divide-y divide-slate-200 dark:divide-slate-800">
    <?php foreach ($passkeys as $p): ?>
    <li class="flex items-center justify-between py-3">
      <div>
        <p class="font-medium"><?= $e($p['name']) ?></p>
        <p class="text-xs text-slate-500">Erstellt <?= $e($p['created_at']) ?> UTC<?= $p['last_used_at'] ? ' · zuletzt genutzt ' . $e($p['last_used_at']) . ' UTC' : '' ?></p>
      </div>
      <form method="post" action="/account/mfa/passkeys/delete" class="flex items-center gap-2">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <input class="input input-sm w-32 rounded" type="password" name="password" placeholder="Passwort" autocomplete="current-password" required>
        <button class="link-danger" type="submit">Entfernen</button>
      </form>
    </li>
    <?php endforeach; ?>
    <?php if (!$passkeys): ?><li class="py-3 text-sm text-slate-500">Noch keine Passkeys registriert.</li><?php endif; ?>
  </ul>
  <div class="mt-6 space-y-3">
    <input id="passkey-name" class="input w-full" placeholder="Name, z. B. „MacBook Touch ID“" maxlength="120">
    <button id="passkey-register" class="btn btn-primary w-full" type="button"><i class="bx bx-plus"></i> Passkey hinzufügen</button>
    <p id="passkey-error" class="hidden text-sm text-red-600" role="alert"></p>
    <div id="passkey-codes" class="hidden"></div>
  </div>
</section>
<script src="/assets/passkey.js" defer></script>
