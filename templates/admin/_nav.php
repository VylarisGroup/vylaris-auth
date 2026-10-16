<?php /* Navigation steht in der Seitenleiste (partials/sidebar.php); hier nur Meldungen. */ ?>
<?php if (!empty($flash)): ?><p class="alert alert-ok mb-4" role="status"><?= $e($flash) ?></p><?php endif; ?>
<?php if (!empty($err)): ?><p class="alert alert-err mb-4" role="alert"><?= $e($err) ?></p><?php endif; ?>
