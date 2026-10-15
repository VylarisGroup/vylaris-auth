<?php $cur = '/admin/update'; require __DIR__ . '/_nav.php'; $fmt = static fn (?int $t): string => $t ? gmdate('d.m.Y H:i', $t) . ' UTC' : '–'; ?>
<section class="card">
  <h1 class="text-xl font-semibold"><i class="bx bx-cloud-download"></i> Update</h1>
  <dl class="mt-3 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 text-sm">
    <dt class="text-slate-500">Installierte Version</dt><dd class="font-mono"><?= $e($current) ?></dd>
    <dt class="text-slate-500">Quelle</dt><dd><?= $configured ? '<span class="font-mono">github.com/' . $e($repo) . '</span> · ' . ($private ? 'privat (Token hinterlegt)' : 'öffentlich') : '<span class="text-red-500">nicht eingerichtet</span>' ?></dd>
    <?php if ($latest): ?>
    <dt class="text-slate-500">Zuletzt geprüft</dt><dd><?= $e($fmt((int) $latest['checked_at'])) ?><?= !empty($latest['error']) ? ' · <span class="text-red-500">' . $e($latest['error']) . '</span>' : '' ?></dd>
    <?php endif; ?>
  </dl>

  <?php if (!$configured): ?>
    <p class="alert alert-err mt-4">Die Aktualisierung ist nicht eingerichtet. Tragen Sie in <code>config/config.php</code> den Block <code>update</code> ein (<code>repo</code> = <code>besitzer/name</code>, bei privaten Repositories zusätzlich <code>token</code>). Siehe docs/UPDATE.md.</p>
  <?php elseif (!$zip): ?>
    <p class="alert alert-err mt-4">Die PHP-Erweiterung <code>zip</code> fehlt – Updates sind auf diesem Server nicht möglich.</p>
  <?php else: ?>
    <?php if ($pending || $locked): ?>
      <p class="alert alert-err mt-4" role="alert">Ein Update ist unvollständig (Wartungsmodus aktiv). <a class="link" href="/admin/update/finish">Update abschließen</a> oder unten zurückstellen.</p>
    <?php endif; ?>
    <form method="post" action="/admin/update/check" class="mt-4">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-secondary" type="submit"><i class="bx bx-refresh"></i> Jetzt auf neue Version prüfen</button>
    </form>
  <?php endif; ?>
</section>

<?php if ($available && $zip && !$pending && !$locked): ?>
<section class="card mt-6">
  <h2 class="font-semibold"><i class="bx bx-up-arrow-circle"></i> Version <?= $e($available['version']) ?> verfügbar</h2>
  <?php if (!empty($available['name']) && $available['name'] !== $available['tag']): ?><p class="mt-1 text-sm"><?= $e($available['name']) ?></p><?php endif; ?>
  <?php if (!empty($available['notes'])): ?><pre class="mt-3 max-h-64 overflow-auto whitespace-pre-wrap rounded-lg border border-slate-200 p-3 text-xs dark:border-slate-800"><?= $e($available['notes']) ?></pre><?php endif; ?>
  <?php if (!empty($available['html_url'])): ?><p class="mt-2 text-sm"><a class="link" href="<?= $e($available['html_url']) ?>" target="_blank" rel="noopener noreferrer">Release auf GitHub ansehen</a></p><?php endif; ?>
  <p class="mt-3 text-sm text-slate-500">Vor der Installation wird eine Sicherung der betroffenen Dateien angelegt, das Archiv und die PHP-Syntax werden geprüft, danach läuft kurz der Wartungsmodus. Scheitert etwas, werden die Dateien automatisch zurückgestellt. <strong>Machen Sie vorher eine Datenbanksicherung</strong> – Migrationen werden nicht rückgängig gemacht. Die Änderungen stammen aus Ihrem Repository: prüfen Sie das Release, bevor Sie installieren.</p>
  <form method="post" action="/admin/update/install" class="mt-4" data-confirm="Update auf Version <?= $e($available['version']) ?> jetzt installieren? Das System ist währenddessen kurz nicht erreichbar.">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($available['version']) ?>">
    <button class="btn btn-primary" type="submit"><i class="bx bx-cloud-download"></i> Auf Version <?= $e($available['version']) ?> aktualisieren</button>
  </form>
</section>
<?php endif; ?>

<?php if ($last): ?>
<section class="card mt-6">
  <h2 class="font-semibold">Letztes Update</h2>
  <p class="mt-1 text-sm"><?= $e(match ($last['status']) { 'done' => 'Erfolgreich', 'rolled_back' => 'Zurückgestellt', default => 'Fehlgeschlagen' }) ?>: <?= $e($last['from']) ?> → <?= $e($last['to']) ?> · <?= $e($fmt((int) $last['at'])) ?></p>
  <?php if (!empty($last['message'])): ?><p class="mt-1 text-sm text-slate-500"><?= $e($last['message']) ?></p><?php endif; ?>
  <?php if (!empty($last['migrations'])): ?><p class="mt-1 text-xs text-slate-500">Migrationen: <?= $e(implode(', ', $last['migrations'])) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($backups): ?>
<section class="card mt-6">
  <h2 class="font-semibold">Sicherungen</h2>
  <p class="mt-1 text-sm text-slate-500">Die letzten Sicherungen der ersetzten Dateien (ohne Datenbank, Konfiguration und Schlüssel). Zurückstellen ändert die Datenbank nicht; Migrationen sind additiv, ältere Programmstände laufen damit in der Regel weiter.</p>
  <ul class="mt-3 divide-y divide-slate-200 text-sm dark:divide-slate-800">
    <?php foreach ($backups as $b): ?>
    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
      <span><span class="font-mono"><?= $e($b['file']) ?></span> · <?= $e($fmt($b['time'])) ?> · <?= $e(number_format($b['size'] / 1024, 0, ',', "'")) ?> KB</span>
      <form method="post" action="/admin/update/rollback" data-confirm="Dateien aus dieser Sicherung wiederherstellen?">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="backup" value="<?= $e($b['file']) ?>">
        <button class="btn btn-secondary" type="submit"><i class="bx bx-undo"></i> Zurückstellen</button>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
