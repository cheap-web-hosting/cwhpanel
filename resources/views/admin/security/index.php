<?php $blockedCount = $blockedCount ?? 0; $auditCount = $auditCount ?? 0; $scanCount = $scanCount ?? 0; $events = $events ?? []; $failedCount = $failedCount ?? 0; $topOffenders = $topOffenders ?? []; $wafInstalled = $wafInstalled ?? false; $avInstalled = $avInstalled ?? false; ?>
<div class="stats-grid">
<div class="stat"><span class="stat-value"><?= e($blockedCount) ?></span><span class="stat-label">Blocked IPs</span></div>
<div class="stat"><span class="stat-value"><?= e($failedCount) ?></span><span class="stat-label">Failed logins (24h)</span></div>
<div class="stat"><span class="stat-value"><?= e($scanCount) ?></span><span class="stat-label">Antivirus scans</span></div>
<div class="stat"><span class="stat-value"><?= e($auditCount) ?></span><span class="stat-label">Audit events</span></div>
</div>
<div class="cards-grid">
<div class="card"><div class="card-label">ModSecurity (WAF)</div><div class="card-value"><?= $wafInstalled ? '<span class="status status-active">Installed</span>' : '<span class="status status-disabled">Not installed</span>' ?></div></div>
<div class="card"><div class="card-label">ClamAV (Antivirus)</div><div class="card-value"><?= $avInstalled ? '<span class="status status-active">Installed</span>' : '<span class="status status-disabled">Not installed</span>' ?></div></div>
</div>
<h2 class="section-title">Failed panel logins by IP (24h)</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>IP address</th><th>Failures</th><th>Last attempt</th><th></th></tr></thead>
<tbody><?php if (empty($topOffenders)): ?><?= empty_state(4, 'No failed logins in the last 24 hours.') ?>
<?php else: foreach ($topOffenders as $o): ?><tr><td><?= e($o['ip_address']) ?></td><td><?= e($o['failures']) ?></td><td class="nowrap"><?= e($o['last_at']) ?></td><td class="actions"><form method="post" action="<?= e(url('admin/security/firewall/block')) ?>" class="inline-form" data-confirm="Block this IP address at the firewall?"><?= csrf_field() ?><input type="hidden" name="ip" value="<?= e($o['ip_address']) ?>"><input type="hidden" name="service" value="panel-login"><input type="hidden" name="reason" value="blocked from security overview after repeated failed logins"><button type="submit" class="btn btn-sm">Block</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
<h2 class="section-title">Recent security events</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>User</th><th>Action</th><th>Resource</th><th>IP</th><th>Time</th></tr></thead>
<tbody><?php if (empty($events)): ?><?= empty_state(5, 'No events.') ?>
<?php else: foreach ($events as $e): ?><tr><td><?= e($e['email']??'system') ?></td><td><?= e($e['action']) ?></td><td><?= e($e['resource_type']??'') ?><?= $e['resource_id']?' #'.e($e['resource_id']):'' ?></td><td><?= e($e['ip_address']??'') ?></td><td class="nowrap"><?= e($e['created_at']) ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>