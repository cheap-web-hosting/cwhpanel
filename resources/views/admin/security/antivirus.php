<?php $scans = $scans ?? []; $accounts = $accounts ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Antivirus</h2></div>
<div class="panel" style="padding:16px">
<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;border-bottom:1px solid #e5e7eb;padding-bottom:12px;margin-bottom:12px">
<form method="post" action="<?= e(url('admin/security/antivirus/scan')) ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0"><?= csrf_field() ?>
<select class="input" name="account_id" required><option value="">Select account to scan…</option><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['username']) ?></option><?php endforeach; ?></select>
<button type="submit" class="btn btn-primary">Run scan</button>
</form>
<form method="post" action="<?= e(url('admin/security/antivirus/scan-all')) ?>" style="margin:0"><?= csrf_field() ?>
<button type="submit" class="btn btn-sm" data-confirm="Scan all active accounts? This can take a while.">Scan all accounts</button>
</form>
</div>
<div class="table-wrap"><table class="table"><thead><tr><th>Account</th><th>Result</th><th>Files</th><th>Infected</th><th>Duration</th><th>Time</th></tr></thead>
<tbody><?php if (empty($scans)): ?><?= empty_state(6, 'No scans yet.') ?>
<?php else: foreach ($scans as $s): ?><tr><td><?= e($s['username']??'—') ?></td>
<td><?= $s['status']==='clean'?'<span class="status status-active">Clean</span>':'<span class="status status-disabled">Infected</span>' ?></td>
<td><?= e($s['scanned_files']) ?></td><td><?= $s['infected_files']?'<span class="badge badge-warning">'.e($s['infected_files']).'</span>':0 ?></td>
<td><?= e($s['duration_seconds']) ?>s</td><td class="nowrap"><?= e($s['created_at']) ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
</div>