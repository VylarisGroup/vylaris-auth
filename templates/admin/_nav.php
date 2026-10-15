<?php $cur = $cur ?? ''; $items = ['/admin' => ['bx-home', 'Übersicht'], '/admin/users' => ['bx-group', 'Benutzer'], '/admin/apps' => ['bx-grid-alt', 'Anwendungen'], '/admin/audit' => ['bx-list-check', 'Audit-Log'], '/admin/social' => ['bx-log-in-circle', 'Social Login'], '/admin/branding' => ['bx-palette', 'Branding'], '/admin/license' => ['bx-id-card', 'Lizenz'], '/admin/keys' => ['bx-key', 'Schlüssel'], '/admin/update' => ['bx-cloud-download', 'Update']]; ?>
<nav class="-mx-4 mb-6 flex gap-1 overflow-x-auto whitespace-nowrap px-4 pb-1 text-sm [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:px-0" aria-label="Administration">
  <?php foreach ($items as $href => [$icon, $label]): ?>
  <a href="<?= $e($href) ?>" class="nav-link shrink-0 <?= $cur === $href ? 'active' : '' ?>"<?= $cur === $href ? ' aria-current="page"' : '' ?>><i class="bx <?= $e($icon) ?>"></i> <?= $e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php if (!empty($flash)): ?><p class="alert alert-ok mb-4" role="status"><?= $e($flash) ?></p><?php endif; ?>
<?php if (!empty($err)): ?><p class="alert alert-err mb-4" role="alert"><?= $e($err) ?></p><?php endif; ?>
