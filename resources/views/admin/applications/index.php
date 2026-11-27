<?php $apps = $apps ?? []; $search = $search ?? ''; $runtime = $runtime ?? 'node'; $base = $base ?? '/admin/nodejs'; $prefix = ltrim($base, '/'); $label = $runtime === 'python' ? 'Python' : 'Node.js'; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title"><?= e($label) ?> applications</h2><a href="<?= e(url($prefix . '/create')) ?>" class="btn btn-primary">Add <?= e($label) ?> App</a></div>
<form method="get" action="<?= e(url($prefix)) ?>" class="filter-bar"><input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search…"><button type="submit" class="btn">Search</button></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Account</th><th>Status</th><th></th></tr></thead>
<tbody><?php if (empty($apps)): ?><?= empty_state(4, 'No ' . e($label) . ' applications yet.') ?>
<?php else: foreach ($apps as $app): ?><tr><td><strong><?= e($app['name']) ?></strong></td><td><?= e($app['username']??'—') ?></td>
<td><?= $app['status']==='running'?'<span class="status status-active">Running</span>':'<span class="status status-disabled">'.e($app['status']).'</span>' ?></td>
<td class="actions"><a href="<?= e(url($prefix . '/' . $app['id'])) ?>" class="btn btn-sm">Manage</a></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>
