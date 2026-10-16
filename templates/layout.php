<!doctype html>
<html lang="de" class="h-full" data-theme="<?= $e($theme === 'light' ? 'light' : 'dark') ?>" data-theme-pref="<?= $e($theme) ?>" data-toggle="<?= $brand['allowToggle'] ? '1' : '0' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <meta name="color-scheme" content="light dark">
  <meta name="csrf" content="<?= $e($csrf) ?>">
  <title><?= $e($title ?? $appName) ?> · <?= $e($appName) ?></title>
  <meta name="theme-color" content="<?= $e($brand['primaryHex']) ?>">
  <link rel="icon" href="<?= $e($brand['faviconUrl']) ?>">
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/boxicons/css/boxicons.min.css">
  <link rel="stylesheet" href="<?= $e($brand['themeCssUrl']) ?>">
  <script src="/assets/ui.js"></script>
</head>
<body class="flex min-h-full flex-col">
  <div class="app-bg" aria-hidden="true"></div>
  <div class="scroll-progress" aria-hidden="true"></div>
  <header class="site-header">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between px-4 py-2.5">
      <a href="/" class="brand">
        <?php if ($brand['hasLogo']): ?>
          <?php if ($brand['hasSeparateDarkLogo']): ?>
          <img class="brand-logo logo-light" src="<?= $e($brand['logoUrl']) ?>" alt="<?= $brand['showName'] ? '' : $e($appName) ?>">
          <img class="brand-logo logo-dark" src="<?= $e($brand['logoDarkUrl']) ?>" alt="" aria-hidden="true">
          <?php else: ?>
          <img class="brand-logo" src="<?= $e($brand['logoUrl']) ?>" alt="<?= $brand['showName'] ? '' : $e($appName) ?>">
          <?php endif; ?>
        <?php else: ?>
          <span class="brand-mark"><i class="bx bxs-shield-alt-2"></i></span>
        <?php endif; ?>
        <?php if ($brand['showName']): ?><span><?= $e($appName) ?></span><?php endif; ?>
      </a>
      <?php if ($brand['allowToggle'] && empty($currentUser)): ?>
      <button type="button" class="theme-toggle" data-theme-toggle aria-label="Hell/Dunkel umschalten" title="Hell/Dunkel umschalten"><i class="bx bx-moon ico-moon"></i><i class="bx bx-sun ico-sun"></i></button>
      <?php endif; ?>
      <?php if (!empty($currentUser)): ?>
      <button type="button" class="nav-toggle" aria-label="Menü" aria-expanded="false" aria-controls="nav-panel"><i class="bx bx-menu"></i></button>
      <nav id="nav-panel" class="nav-panel" aria-label="Hauptnavigation">
        <a class="nav-link" href="/account"><i class="bx bx-user"></i> Konto</a>
        <a class="nav-link" href="/account/sessions"><i class="bx bx-devices"></i> Geräte</a>
        <?php if (!empty($isAdmin)): ?><a class="nav-link" href="/admin"><i class="bx bx-cog"></i> Admin</a><?php endif; ?>
        <?php if ($brand['allowToggle']): ?>
        <button type="button" class="theme-toggle max-md:w-full max-md:justify-start max-md:gap-2 max-md:px-3" data-theme-toggle aria-label="Hell/Dunkel umschalten" title="Hell/Dunkel umschalten"><i class="bx bx-moon ico-moon"></i><i class="bx bx-sun ico-sun"></i></button>
        <?php endif; ?>
        <form method="post" action="/logout" class="contents">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="nav-link w-full md:w-auto" type="submit"><i class="bx bx-log-out"></i> Abmelden</button>
        </form>
      </nav>
      <?php endif; ?>
    </div>
  </header>
  <?php
  $navPath = $navPath ?? '';
  $section = '';
  if (!empty($currentUser)) {
      if (str_starts_with($navPath, '/admin') && !empty($isAdmin)) { $section = 'admin'; }
      elseif (str_starts_with($navPath, '/account')) { $section = 'account'; }
  }
  ?>
  <div class="<?= $section !== '' ? 'shell' : 'mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:py-12' ?>">
    <?php if ($section !== '') { require __DIR__ . '/partials/sidebar.php'; } ?>
    <main class="min-w-0"><?= $content ?></main>
  </div>
  <footer class="site-footer mx-auto flex w-full max-w-6xl flex-wrap items-center justify-center gap-x-4 gap-y-1 px-4 pb-8 text-xs text-slate-500 sm:justify-start">
    <?php if ($brand['footer'] !== ''): ?><span><?= $e($brand['footer']) ?></span><?php endif; ?>
    <a href="/privacy">Datenschutz</a>
    <?php foreach ($brand['legal'] as [$label, $url]): ?><a href="<?= $e($url) ?>"<?= str_starts_with($url, 'http') ? ' rel="noopener noreferrer" target="_blank"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
    <?php if ($brand['supportEmail'] !== ''): ?><a href="mailto:<?= $e($brand['supportEmail']) ?>">Support</a><?php endif; ?>
  </footer>
</body>
</html>
