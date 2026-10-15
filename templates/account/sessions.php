<section class="card mx-auto max-w-3xl">
  <div class="flex items-center justify-between">
    <h1 class="text-xl font-semibold"><i class="bx bx-devices"></i> Angemeldete Geräte</h1>
    <form method="post" action="/account/sessions/revoke-all">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-outline-danger btn-sm py-1.5" type="submit">Alle anderen abmelden</button>
    </form>
  </div>
  <?php if (!empty($flash)): ?><p class="alert alert-ok mt-3" role="status"><?= $e($flash) ?></p><?php endif; ?>
  <ul class="list-rows mt-4 divide-y divide-slate-200 dark:divide-slate-800">
    <?php foreach ($sessions as $s): ?>
    <li class="flex items-center justify-between py-3">
      <div>
        <p class="font-medium"><?= $e($s['device']) ?><?= $s['current'] ? ' (dieses Gerät)' : '' ?></p>
        <p class="text-xs text-slate-500">IP <?= $e($s['ip']) ?> · zuletzt aktiv <?= $e($s['last_seen_at']) ?> UTC</p>
      </div>
      <?php if (!$s['current']): ?>
      <form method="post" action="/account/sessions/revoke">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="session" value="<?= $e($s['id']) ?>">
        <button class="link-danger" type="submit">Beenden</button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
