<?php
$apps = $apps ?? [];
$runtime = $runtime ?? 'node';
$base = $base ?? '/panel/nodejs';
$title = ($runtime === 'python' ? 'My Python apps' : 'My Node.js apps');
?>
<?php $disabledRuntime = $disabledRuntime ?? false; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title"><?= e($title) ?></h2><?php if (!$disabledRuntime): ?><a href="<?= e(url(ltrim($base, '/') . '/create')) ?>" class="btn btn-primary">Add App</a><?php endif; ?></div>
<?php if ($disabledRuntime): ?>
  <div class="panel" style="margin:12px 16px;padding:14px 16px;border-left:3px solid #b45309"><?= e($label) ?> apps are disabled for your package. Ask your administrator to enable them.</div>
<?php endif; ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Entry point</th><th>Status</th><th></th></tr></thead>
<tbody><?php if (empty($apps)): ?><?= empty_state(4, 'No ' . e($runtime === 'python' ? 'Python' : 'Node.js') . ' apps yet.') ?>
<?php else: foreach ($apps as $app): ?><tr><td><strong><?= e($app['name']) ?></strong></td><td><?= e($app['entry_point']) ?></td>
<td><?= $app['status']==='running'?'<span class="status status-active">Running</span>':'<span class="status status-disabled">'.e($app['status']).'</span>' ?></td>
<td class="actions"><a href="<?= e(url(ltrim($base, '/') . '/' . $app['id'])) ?>" class="btn btn-sm">Manage</a></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>
