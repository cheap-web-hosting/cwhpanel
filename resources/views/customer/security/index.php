<?php $events = $events ?? []; $myIps = $myIps ?? []; $sslLink = $sslLink ?? '/panel/ssl'; ?>
<div class="cards-grid">
<?php if (feature_enabled('ssl')): ?><div class="card"><div class="card-label">SSL</div><div class="card-value"><a href="<?= e($sslLink) ?>">Manage certificates</a></div></div><?php endif; ?>
</div>
<h2 class="section-title">IP access control</h2>
<div class="panel" style="padding:16px">
<form method="post" action="<?= e(url('panel/security/ip-block')) ?>" class="filter-bar" style="border-bottom:0;padding:0"><?= csrf_field() ?>
<input class="input" type="text" name="ip" placeholder="Block IP address" required>
<button type="submit" class="btn btn-danger">Block IP</button>
</form>
<?php if ($myIps): ?><div class="table-wrap" style="margin-top:12px"><table class="table"><thead><tr><th>IP</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($myIps as $ip): ?><tr><td><code><?= e($ip['ip_address']) ?></code></td><td><span class="badge badge-warning"><?= e($ip['action']) ?></span></td>
<td class="actions"><form method="post" action="<?= e(url('panel/security/ip-block/'.$ip['id'].'/remove')) ?>" class="inline-form" data-confirm="Unblock this IP address?"><?= csrf_field() ?><button type="submit" class="btn btn-sm">Unblock</button></form></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<h2 class="section-title">Security events</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>Action</th><th>Resource</th><th>IP</th><th>Time</th></tr></thead>
<tbody><?php if (empty($events)): ?><?= empty_state(4, 'No security events for your account.') ?>
<?php else: foreach ($events as $e): ?><tr><td><?= e($e['action']) ?></td><td><?= e($e['resource_type']??'') ?></td><td><?= e($e['ip_address']??'') ?></td><td class="nowrap"><?= e($e['created_at']) ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>