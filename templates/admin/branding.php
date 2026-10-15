<?php
$cur = '/admin/branding'; require __DIR__ . '/_nav.php';
$presetOrHex = static fn (string $val): bool => (bool) preg_match('/^#[0-9a-f]{6}$/', $val);
$labels = ['sharp' => 'Eckig', 'normal' => 'Normal', 'round' => 'Rund', 'modern' => 'Modern (Inter, falls installiert)', 'system' => 'System', 'serif' => 'Serif', 'mono' => 'Monospace'];
?>
<form method="post" action="/admin/branding" enctype="multipart/form-data" class="grid gap-6" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <section class="card">
    <h1 class="text-xl font-semibold">Branding</h1>
    <p class="mt-1 text-sm text-slate-500">Name, Logo, Farben, Texte und Theme dieser Installation. Platzhalter: <code>{app}</code> (Name) und <code>{org}</code> (Organisation).</p>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Name der Anwendung<input class="input w-full" name="name" maxlength="60" required value="<?= $e($v['name']) ?>"></label>
      <label class="text-sm">Organisation<input class="input w-full" name="org" maxlength="80" value="<?= $e($v['org']) ?>"></label>
      <label class="text-sm sm:col-span-2">Slogan (optional)<input class="input w-full" name="tagline" maxlength="160" value="<?= $e($v['tagline']) ?>"></label>
    </div>
  </section>

  <section class="card">
    <h2 class="font-semibold">Logo &amp; Favicon</h2>
    <p class="mt-1 text-sm text-slate-500">PNG, JPEG, WebP oder SVG (max. 512 KiB; SVG max. 200 KiB, ohne Skripte). Favicon zusätzlich ICO. Ein Logo für dunklen Hintergrund ist optional.</p>
    <div class="mt-4 grid gap-4 sm:grid-cols-3">
      <?php foreach (['logo' => 'Logo (heller Hintergrund)', 'logo_dark' => 'Logo (dunkler Hintergrund)', 'favicon' => 'Favicon'] as $slot => $label): ?>
      <div class="text-sm">
        <label for="f-<?= $e($slot) ?>"><?= $e($label) ?></label>
        <input id="f-<?= $e($slot) ?>" class="input mt-1 w-full" type="file" name="<?= $e($slot) ?>" accept="<?= $slot === 'favicon' ? '.png,.ico,.svg,.webp,.jpg,.jpeg' : '.png,.svg,.webp,.jpg,.jpeg' ?>">
        <?php if ($v[$slot] !== ''): ?>
        <p class="mt-1 text-xs text-slate-500">Aktuell: <?= $e(strtoupper($v[$slot])) ?> · <label class="inline-flex items-center gap-1"><input type="checkbox" name="remove_<?= $e($slot) ?>" value="1"> entfernen</label></p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <label class="mt-4 flex items-center gap-2 text-sm"><input type="checkbox" name="show_name" value="1" <?= $v['show_name'] === '1' ? 'checked' : '' ?>> Namen neben dem Logo anzeigen</label>
  </section>

  <section class="card">
    <h2 class="font-semibold">Farben &amp; Stil</h2>
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <?php foreach (['primary' => 'Primärfarbe', 'accent' => 'Akzentfarbe'] as $k => $label): $custom = $presetOrHex($v[$k]); ?>
      <div class="text-sm">
        <label for="c-<?= $e($k) ?>"><?= $e($label) ?></label>
        <select id="c-<?= $e($k) ?>" class="input mt-1 w-full" name="<?= $e($k) ?>">
          <?php foreach ($brands as $b): ?><option value="<?= $e($b) ?>" <?= $v[$k] === $b ? 'selected' : '' ?>><?= $e(ucfirst($b)) ?></option><?php endforeach; ?>
          <?php if ($custom): ?><option value="<?= $e($v[$k]) ?>" selected>Eigene: <?= $e($v[$k]) ?></option><?php endif; ?>
        </select>
        <label class="mt-2 block text-xs text-slate-500">Oder eigener Hex-Wert (hat Vorrang)<input class="input mt-1 w-full font-mono" name="<?= $e($k) ?>_hex" maxlength="7" pattern="#[0-9a-fA-F]{6}" placeholder="#4f46e5" value=""></label>
      </div>
      <?php endforeach; ?>
      <label class="text-sm">Neutrale Töne (Hintergründe, Text)<select class="input mt-1 w-full" name="neutral"><?php foreach ($neutrals as $n): ?><option value="<?= $e($n) ?>" <?= $v['neutral'] === $n ? 'selected' : '' ?>><?= $e(ucfirst($n)) ?></option><?php endforeach; ?></select></label>
      <label class="text-sm">Rundung<select class="input mt-1 w-full" name="radius"><?php foreach ($radii as $r): ?><option value="<?= $e($r) ?>" <?= $v['radius'] === $r ? 'selected' : '' ?>><?= $e($labels[$r]) ?></option><?php endforeach; ?></select></label>
      <label class="text-sm">Schrift<select class="input mt-1 w-full" name="font"><?php foreach ($fonts as $f): ?><option value="<?= $e($f) ?>" <?= $v['font'] === $f ? 'selected' : '' ?>><?= $e($labels[$f]) ?></option><?php endforeach; ?></select></label>
      <label class="text-sm">Hintergrund<select class="input mt-1 w-full" name="bg"><option value="blobs" <?= $v['bg'] === 'blobs' ? 'selected' : '' ?>>Farbverlauf</option><option value="plain" <?= $v['bg'] === 'plain' ? 'selected' : '' ?>>Einfarbig</option></select></label>
    </div>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Standard-Theme<select class="input mt-1 w-full" name="default_theme">
        <option value="dark" <?= $v['default_theme'] === 'dark' ? 'selected' : '' ?>>Dunkel</option>
        <option value="light" <?= $v['default_theme'] === 'light' ? 'selected' : '' ?>>Hell</option>
        <option value="system" <?= $v['default_theme'] === 'system' ? 'selected' : '' ?>>Systemeinstellung</option></select></label>
      <label class="flex items-center gap-2 text-sm sm:mt-6"><input type="checkbox" name="allow_toggle" value="1" <?= $v['allow_toggle'] === '1' ? 'checked' : '' ?>> Benutzer dürfen Hell/Dunkel umschalten</label>
    </div>
  </section>

  <section class="card">
    <h2 class="font-semibold">Texte</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
      <label class="text-sm">Titel der Anmeldeseite<input class="input w-full" name="login_title" maxlength="60" value="<?= $e($v['login_title']) ?>"></label>
      <label class="text-sm">Untertitel der Anmeldeseite<input class="input w-full" name="login_subtitle" maxlength="200" value="<?= $e($v['login_subtitle']) ?>"></label>
      <label class="text-sm sm:col-span-2">Fußzeile<input class="input w-full" name="footer" maxlength="300" value="<?= $e($v['footer']) ?>"></label>
      <label class="text-sm">Support-E-Mail<input class="input w-full" type="email" name="support_email" maxlength="255" value="<?= $e($v['support_email']) ?>"></label>
    </div>
    <h3 class="mt-5 text-sm font-semibold">Rechtliche Links (Fußzeile)</h3>
    <?php foreach ([1, 2, 3] as $i): ?>
    <div class="mt-2 grid gap-3 sm:grid-cols-3">
      <label class="text-sm">Bezeichnung<input class="input w-full" name="legal_<?= $i ?>_label" maxlength="40" placeholder="Impressum" value="<?= $e($v["legal_{$i}_label"]) ?>"></label>
      <label class="text-sm sm:col-span-2">URL<input class="input w-full font-mono" name="legal_<?= $i ?>_url" maxlength="500" placeholder="https://… oder /impressum" value="<?= $e($v["legal_{$i}_url"]) ?>"></label>
    </div>
    <?php endforeach; ?>
  </section>

  <section class="card">
    <h2 class="font-semibold"><i class="bx bx-shield-quarter"></i> Datenschutz (Angaben für /privacy)</h2>
    <p class="mt-1 text-sm text-slate-500">Diese Angaben erscheinen in der öffentlichen Datenschutzerklärung (<a class="link" href="/privacy" target="_blank" rel="noopener">/privacy</a>). Pflicht: Verantwortlicher mit Anschrift und Kontakt. Der Text ist eine Vorlage – lassen Sie ihn rechtlich prüfen.</p>
    <?php if ($v['privacy_controller'] === ''): ?><p class="alert alert-warn mt-3">Der Verantwortliche ist noch nicht hinterlegt.</p><?php endif; ?>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
      <label class="text-sm sm:col-span-2">Verantwortlicher (Name, Anschrift)<textarea class="input w-full" name="privacy_controller" rows="3" maxlength="600"><?= $e($v['privacy_controller']) ?></textarea></label>
      <label class="text-sm">Kontakt für Datenschutzanfragen (E-Mail)<input class="input w-full" type="email" name="privacy_email" maxlength="255" value="<?= $e($v['privacy_email']) ?>"></label>
      <label class="text-sm">Datenschutzbeauftragte/r (falls vorhanden)<textarea class="input w-full" name="privacy_dpo" rows="2" maxlength="400"><?= $e($v['privacy_dpo']) ?></textarea></label>
      <label class="text-sm">Zuständige Aufsichtsbehörde (optional)<textarea class="input w-full" name="privacy_authority" rows="2" maxlength="300"><?= $e($v['privacy_authority']) ?></textarea></label>
      <label class="text-sm">Link zu Nutzungsbedingungen (optional)<input class="input w-full font-mono" name="terms_url" maxlength="500" value="<?= $e($v['terms_url']) ?>" placeholder="https://… oder /agb"></label>
      <label class="text-sm sm:col-span-2">Zusätzliche Hinweise in der Datenschutzerklärung<textarea class="input w-full" name="privacy_extra" rows="4" maxlength="4000"><?= $e($v['privacy_extra']) ?></textarea></label>
    </div>
  </section>

  <section class="card">
    <h2 class="font-semibold">E-Mails</h2>
    <div class="mt-4 grid gap-3">
      <label class="text-sm">Absendername (leer = Name der Anwendung)<input class="input w-full" name="mail_from_name" maxlength="60" value="<?= $e($v['mail_from_name']) ?>"></label>
      <label class="text-sm">Fußzeile in E-Mails<textarea class="input w-full" name="mail_footer" rows="3" maxlength="500"><?= $e($v['mail_footer']) ?></textarea></label>
    </div>
  </section>

  <section class="card">
    <h2 class="font-semibold">Eigenes CSS (fortgeschritten)</h2>
    <p class="mt-1 text-sm text-slate-500">Wird nach dem Theme geladen. Nicht erlaubt: <code>@import</code>, externe <code>url()</code>, <code>expression()</code>, <code>javascript:</code>. Verfügbar: CSS-Variablen <code>--brand-500</code>, <code>--accent-500</code>, <code>--n-900</code> usw.</p>
    <textarea class="input mt-3 w-full font-mono text-xs" name="custom_css" rows="8" maxlength="20000" spellcheck="false"><?= $e($v['custom_css']) ?></textarea>
  </section>

  <div class="flex flex-wrap gap-3">
    <button class="btn btn-primary" type="submit"><i class="bx bx-save"></i> Speichern</button>
  </div>
</form>
<form method="post" action="/admin/branding/reset" class="mt-4">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <button class="btn btn-secondary" type="submit" data-confirm="Alle Branding-Einstellungen und hochgeladenen Dateien wirklich zurücksetzen?"><i class="bx bx-reset"></i> Auf Standard zurücksetzen</button>
</form>
