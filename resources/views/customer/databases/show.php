<?php $database = $database ?? []; $size = $size ?? 0; $users = $users ?? []; $mysqlRemote = $mysqlRemote ?? false; ?>
<div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
<a href="<?= e(url('phpmyadmin/')) ?>" target="_blank" rel="noopener" class="btn btn-primary"><?= icon('database', 14) ?> Open phpMyAdmin</a>
</div>
<div class="cards-grid"><div class="card"><div class="card-label">Database</div><div class="card-value"><code><?= e($database['name']) ?></code></div></div>
<div class="card"><div class="card-label">User</div><div class="card-value"><code><?= e($database['username']) ?></code></div></div>
<div class="card"><div class="card-label">Size</div><div class="card-value"><?= round($size/1048576,2) ?> MB</div></div>
<div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($database['status']) ?></div></div></div>
<h2 class="section-title">Users</h2>
<div class="panel" style="padding:16px">
<form method="post" action="<?= e(url('panel/databases/'.$database['id'].'/add-user')) ?>" class="filter-bar" style="border-bottom:0;padding:0"><?= csrf_field() ?>
<input class="input" type="text" name="username" placeholder="Username" required><input class="input" type="password" autocomplete="new-password" name="password" placeholder="Password (min 10)" title="Minimum 10 characters" required minlength="10">
<button type="submit" class="btn btn-primary btn-sm">Add user</button></form></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Username</th><th>Remote hosts (localhost always allowed)</th><th></th></tr></thead>
<tbody><?php if (empty($users)): ?><?= empty_state(3, 'No additional users.') ?>
<?php else: foreach ($users as $u): ?><tr><td><code><?= e($u['username']) ?></code></td>
<td><?php if (empty($u['hosts'])): ?><span class="text-muted">localhost only</span><?php else: ?><?php foreach ($u['hosts'] as $h): ?><code><?= e($h['host']) ?></code>
<form method="post" action="<?= e(url('panel/databases/'.$database['id'].'/users/'.$u['id'].'/hosts/'.$h['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Revoke <?= e($h['host']) ?>?">Revoke</button></form><br><?php endforeach; ?><?php endif; ?>
<form method="post" action="<?= e(url('panel/databases/'.$database['id'].'/users/'.$u['id'].'/hosts')) ?>" class="inline-form"><?= csrf_field() ?><input class="input" type="text" name="host" placeholder="IP or host" required style="max-width:140px" <?= $mysqlRemote ? '' : 'disabled' ?>><input class="input" type="password" autocomplete="new-password" name="password" placeholder="User password" required style="max-width:140px" <?= $mysqlRemote ? '' : 'disabled' ?>><button type="submit" class="btn btn-sm" <?= $mysqlRemote ? '' : 'disabled' ?>>Allow</button></form>
<?php if (!$mysqlRemote): ?><p class="field-help">Remote MySQL is disabled by the administrator — ask them to enable it (Security → Firewall).</p>
<?php else: ?><p class="field-help">A literal IPv4 also opens 3306/tcp for that IP only.</p><?php endif; ?></td>
<td class="actions"><form method="post" action="<?= e(url('panel/databases/'.$database['id'].'/delete-user/'.$u['id'])) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove user?">Remove</button></form></td>
</tr><?php endforeach; endif; ?></tbody></table></div>
<div class="panel" style="padding:16px"><div class="form-actions">
<form method="post" action="<?= e(url('panel/databases/'.$database['id'].'/rotate-password')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn" data-confirm="Rotate password?">Rotate password</button></form>
<form method="post" action="<?= e(url('panel/databases/'.$database['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-danger" data-confirm="Delete database and all data?">Delete</button></form>
</div></div>