<?php /** Auswahl der Lizenz aus dem Pool. Erwartet $poolOptions; optional $autoOption (Standard true), $currentPoolId. */ ?>
<?php $currentPoolId = (int) ($currentPoolId ?? 0); ?>
<select class="input w-full" name="license_pool_id" data-label="Lizenz">
  <?php if ($autoOption ?? true): ?>
  <option value="">Automatisch (nächster freier Platz)</option>
  <?php else: ?>
  <option value="">– Lizenz wählen –</option>
  <?php endif; ?>
  <?php foreach ($poolOptions as $o): ?>
  <?php $cur = $currentPoolId > 0 && (int) $o['id'] === $currentPoolId; ?>
  <option value="<?= (int) $o['id'] ?>"<?= ($o['usable'] && !$cur) ? '' : ' disabled' ?>><?= $e(($cur ? '✓ ' : '') . $o['key'] . ($o['plan'] !== '' ? ' · ' . $o['plan'] : '') . ($o['max'] !== null ? ' · ' . (int) $o['free'] . ' von ' . (int) $o['max'] . ' Plätzen frei' : '') . ($cur ? ' (aktuell)' : ($o['usable'] ? '' : ' (nicht verfügbar)'))) ?></option>
  <?php endforeach; ?>
</select>
