<?php
/** Seitenleiste mit ausklappbaren Gruppen. Erwartet: $section ('admin'|'account'), $navPath, $e. */
if ($section === 'admin') {
    $title = 'Administration';
    $tree = [
        ['/admin', 'bx-home', 'Übersicht'],
        ['Verwaltung', 'bx-group', [['/admin/users', 'bx-user', 'Benutzer'], ['/admin/apps', 'bx-grid-alt', 'Anwendungen'], ['/admin/social', 'bx-log-in-circle', 'Social Login']]],
        ['Sicherheit', 'bx-shield-quarter', [['/admin/audit', 'bx-list-check', 'Audit-Log'], ['/admin/keys', 'bx-key', 'Schlüssel']]],
        ['System', 'bx-cog', [['/admin/branding', 'bx-palette', 'Branding'], ['/admin/license', 'bx-id-card', 'Lizenz'], ['/admin/update', 'bx-cloud-download', 'Update']]],
        ['Assistenten', 'bx-rocket', [['/admin/apps/new', 'bx-plus-circle', 'Neue Anwendung'], ['/admin/users/new', 'bx-user-plus', 'Neuer Benutzer'], ['/admin/social/new', 'bx-link', 'Neuer Social-Anbieter']]],
    ];
} else {
    $title = 'Mein Konto';
    $tree = [
        ['/account', 'bx-home', 'Übersicht'],
        ['Sicherheit', 'bx-shield-quarter', [['/account/sessions', 'bx-devices', 'Geräte und Sitzungen'], ['/account/mfa/totp', 'bx-mobile-alt', 'Authenticator-App'], ['/account/mfa/passkeys', 'bx-fingerprint', 'Passkeys'], ['/account/mfa/recovery', 'bx-lock-open-alt', 'Wiederherstellungscodes']]],
        ['Verknüpfungen', 'bx-link', [['/account/social', 'bx-log-in-circle', 'Verknüpfte Konten'], ['/account/apps', 'bx-grid-alt', 'Anwendungen']]],
        ['Lizenz und Daten', 'bx-id-card', [['/account/license', 'bx-id-card', 'Lizenz'], ['/account/privacy', 'bx-lock-alt', 'Datenschutz']]],
    ];
}
// Aktiv ist der Eintrag mit dem längsten passenden Pfad ('/admin' und '/account' nur exakt).
$hrefs = [];
foreach ($tree as $n) {
    if (is_array($n[2] ?? null)) {
        foreach ($n[2] as $i) { $hrefs[] = $i[0]; }
    } else {
        $hrefs[] = $n[0];
    }
}
$active = '';
foreach ($hrefs as $h) {
    $match = $navPath === $h || ($h !== '/admin' && $h !== '/account' && str_starts_with($navPath, $h . '/'));
    if ($match && strlen($h) > strlen($active)) { $active = $h; }
}
?>
<details class="sidebar" open data-sidebar>
  <summary class="side-mobile"><span><i class="bx bx-menu"></i> <?= $e($title) ?></span><i class="bx bx-chevron-down side-chevron" aria-hidden="true"></i></summary>
  <p class="side-title"><?= $e($title) ?></p>
  <nav class="grid gap-1" aria-label="<?= $e($title) ?>">
  <?php foreach ($tree as $n): ?>
    <?php if (!is_array($n[2] ?? null)): ?>
      <a class="side-link <?= $active === $n[0] ? 'active' : '' ?>" href="<?= $e($n[0]) ?>"<?= $active === $n[0] ? ' aria-current="page"' : '' ?>><i class="bx <?= $e($n[1]) ?>"></i> <?= $e($n[2]) ?></a>
    <?php else: $open = in_array($active, array_column($n[2], 0), true); ?>
      <details class="side-group" data-side-key="<?= $e($section . ':' . $n[0]) ?>"<?= $open ? ' open data-active="1"' : '' ?>>
        <summary class="side-summary"><span><i class="bx <?= $e($n[1]) ?>"></i> <?= $e($n[0]) ?></span><i class="bx bx-chevron-down side-chevron" aria-hidden="true"></i></summary>
        <div class="side-items">
        <?php foreach ($n[2] as [$href, $icon, $label]): ?>
          <a class="side-link <?= $active === $href ? 'active' : '' ?>" href="<?= $e($href) ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>><i class="bx <?= $e($icon) ?>"></i> <?= $e($label) ?></a>
        <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  <?php endforeach; ?>
  </nav>
  <?= \Vylaris\Core\Watermark::html() ?>
</details>
