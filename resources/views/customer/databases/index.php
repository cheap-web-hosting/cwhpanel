<?php $dbs = $databases ?? []; $dbUsageBytes = $dbUsageBytes ?? null; $dbQuotaMb = (int) ($dbQuotaMb ?? 0); ?>
<?php if ($dbQuotaMb > 0): ?>
  <div class="panel" style="padding:12px 16px;margin-bottom:12px">
    <?php if ($dbUsageBytes === null): ?>
      <span class="text-muted">Database storage usage unavailable.</span>
    <?php else: ?>
      Database storage: <strong><?= e(number_format($dbUsageBytes / 1048576, 1)) ?> MB</strong> of <?= (int) $dbQuotaMb ?> MB
      <?php if ($dbUsageBytes >= $dbQuotaMb * 1048576): ?><span class="status status-disabled" style="margin-left:8px">Over quota — writes blocked</span><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">My databases</h2><a href="<?= e(url('panel/databases/create')) ?>" class="btn btn-primary">Add Database</a></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Database</th><th>User</th><th>Status</th><th></th></tr></thead>
<tbody><?php if (empty($dbs)): ?><?= empty_state(4, 'No databases yet. <a href="' . e(url('panel/databases/create')) . '">Create one</a>.') ?>
<?php else: foreach ($dbs as $d): ?><tr><td><code><?= e($d['name']) ?></code></td><td><?= e($d['username']) ?></td>
<td><?= $d['status']==='active'?'<span class="status status-active">Active</span>':'<span class="status status-disabled">'.e($d['status']).'</span>' ?></td>
<td class="actions"><a href="<?= e(url('panel/databases/'.$d['id'])) ?>" class="btn btn-sm">Manage</a></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>