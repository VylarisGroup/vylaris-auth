<?php if (!empty($errors)): ?>
<div class="alert alert-err mb-4" role="alert">
  <ul class="list-inside list-disc"><?php foreach ($errors as $err): ?><li><?= $e($err) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
