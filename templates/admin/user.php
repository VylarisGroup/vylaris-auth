<?php $cur = '/admin/users'; require __DIR__ . '/_nav.php'; $act = "/admin/users/" . (int) $u['id'] . "/action"; ?>
<section class="card">
  <h1 class="text-xl font-semibold"><?= $e($u['display_name']) ?></h1>
  <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
    <dt class="text-slate-500">E-Mail</dt><dd><?= $e($u['email']) ?> (<?= $u['email_verified_at'] ? 'verifiziert' : 'unverifiziert' ?>)</dd>
    <dt class="text-slate-500">Status</dt><dd><?= $e($u['status']) ?><?= !empty($u['locked_reason']) ? ' – ' . $e($u['locked_reason']) : '' ?></dd>
    <dt class="text-slate-500">Letzte Anmeldung</dt><dd><?= $e($u['last_login_at'] ?? '–') ?> UTC</dd>
    <dt class="text-slate-500">MFA</dt><dd>TOTP: <?= $mfa['totp'] ? 'ja' : 'nein' ?> · Passkeys: <?= (int) $mfa['passkeys'] ?></dd>
    <dt class="text-slate-500">Rollen</dt><dd><?= $e(implode(', ', $roles) ?: '–') ?></dd>
  </dl>
</section>
<?php if ($canManage): ?>
<section class="card mt-6">
  <h2 class="font-semibold">Aktionen</h2>
  <div class="mt-3 flex flex-wrap items-end gap-3">
    <?php if ($u['status'] === 'locked'): ?>
    <form method="post" action="<?= $e($act) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="unlock"><button class="btn btn-primary" type="submit">Entsperren</button></form>
    <?php else: ?>
    <form method="post" action="<?= $e($act) ?>" class="flex gap-2"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="lock"><input class="input w-full" name="reason" placeholder="Grund" maxlength="200" required><button class="btn btn-danger" type="submit">Sperren</button></form>
    <?php endif; ?>
    <form method="post" action="<?= $e($act) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="revoke_sessions"><button class="btn btn-secondary" type="submit">Alle Sitzungen beenden</button></form>
    <form method="post" action="<?= $e($act) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="reset_mfa"><button class="btn btn-secondary" type="submit">MFA zurücksetzen</button></form>
  </div>
  <h3 class="mt-5 text-sm font-semibold">Globale Rollen</h3>
  <div class="mt-2 flex flex-wrap gap-2">
    <?php foreach ($roles as $r): ?>
    <form method="post" action="<?= $e($act) ?>" class="inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="revoke_role"><input type="hidden" name="role" value="<?= $e($r) ?>"><button class="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800" type="submit" title="Rolle entziehen"><?= $e($r) ?> ✕</button></form>
    <?php endforeach; ?>
    <form method="post" action="<?= $e($act) ?>" class="flex gap-2"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="grant_role">
      <select class="input w-full" name="role"><?php foreach (($isSuper ? ['user', 'admin', 'superadmin'] : ['user']) as $r): ?><option><?= $e($r) ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary" type="submit">Vergeben</button></form>
  </div>
</section>
<?php elseif (!$self): ?>
<p class="mt-6 text-sm text-slate-500">Dieses Konto kann nur von Superadmins verwaltet werden.</p>
<?php endif; ?>
<?php if ($canManage): ?>
<section class="card mt-6">
  <h2 class="font-semibold"><i class="bx bx-shield-quarter"></i> Datenschutz (DSGVO)</h2>
  <p class="mt-1 text-sm text-slate-500">Auskunft (Art. 15/20) und Löschung (Art. 17) für Betroffenenanfragen. Prüfen Sie vorher die Identität der anfragenden Person. Jede Aktion wird protokolliert.</p>
  <div class="mt-3 flex flex-wrap items-end gap-3">
    <form method="post" action="/admin/users/<?= (int) $u['id'] ?>/export"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn-secondary" type="submit"><i class="bx bx-download"></i> Daten exportieren (JSON)</button></form>
    <form method="post" action="/admin/users/<?= (int) $u['id'] ?>/erase" class="flex flex-wrap items-end gap-2"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="text-sm">Zum Löschen E-Mail-Adresse des Kontos eingeben<input class="input w-full" name="confirm_email" autocomplete="off" required></label>
      <button class="btn btn-danger" type="submit" data-confirm="Konto und alle zugehörigen Daten unwiderruflich löschen?"><i class="bx bx-trash"></i> Konto löschen</button></form>
  </div>
</section>
<section class="card mt-6">
  <h2 class="font-semibold">Anwendungsrollen</h2>
  <?php foreach ($apps as $a): ?>
    <div class="mt-3 border-t border-slate-200 pt-3 dark:border-slate-800">
      <p class="text-sm font-medium"><?= $e($a['client']['name']) ?> <span class="text-xs text-slate-500">(<?= $e($a['client']['access_policy'] === 'assigned' ? 'nur mit Rolle' : 'alle Benutzer') ?>)</span></p>
      <div class="mt-1 flex flex-wrap gap-2">
      <?php foreach ($a['roles'] as $r): $has = in_array($r['name'], $a['assigned'], true); ?>
        <form method="post" action="/admin/users/<?= (int) $u['id'] ?>/app-role" class="inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="client" value="<?= (int) $a['client']['id'] ?>"><input type="hidden" name="role" value="<?= (int) $r['id'] ?>"><input type="hidden" name="op" value="<?= $has ? 'unassign' : 'assign' ?>">
          <button class="rounded-full px-3 py-1 text-xs <?= $has ? 'bg-brand-600 text-white' : 'bg-slate-100 dark:bg-slate-800' ?>" type="submit"><?= $e($r['name']) ?><?= $has ? ' ✓' : '' ?></button></form>
      <?php endforeach; ?>
      <?php if (!$a['roles']): ?><span class="text-xs text-slate-500">Keine Rollen definiert.</span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$apps): ?><p class="mt-2 text-sm text-slate-500">Keine Anwendungen.</p><?php endif; ?>
</section>
<?php endif; ?>
<section class="card mt-6">
  <h2 class="font-semibold">Aktive Sitzungen</h2>
  <ul class="list-rows mt-2 divide-y divide-slate-200 text-sm dark:divide-slate-800">
  <?php foreach ($sessions as $s): ?><li class="py-2"><?= $e($s['ip'] ?? '–') ?> · <?= $e(mb_substr((string) $s['user_agent'], 0, 80)) ?> · zuletzt <?= $e($s['last_seen_at']) ?> UTC</li><?php endforeach; ?>
  <?php if (!$sessions): ?><li class="py-2 text-slate-500">Keine aktiven Sitzungen.</li><?php endif; ?>
  </ul>
</section>
<section class="card mt-6">
  <h2 class="font-semibold">Letzte Ereignisse</h2>
  <?php $events = $audit; require __DIR__ . '/_events.php'; ?>
</section>
