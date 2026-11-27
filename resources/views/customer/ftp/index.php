<?php $accounts = $accounts ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">My FTP accounts</h2><a href="<?= e(url('panel/ftp/create')) ?>" class="btn btn-primary">Add FTP Account</a></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Username</th><th>Home</th><th>Quota</th><th>Status</th><th></th></tr></thead>
<tbody><?php if (empty($accounts)): ?><?= empty_state(5, 'No FTP accounts.') ?>
<?php else: foreach ($accounts as $a): ?><tr><td><code><?= e($a['username']) ?></code></td><td><code><?= e($a['home_dir']) ?></code></td><td><?= e($a['quota_mb']) ?> MB</td>
<td><?= $a['status']==='active'?'<span class="status status-active">Active</span>':'<span class="status status-disabled">'.e($a['status']).'</span>' ?></td>
<td class="actions"><form method="post" action="<?= e(url('panel/ftp/'.$a['id'].'/password')) ?>" class="inline-form"><?= csrf_field() ?><input class="input" type="password" autocomplete="new-password" name="password" placeholder="New password" required minlength="8"><button type="submit" class="btn btn-sm">Change password</button></form>
<form method="post" action="<?= e(url('panel/ftp/'.$a['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete?">Delete</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>