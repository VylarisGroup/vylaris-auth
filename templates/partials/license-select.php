<?php /** Auswahl der Lizenz aus dem Pool (Nutzererstellung). Erwartet $poolOptions. */ ?>
<select class="input w-full" name="license_pool_id" data-label="Lizenz">
  <option value="">Automatisch (nächster freier Platz)</option>
  <?php foreach ($poolOptions as $o): ?>
  <option value="<?= (int) $o['id'] ?>"<?= $o['usable'] ? '' : ' disabled' ?>><?= $e($o['key'] . ($o['plan'] !== '' ? ' · ' . $o['plan'] : '') . ($o['max'] !== null ? ' · ' . (int) $o['free'] . ' von ' . (int) $o['max'] . ' Plätzen frei' : '') . ($o['usable'] ? '' : ' (nicht verfügbar)')) ?></option>
  <?php endforeach; ?>
</select>
