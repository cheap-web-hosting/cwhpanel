<?php $keys = $keys ?? []; $enabled = (bool) ($enabled ?? false); ?>
<?php if (!$enabled): ?><div class="alert alert-warning">API keys are disabled on this deployment (set API_KEYS_ENABLED=1 to mint them). The v1 API endpoints (accounts, packages, lifecycle) authenticate with a Bearer key.</div><?php endif; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">API keys</h2></div>
<div class="panel" style="padding:16px"><form method="post" action="<?= e(url('admin/api/keys')) ?>" class="filter-bar" style="border-bottom:0;padding:0"><?= csrf_field() ?>
<input class="input" type="text" name="name" placeholder="Key name" required><input class="input" type="number" name="user_id" placeholder="User ID" required>
<button type="submit" class="btn btn-primary">Create key</button></form></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>User</th><th>Key</th><th>Status</th><th></th></tr></thead>
<tbody><?php if (empty($keys)): ?><?= empty_state(5, 'No API keys.') ?>
<?php else: foreach ($keys as $k): ?><tr><td><?= e($k['name']) ?></td><td><?= e($k['email']??'—') ?></td><td><code><?= e(substr((string) $k['key'], 0, 12)) ?>…</code></td>
<td><?= $k['status']==='active'?'<span class="status status-active">Active</span>':'<span class="status status-disabled">'.e($k['status']).'</span>' ?></td>
<td class="actions"><form method="post" action="<?= e(url('admin/api/keys/'.$k['id'].'/revoke')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Revoke key?">Revoke</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>