<?php $cur = '/admin/license'; require __DIR__ . '/_nav.php'; ?>
<section class="card">
  <h1 class="text-xl font-semibold"><i class="bx bx-id-card"></i> Lizenz</h1>
  <dl class="mt-3 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 text-sm">
    <dt class="text-slate-500">Lizenzpflicht</dt><dd><?= $required ? 'aktiv (jedes neue Konto belegt einen Platz aus dem Lizenzpool)' : 'aus (license.required = false)' ?></dd>
    <dt class="text-slate-500">Lizenzserver</dt><dd class="font-mono"><?= $e($apiUrl) ?></dd>
    <dt class="text-slate-500">Produkt</dt><dd class="font-mono"><?= $e($product) ?></dd>
    <dt class="text-slate-500">Öffentlicher Schlüssel</dt>
    <dd><?= $configured && $fingerprint !== '' ? '<span class="text-emerald-500"><i class="bx bx-check-circle"></i> hinterlegt</span> <span class="font-mono text-xs text-slate-500">' . $e($fingerprint) . '</span>' : '<span class="text-red-500"><i class="bx bx-error-circle"></i> fehlt oder ungültig – ohne gültigen Schlüssel sind keine Registrierungen möglich</span>' ?></dd>
    <dt class="text-slate-500">Konten ohne Schlüssel</dt><dd><?= (int) $keyless ?> (Bestandsschutz, belegen keinen Platz)</dd>
    <?php if ($invalid > 0): ?><dt class="text-slate-500">Ohne gültige Lizenz</dt><dd class="text-red-500"><?= (int) $invalid ?> Nutzer – sobald ein Pool-Schlüssel mit freiem Platz verfügbar ist, werden sie automatisch umgestuft</dd><?php endif; ?>
    <?php if ($releases > 0): ?><dt class="text-slate-500">Offene Freigaben</dt><dd><?= (int) $releases ?> (werden per Cron wiederholt)</dd><?php endif; ?>
  </dl>
  <form method="post" action="/admin/license/refresh" class="mt-4">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button class="btn btn-secondary" type="submit"><i class="bx bx-refresh"></i> Alle jetzt beim Lizenzserver prüfen</button>
  </form>
</section>

<section class="card mt-6">
  <h2 class="font-semibold"><i class="bx bx-key"></i> Lizenzpool</h2>
  <p class="mt-1 text-sm text-slate-500">Hier – und nur hier – tragen Sie Ihre Lizenzschlüssel ein. Bei der Nutzererstellung wird die Lizenz aus diesem Pool gewählt (automatisch der erste Schlüssel mit freiem Platz oder gezielt per Auswahl). Die Schlüssel werden regelmäßig beim Lizenzserver geprüft: Wird eine Lizenz ungültig (abgelaufen, gesperrt, Platz zurückgesetzt), werden ihre Nutzer automatisch auf den nächsten verfügbaren Schlüssel umgestuft.</p>
  <div class="table-wrap mt-3"><table class="w-full text-left text-sm">
    <thead class="text-xs uppercase tracking-wide text-slate-400"><tr><th class="py-1 pr-3">Schlüssel</th><th class="pr-3">Status</th><th class="pr-3">Plan</th><th class="pr-3">Plätze</th><th class="pr-3">Gültig bis</th><th class="pr-3">Geprüft</th><th></th></tr></thead>
    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
    <?php foreach ($pool as $p): ?>
      <tr><td class="py-2 pr-3 font-mono"><?= $e($p['key']) ?></td>
        <td class="pr-3"><?= $p['valid'] === null ? '<span class="text-slate-400">noch nicht geprüft</span>' : ($p['valid'] ? '<span class="text-emerald-500"><i class="bx bx-check-circle"></i> gültig</span>' : '<span class="text-red-500"><i class="bx bx-error-circle"></i> ' . $e(match ($p['code']) { 'EXPIRED' => 'abgelaufen', 'BLOCKED' => 'gesperrt', 'NOT_FOUND' => 'unbekannt', 'WRONG_PRODUCT' => 'falsches Produkt', default => $p['code'] }) . '</span>') ?></td>
        <td class="pr-3"><?= $e($p['plan'] ?? '–') ?></td>
        <td class="pr-3"><?= (int) max($p['seats'], (int) ($p['devices'] ?? 0)) ?><?= $p['max'] !== null ? ' / ' . (int) $p['max'] : '' ?></td>
        <td class="pr-3"><?= $e($p['expires_at'] ?? 'unbegrenzt') ?></td>
        <td class="pr-3"><?= $e($p['checked_at'] ?? '–') ?></td>
        <td><form method="post" action="/admin/license/keys/<?= (int) $p['id'] ?>/remove"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Schlüssel entfernen? Bestehende Konten behalten ihren Platz, werden aber bei ungültiger Lizenz nicht mehr auf diesen Schlüssel umgestuft.">Entfernen</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$pool): ?><tr><td colspan="7" class="py-2 text-slate-500">Noch kein Schlüssel hinterlegt – ohne Pool können keine neuen Konten angelegt werden.</td></tr><?php endif; ?>
    </tbody></table></div>
  <form method="post" action="/admin/license/keys" class="mt-4 flex flex-wrap gap-2">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input class="input min-w-0 flex-1 font-mono" name="license_key" placeholder="Lizenzschlüssel hinterlegen" maxlength="64" autocomplete="off" spellcheck="false" required>
    <button class="btn btn-primary" type="submit"><i class="bx bx-plus"></i> Hinterlegen</button>
  </form>
</section>

<section class="card mt-6">
  <h2 class="font-semibold">Belegte Plätze je Lizenzschlüssel</h2>
  <div class="table-wrap mt-3"><table class="w-full text-left text-sm">
    <thead class="text-xs uppercase tracking-wide text-slate-400"><tr><th class="py-1 pr-3">Schlüssel</th><th class="pr-3">Plan</th><th class="pr-3">Plätze</th><th class="pr-3">Status</th><th>Zuletzt geprüft</th></tr></thead>
    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
    <?php foreach ($seats as $s): ?>
      <tr><td class="py-2 pr-3 font-mono"><?= $e($s['license_key']) ?></td><td class="pr-3"><?= $e($s['plan'] ?? '–') ?></td><td class="pr-3"><?= (int) $s['seats'] ?><?= $s['max_devices'] !== null ? ' / ' . (int) $s['max_devices'] : '' ?></td><td class="pr-3"><?= (int) $s['valid'] === 1 ? 'gültig' : 'ungültig' ?></td><td><?= $e($s['checked_at']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$seats): ?><tr><td colspan="5" class="py-2 text-slate-500">Noch keine Lizenzschlüssel in Verwendung.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card mt-6">
  <h2 class="font-semibold">Scopes (Funktionen)</h2>
  <p class="mt-1 text-sm text-slate-500">Tragen Sie die Namen im Feld <code>features</code> des Tarifs im Lizenzserver ein. Nur enthaltene Funktionen sind für Konten mit diesem Schlüssel nutzbar; Admin-Funktionen richten sich nach dem Schlüssel des ausführenden Administrators.</p>
  <ul class="mt-3 divide-y divide-slate-200 text-sm dark:divide-slate-800">
    <?php foreach ($features as $name => $desc): ?>
    <li class="py-2"><code><?= $e($name) ?></code> – <?= $e($desc) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
