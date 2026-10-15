<section class="card mx-auto max-w-md text-center">
  <div class="icon-badge"><i class="bx <?= $e($icon ?? 'bx-info-circle') ?>"></i></div>
  <h1 class="mt-3 text-xl font-semibold"><?= $e($title) ?></h1>
  <p class="mt-2 text-slate-600 dark:text-slate-300"><?= $e($message) ?></p>
  <?php if (!empty($link)): ?>
  <a href="<?= $e($link[0]) ?>" class="btn btn-primary mt-6 inline-block"><?= $e($link[1]) ?></a>
  <?php endif; ?>
</section>
