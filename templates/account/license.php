<section class="card mx-auto max-w-2xl">
  <h1 class="text-xl font-semibold"><i class="bx bx-id-card"></i> Lizenz</h1>
  <?php if (!empty($flash)): ?><p class="alert alert-ok mt-3" role="status"><?= $e($flash) ?></p><?php endif; ?>
  <div class="mt-4"><?php require __DIR__ . '/../partials/errors.php'; ?></div>

  <?php if (!$required): ?>
    <p class="mt-2 text-sm text-slate-500">Für diese Installation ist kein Lizenzschlüssel erforderlich; alle Funktionen stehen zur Verfügung.</p>
  <?php elseif ($lic['keyless']): ?>
    <p class="mt-2 text-sm text-slate-500">Für Ihr Konto ist kein Lizenzschlüssel hinterlegt. Es nutzt den Bestandsschutz und belegt keinen Platz.</p>
    <form method="post" action="/account/license" class="mt-4 flex flex-wrap gap-2">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input class="input min-w-0 flex-1 font-mono" name="license_key" placeholder="<?= !empty($hasPool) ? 'Lizenzschlüssel (leer = hinterlegter Schlüssel)' : 'Lizenzschlüssel' ?>" maxlength="64" autocomplete="off" spellcheck="false" <?= empty($hasPool) ? 'required' : '' ?>>
      <button class="btn btn-primary" type="submit">Hinterlegen</button>
    </form>
  <?php else: ?>
    <dl class="mt-3 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 text-sm">
      <dt class="text-slate-500">Schlüssel</dt><dd class="font-mono"><?= $e($lic['key']) ?></dd>
      <dt class="text-slate-500">Plan</dt><dd><?= $e($lic['plan'] !== '' ? $lic['plan'] : '–') ?></dd>
      <dt class="text-slate-500">Status</dt>
      <dd><?= $lic['valid'] ? '<span class="text-emerald-500"><i class="bx bx-check-circle"></i> gültig</span>' : '<span class="text-red-500"><i class="bx bx-error-circle"></i> ' . $e(match ($lic['code']) { 'EXPIRED' => 'abgelaufen', 'BLOCKED' => 'gesperrt', 'UNVERIFIED' => 'nicht überprüfbar (Lizenzserver länger nicht erreicht)', default => 'ungültig (' . $lic['code'] . ')' }) . '</span>' ?></dd>
      <dt class="text-slate-500">Gültig bis</dt><dd><?= $e($lic['expires_at'] ?? 'unbegrenzt') ?></dd>
    </dl>
  <?php endif; ?>

  <?php if ($required): ?>
  <h2 class="mt-6 font-semibold">Freigeschaltete Funktionen</h2>
  <ul class="mt-2 divide-y divide-slate-200 text-sm dark:divide-slate-800">
    <?php foreach ($features as $name => $desc): $on = in_array($name, $lic['features'], true); ?>
    <li class="flex items-start gap-2 py-2">
      <i class="bx <?= $on ? 'bx-check-circle text-emerald-500' : 'bx-lock-alt text-slate-400' ?> mt-0.5"></i>
      <span><code><?= $e($name) ?></code> – <?= $e($desc) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <p class="mt-3 text-xs text-slate-500">Anmeldung, Zwei-Faktor-Schutz, Datenschutz-Funktionen und SSO sind unabhängig von der Lizenz immer verfügbar.</p>
  <?php endif; ?>
  <p class="mt-6 text-sm"><a class="link" href="/account">← Zurück zum Konto</a></p>
</section>
