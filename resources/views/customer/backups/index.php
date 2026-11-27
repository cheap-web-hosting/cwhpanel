<?php $backups = $backups ?? []; $encrypted = $encrypted ?? false; $backupUsageBytes = $backupUsageBytes ?? null; $backupQuotaMb = (int) ($backupQuotaMb ?? 0); ?>
<?php if ($backupQuotaMb > 0): ?>
  <div class="panel" style="padding:12px 16px;margin-bottom:12px">
    <?php if ($backupUsageBytes === null): ?>
      <span class="text-muted">Backup storage usage unavailable.</span>
    <?php else: ?>
      Backup storage: <strong><?= e(number_format($backupUsageBytes / 1048576, 1)) ?> MB</strong> of <?= (int) $backupQuotaMb ?> MB
      <?php if ($backupUsageBytes >= $backupQuotaMb * 1048576): ?><span class="status status-disabled" style="margin-left:8px">Quota reached — new backups refused</span><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">My backups</h2><div class="actions"><a href="<?= e(url('panel/backups/schedules')) ?>" class="btn btn-sm">Schedules</a> <a href="<?= e(url('panel/backups/remotes')) ?>" class="btn btn-sm">Remote Storage</a> <a href="<?= e(url('panel/backups/create')) ?>" class="btn btn-primary">Create Backup</a></div></div>
<div style="padding:12px 16px;border-bottom:1px solid var(--border)">
  <form method="post" action="<?= e(url('panel/backups/encryption')) ?>" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="enabled" value="<?= $encrypted ? '0' : '1' ?>">
    <span class="status <?= $encrypted ? 'status-active' : 'status-disabled' ?>"><?= $encrypted ? 'Encryption on' : 'Encryption off' ?></span>
    <span class="text-muted">New backups are <?= $encrypted ? 'sealed (AES-256-GCM)' : 'stored unencrypted' ?>.</span>
    <button type="submit" class="btn btn-sm"><?= $encrypted ? 'Disable' : 'Enable' ?></button>
  </form>
</div>
<div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Remote</th><th></th></tr></thead>
<tbody><?php if (empty($backups)): ?><?= empty_state(5, 'No backups yet.') ?>
<?php else: foreach ($backups as $b): ?><tr><td><code><?= e($b['name']) ?></code></td><td><?= e(\Modules\Backups\Services\BackupService::typeLabel($b['type'])) ?></td>
<td><?= e($b['status']) ?></td>
<td><?php if (empty($b['copies'])): ?><span class="text-muted">—</span><?php else: foreach ($b['copies'] as $c): ?><div><span class="status status-active"><?= e($c['remote_name'] ?? 'remote') ?></span> <small class="text-muted"><?= e(basename((string) $c['remote_path'])) ?></small></div><?php endforeach; endif; ?></td>
<td class="actions"><?php if (!empty($b['artifacts'])): foreach ($b['artifacts'] as $a): ?><a class="btn btn-sm" href="<?= e(url('panel/backups/'.$b['id'].'/download?file='.urlencode($a['file']))) ?>">Download <?= e($a['file']) ?></a><?php endforeach; endif; ?><?php if ($b['status'] === 'completed'): ?><?php if (empty($b['artifacts']) && !empty($b['copies'])): ?><form method="post" action="<?= e(url('panel/backups/'.$b['id'].'/restore-remote')) ?>" class="inline-form"><?= csrf_field() ?><label><input type="checkbox" name="confirm_restore" value="1" required> I confirm restoring <?= e($b['name']) ?> may overwrite current files and databases.</label><button type="submit" class="btn btn-sm btn-primary">Fetch &amp; restore</button></form><?php else: ?><form method="post" action="<?= e(url('panel/backups/'.$b['id'].'/restore')) ?>" class="inline-form"><?= csrf_field() ?><label><input type="checkbox" name="confirm_restore" value="1" required> I confirm restoring <?= e($b['name']) ?> may overwrite current files and databases.</label><button type="submit" class="btn btn-sm">Restore</button></form><?php endif; ?><?php endif; ?>
<?php if (!empty($b['copies'])): foreach ($b['copies'] as $c): ?><form method="post" action="<?= e(url('panel/backups/copies/'.(int) $c['copy_id'].'/fetch')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm" title="Download this artifact from remote storage">Fetch <?= e(basename((string) $c['remote_path'])) ?></button></form><?php endforeach; endif; ?>
<form method="post" action="<?= e(url('panel/backups/'.$b['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete?">Delete</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>
