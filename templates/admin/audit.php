<?php $cur = '/admin/audit'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold">Audit-Log</h1>
  <form method="get" action="/admin/audit" class="mt-4 flex flex-wrap gap-2">
    <input class="input w-full sm:w-64" name="event" value="<?= $e($event) ?>" placeholder="Ereignis, z. B. auth.login_failed" maxlength="80">
    <input class="input w-full sm:w-40" name="user" value="<?= $e($uid ?? '') ?>" placeholder="Benutzer-ID" inputmode="numeric">
    <button class="btn btn-primary" type="submit">Filtern</button>
  </form>
  <?php require __DIR__ . '/_events.php'; ?>
  <p class="mt-3 text-xs text-slate-500">Das Log enthält keine Passwörter, Tokens oder Secrets.</p>
</section>
