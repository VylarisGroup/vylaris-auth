<div class="alert alert-warn p-5 mt-4 rounded-lg p-4 dark:border-amber-800" role="alert">
  <p class="font-medium"><i class="bx bx-error"></i> Recovery-Codes – nur jetzt sichtbar</p>
  <p class="mt-1 text-sm">Bewahren Sie diese Codes sicher auf (z. B. im Passwortmanager). Jeder Code funktioniert genau einmal.</p>
  <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">
    <?php foreach ($codes as $c): ?><li class="rounded bg-white px-2 py-1 text-center dark:bg-slate-900"><?= $e($c) ?></li><?php endforeach; ?>
  </ul>
</div>
