<div class="table-wrap mt-3">
<table class="w-full text-left text-xs">
  <thead class="text-xs uppercase tracking-wide text-slate-400"><tr><th class="py-1 pr-3">Zeit (UTC)</th><th class="pr-3">Ereignis</th><th class="pr-3">Benutzer</th><th class="pr-3">Akteur</th><th class="pr-3">Client</th><th class="pr-3">IP</th><th>Details</th></tr></thead>
  <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
  <?php foreach ($events as $ev): ?>
    <tr><td class="py-1 pr-3 whitespace-nowrap"><?= $e($ev['created_at']) ?></td><td class="pr-3 font-mono"><?= $e($ev['event']) ?></td><td class="pr-3"><?= $e($ev['user_id'] ?? '–') ?></td><td class="pr-3"><?= $e($ev['actor_id'] ?? '–') ?></td><td class="pr-3"><?= $e($ev['client_id'] ?? '–') ?></td><td class="pr-3"><?= $e($ev['ip'] ?? '–') ?></td><td class="break-all font-mono text-slate-500"><?= $e($ev['details'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$events): ?><tr><td colspan="7" class="py-2 text-slate-500">Keine Ereignisse.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
