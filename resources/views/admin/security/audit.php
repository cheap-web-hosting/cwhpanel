<?php $logs = $logs ?? []; $total = $total ?? 0; $page = $page ?? 1; $perPage = $perPage ?? 50; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Audit log (<?= e($total) ?> entries)</h2></div>
<div class="table-wrap"><table class="table"><thead><tr><th>User</th><th>Action</th><th>Resource</th><th>IP</th><th>Time</th></tr></thead>
<tbody><?php if (empty($logs)): ?><?= empty_state(5, 'No audit entries.') ?>
<?php else: foreach ($logs as $l): ?><tr><td><?= e($l['email']??'system') ?></td><td><?= e($l['action']) ?></td><td><?= e($l['resource_type']??'') ?><?= $l['resource_id']?' #'.e($l['resource_id']):'' ?></td><td><?= e($l['ip_address']??'') ?></td><td class="nowrap"><?= e($l['created_at']) ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
<?php if ($total > $perPage): ?><div class="filter-bar">
<?php if ($page > 1): ?><a href="<?= e(url('admin/security/audit?page='.($page-1))) ?>" class="btn btn-sm">Prev</a><?php endif; ?>
<?php if ($page * $perPage < $total): ?><a href="<?= e(url('admin/security/audit?page='.($page+1))) ?>" class="btn btn-sm">Next</a><?php endif; ?>
</div><?php endif; ?></div>