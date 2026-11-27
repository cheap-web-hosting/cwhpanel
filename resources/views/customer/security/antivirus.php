<?php $scans = $scans ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Antivirus</h2></div>
<div class="panel" style="padding:16px"><form method="post" action="<?= e(url('panel/security/antivirus/scan')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-primary" data-confirm="Scan your account files for malware? This may take a moment.">Scan my account</button></form></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Result</th><th>Files scanned</th><th>Infected</th><th>Duration</th><th>Time</th></tr></thead>
<tbody><?php if (empty($scans)): ?><?= empty_state(5, 'No scans yet.') ?>
<?php else: foreach ($scans as $s): ?><tr>
<td><?= $s['status']==='clean'?'<span class="status status-active">Clean</span>':'<span class="status status-disabled">Infected</span>' ?></td>
<td><?= e($s['scanned_files']) ?></td><td><?= $s['infected_files'] ? '<span class="badge badge-warning">'.e($s['infected_files']).'</span>' : 0 ?></td>
<td><?= e($s['duration_seconds']) ?>s</td><td class="nowrap"><?= e($s['created_at']) ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
</div>