<?php if (!empty($socialProviders)): ?>
<div class="mt-6">
  <p class="divider-label mb-4">oder weiter mit</p>
  <div class="grid gap-2 sm:grid-cols-2">
    <?php foreach ($socialProviders as $sp): ?>
    <a class="btn btn-secondary" href="/social/<?= $e($sp['provider_key']) ?>/login<?= !empty($next) ? '?next=' . $e(rawurlencode($next)) : '' ?>"><i class="bx <?= $e($sp['icon']) ?> text-lg"></i> <?= $e($sp['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <p class="mt-3 text-xs text-slate-500">Mit Klick werden Sie zum gewählten Anbieter weitergeleitet (dabei erhält dieser z. B. Ihre IP-Adresse). Bei der ersten Anmeldung legen wir ein Konto aus Name und E-Mail-Adresse an, die der Anbieter übermittelt. Details: <a class="link" href="/privacy">Datenschutzerklärung</a>.</p>
</div>
<?php endif; ?>
