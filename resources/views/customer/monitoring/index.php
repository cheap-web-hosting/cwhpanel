<?php
use Modules\Core\Services\SystemInfo;
$system = $system ?? []; $account = $account ?? null; $diskUsedBytes = $diskUsedBytes ?? null; $traffic = $traffic ?? null;
?>
<?php if (is_array($traffic)): ?>
<h2 class="section-title">Website traffic (access logs)</h2>
<div class="cards-grid">
<div class="card"><div class="card-label">Requests</div><div class="card-value"><?= e((string) ($traffic['totals']['requests'] ?? 0)) ?></div></div>
<div class="card"><div class="card-label">Bandwidth</div><div class="card-value"><?= e(SystemInfo::formatBytes((float) ($traffic['totals']['bytes'] ?? 0))) ?></div></div>
<div class="card"><div class="card-label">Visitors (approx)</div><div class="card-value"><?= e((string) ($traffic['totals']['visitors'] ?? 0)) ?></div></div>
</div>
<?php foreach (($traffic['sites'] ?? []) as $site): $st = $site['stats']; ?>
<h3 class="section-title"><?= e($site['label']) ?></h3>
<div class="panel"><div class="table-wrap"><table class="table">
<thead><tr><th>Metric</th><th>Value</th></tr></thead>
<tbody>
<tr><td>Requests</td><td><?= e((string) ($st['requests'] ?? 0)) ?></td></tr>
<tr><td>Bandwidth</td><td><?= e(SystemInfo::formatBytes((float) ($st['bytes'] ?? 0))) ?></td></tr>
<tr><td>Visitors (approx)</td><td><?= e((string) ($st['visitors'] ?? 0)) ?></td></tr>
<tr><td>Status 2xx / 3xx / 4xx / 5xx</td><td><?= e((string) ($st['byStatus']['2xx'] ?? 0)) ?> / <?= e((string) ($st['byStatus']['3xx'] ?? 0)) ?> / <?= e((string) ($st['byStatus']['4xx'] ?? 0)) ?> / <?= e((string) ($st['byStatus']['5xx'] ?? 0)) ?></td></tr>
<tr><td>Top pages</td><td><?php if (empty($st['topPaths'])): ?>—<?php else: ?><?php foreach ($st['topPaths'] as $tp): ?><code><?= e($tp['path']) ?></code> (<?= e((string) $tp['hits']) ?>)<br><?php endforeach; ?><?php endif; ?></td></tr>
<tr><td>By day</td><td><?php if (empty($st['byDay'])): ?>—<?php else: ?><?php foreach ($st['byDay'] as $day => $row): ?><code><?= e($day) ?></code>: <?= e((string) $row['requests']) ?> req, <?= e(SystemInfo::formatBytes((float) $row['bytes'])) ?><br><?php endforeach; ?><?php endif; ?></td></tr>
</tbody>
</table></div></div>
<?php endforeach; ?>
<?php endif; ?>
<h2 class="section-title">Your account usage</h2>
<div class="cards-grid">
<div class="card"><div class="card-label">Disk used</div><div class="card-value"><?= e($diskUsedBytes === null ? 'Not measured' : SystemInfo::formatBytes((float) $diskUsedBytes)) ?></div></div>
<div class="card"><div class="card-label">Disk quota</div><div class="card-value"><?= e($account && $account['disk_quota_mb'] !== null ? SystemInfo::formatBytes(((float)$account['disk_quota_mb']) * 1024 * 1024) : '—') ?></div></div>
</div>
<h2 class="section-title">Your account limits</h2>
<div class="cards-grid">
<?php if ($account): ?>
<div class="card"><div class="card-label">Memory limit</div><div class="card-value"><?= e(SystemInfo::formatBytes(((float)($account['max_memory_mb']??0)) * 1024 * 1024)) ?></div></div>
<div class="card"><div class="card-label">CPU limit</div><div class="card-value"><?= e($account['max_cpu_percent']??0) ?>%</div></div>
<div class="card"><div class="card-label">Package</div><div class="card-value"><?= e($account['package_name']??'—') ?></div></div>
<?php else: ?>
<div class="card"><div class="card-label">No account</div><div class="card-value">Not linked to a hosting account.</div></div>
<?php endif; ?>
<div class="card"><div class="card-label">Server PHP</div><div class="card-value"><?= e($system['php']??'—') ?></div></div>
</div>