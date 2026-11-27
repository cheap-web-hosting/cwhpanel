<?php $dbs = $databases ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">All databases</h2><a href="<?= e(url('admin/databases/create')) ?>" class="btn btn-primary">Add Database</a></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Database</th><th>Account</th><th>User</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php if (empty($dbs)): ?><?= empty_state(5, 'No databases yet.') ?>
<?php else: foreach ($dbs as $d): ?>
<tr><td><code><?= e($d['name']) ?></code></td><td><?= e($d['account_username'] ?? '—') ?></td><td><?= e($d['username']) ?></td>
<td><?= $d['status']==='active'?'<span class="status status-active">Active</span>':'<span class="status status-disabled">'.e($d['status']).'</span>' ?></td>
<td class="actions"><a href="<?= e(url('admin/databases/'.$d['id'])) ?>" class="btn btn-sm">Manage</a>
<form method="post" action="<?= e(url('admin/databases/'.$d['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete database?">Delete</button></form></td>
</tr><?php endforeach; endif; ?>
</tbody></table></div></div>