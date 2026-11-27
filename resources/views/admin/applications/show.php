<?php $app = $app ?? []; $venv = $venv ?? null; $base = $base ?? '/admin/nodejs'; $prefix = ltrim($base, '/'); ?>
<div class="cards-grid">
<div class="card"><div class="card-label">Name</div><div class="card-value"><?= e($app['name']) ?></div></div>
<div class="card"><div class="card-label">Runtime</div><div class="card-value"><?= e($app['runtime']) ?> <?= e($app['runtime_version'] ?? '') ?></div></div>
<?php if ($venv !== null): ?>
<div class="card"><div class="card-label">Environment</div><div class="card-value"><?= $venv ? '<span class="status status-active">Isolated venv</span>' : '<span class="status status-disabled">System packages</span>' ?></div></div>
<?php endif; ?>
<div class="card"><div class="card-label">Account</div><div class="card-value"><?= e($app['username']??'—') ?></div></div>
<div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($app['status']) ?></div></div>
<div class="card"><div class="card-label">Work dir</div><div class="card-value"><code><?= e($app['work_dir']) ?></code></div></div>
<div class="card"><div class="card-label">Git</div><div class="card-value"><?= e($app['git_url']??'—') ?> <small class="text-muted"><?= e($app['git_branch']??'') ?></small></div></div>
</div>
<div class="panel" style="padding:16px"><div class="form-actions">
<?php if ($app['status']!=='running'): ?><form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/start')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-primary">Start</button></form>
<?php else: ?><form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/stop')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn">Stop</button></form><?php endif; ?>
<form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/restart')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn">Restart</button></form>
<?php if ($app['git_url']): ?><form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/deploy')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn">Deploy</button></form><?php endif; ?>
<form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/update')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn" data-confirm="Queue an update (git pull + dependency refresh + restart if running)?">Update</button></form>
<form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-danger" data-confirm="Delete app?">Delete</button></form>
</div></div>
<?php $appEnv = $appEnv ?? []; $parentApp = $parentApp ?? null; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Environment variables</h2></div>
<form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/env')) ?>" class="form-stack" style="padding:12px 16px"><?= csrf_field() ?>
<label class="field-label" for="app-env">One <code>KEY=value</code> per line (A-Z, 0-9, _; values: letters, digits and _.-:/). PORT and HOST are managed automatically.</label>
<textarea class="input" id="app-env" name="env" rows="5" style="width:100%;font-family:monospace" placeholder="NODE_ENV=production"><?= e(implode("\n", array_map(fn($k, $v) => $k . '=' . $v, array_keys($appEnv), $appEnv))) ?></textarea>
<div class="form-actions"><button type="submit" class="btn btn-primary">Save environment</button>
<span class="text-muted">Takes effect on the next start/restart.</span></div>
</form></div>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Staging</h2></div>
<div style="padding:12px 16px">
<?php if ($parentApp): ?><p><span class="text-muted">Staging clone of</span> <strong><?= e($parentApp['name']) ?></strong></p>
<form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/sync')) ?>" class="inline-form" data-confirm="Replace <?= e($parentApp['name']) ?>'s files with this staging copy (minus dependency trees)? Production keeps running until restarted."><?= csrf_field() ?><button type="submit" class="btn btn-primary">Sync files to <?= e($parentApp['name']) ?></button></form>
<p class="text-muted">Promotes files only — restart <?= e($parentApp['name']) ?> to apply, then run Update there for fresh dependencies.</p>
<?php endif; ?>
<form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/clone')) ?>" class="inline-form" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?>
<label class="field-label" for="clone-name">Clone as staging:</label>
<input class="input" type="text" id="clone-name" name="name" value="<?= e($app['name']) ?>-staging" required maxlength="64" style="max-width:220px">
<button type="submit" class="btn">Clone</button></form>
<p class="text-muted">Copies files (minus .venv/node_modules) and settings into a stopped clone with a fresh port and no proxy mount.</p>
</div></div>
<?php $webhookUrl = $webhook_url ?? ''; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Push-to-deploy webhook</h2></div>
<div style="padding:12px 16px">
<?php if (empty($app['git_url'])): ?>
<span class="text-muted">Set a git remote on this app first — webhooks deploy by fetching it.</span>
<?php else: ?>
<p><span class="text-muted">Payload URL (GitHub / GitLab):</span><br><code><?= e($webhookUrl) ?></code></p>
<?php if (!empty($app['last_deploy_at'])): ?><p><span class="text-muted">Last deploy:</span> <?= e($app['last_deploy_at']) ?> (<?= e($app['last_deploy_status'] ?? '—') ?>)</p><?php endif; ?>
<div class="form-actions">
<form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/webhook')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="enabled" value="<?= empty($app['webhook_enabled']) ? '1' : '0' ?>"><button type="submit" class="btn"><?= empty($app['webhook_enabled']) ? 'Enable webhook' : 'Disable webhook' ?></button></form>
<?php if (!empty($app['webhook_secret'])): ?><form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/webhook/rotate')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn" data-confirm="Generate a new secret? The forge stops verifying until updated.">Rotate secret</button></form><?php endif; ?>
</div>
<?php if (!empty($app['webhook_secret'])): ?><p><span class="text-muted">Secret (configure as GitHub webhook secret / GitLab secret token, treat like a password):</span><br><code><?= e($app['webhook_secret']) ?></code></p><?php endif; ?>
<p><span class="text-muted">Status:</span> <?= !empty($app['webhook_enabled']) ? '<span class="status status-active">enabled</span>' : '<span class="status status-disabled">disabled</span>' ?></p>
<?php endif; ?>
</div></div>
<?php if (($app['runtime'] ?? '') === 'python'): $packages = $packages ?? []; $packageCatalog = $packageCatalog ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Python packages</h2></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Package</th><th>Installed version</th><th style="width:120px"></th></tr></thead>
<tbody>
<?php if (empty($packages)): ?><?= empty_state(3, 'No packages installed in this app\'s virtualenv yet.') ?>
<?php else: foreach ($packages as $pname => $pver): ?>
<tr><td><strong><?= e($pname) ?></strong></td><td><?= e($pver) ?></td>
<td class="actions"><?php if (isset($packageCatalog[$pname])): ?><form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/packages/remove')) ?>" class="inline-form" data-confirm="Uninstall <?= e($pname) ?>?"><?= csrf_field() ?><input type="hidden" name="package" value="<?= e($pname) ?>"><button type="submit" class="btn btn-sm btn-danger">Remove</button></form><?php else: ?><span class="text-muted" title="Installed automatically as a dependency">dep</span><?php endif; ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<form method="post" action="<?= e(url($prefix.'/'.$app['id'].'/packages')) ?>" style="padding:12px 16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?>
<label class="field-label" for="pkg-add">Add package</label>
<select class="input" id="pkg-add" name="package" style="max-width:280px"><?php foreach ($packageCatalog as $pname => $blurb): if (isset($packages[$pname])) continue; ?><option value="<?= e($pname) ?>"><?= e($pname) ?> — <?= e($blurb) ?></option><?php endforeach; ?></select>
<button type="submit" class="btn btn-primary btn-sm">Install</button>
<span class="text-muted">Installs into the app's isolated virtualenv in the background.</span>
</form></div>
<?php endif; ?>
