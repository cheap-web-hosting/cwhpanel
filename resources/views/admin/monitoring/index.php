<?php
use Modules\Core\Services\SystemInfo;
$system = $system ?? []; $accounts = $accounts ?? []; $traffic = $traffic ?? [];
$load1 = (float)($system['load']['1m'] ?? 0);
$cores = max(1, (int)($system['cpu']['cores'] ?? 1));
$cpuPct = (int) min(100, max(0, round(($load1 / $cores) * 100)));
?>
<h2 class="section-title">Host resources</h2>
<div class="cards-grid">
<div class="card"><div class="card-label">CPU</div><div class="card-value"><?= e($system['cpu']['cores']??0) ?> cores</div><div class="card-bar"><div class="card-bar-fill" style="width:<?= $cpuPct ?>%"></div></div></div>
<div class="card"><div class="card-label">Memory</div><div class="card-value"><?= e(SystemInfo::formatBytes(($system['memory']['used']??0) * 1024).' / '.SystemInfo::formatBytes(($system['memory']['total']??0) * 1024)) ?></div><div class="card-bar"><div class="card-bar-fill" style="width:<?= e($system['memory']['used_percent']??0) ?>%"></div></div></div>
<div class="card"><div class="card-label">Disk</div><div class="card-value"><?= e(SystemInfo::formatBytes($system['disk']['used']??0).' / '.SystemInfo::formatBytes($system['disk']['total']??0)) ?></div><div class="card-bar"><div class="card-bar-fill" style="width:<?= e($system['disk']['used_percent']??0) ?>%"></div></div></div>
<div class="card"><div class="card-label">Load</div><div class="card-value"><?= e(($system['load']['1m']??0).' / '.($system['load']['5m']??0).' / '.($system['load']['15m']??0)) ?></div></div>
<div class="card"><div class="card-label">Uptime</div><div class="card-value"><?= e($system['uptime']['human']??'—') ?></div></div>
</div>
<h2 class="section-title">Account resource limits</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>Account</th><th>Package</th><th>Disk</th><th>Memory</th><th>CPU</th><th>Traffic (req / bytes)</th><th>Status</th></tr></thead>
<tbody><?php if (empty($accounts)): ?><?= empty_state(7, 'No accounts.') ?>
<?php else: foreach ($accounts as $a): $tr = $traffic[(int) ($a['id'] ?? 0)] ?? null; ?><tr><td><?= e($a['username']) ?></td><td><?= e($a['package_name']??'—') ?></td>
<td><?= e(SystemInfo::formatBytes(((float)($a['disk_quota_mb']??0)) * 1024 * 1024)) ?></td><td><?= e(SystemInfo::formatBytes(((float)($a['max_memory_mb']??0)) * 1024 * 1024)) ?></td><td><?= e($a['max_cpu_percent']??0) ?>%</td><td><?= $tr === null ? '—' : e((string) $tr['requests']) . ' / ' . e(SystemInfo::formatBytes((float) $tr['bytes'])) ?></td>
<td><?= $a['status']==='active'?'<span class="status status-active">Active</span>':'<span class="status status-disabled">'.e($a['status']).'</span>' ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>