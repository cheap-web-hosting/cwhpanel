<?php $jobs = $jobs ?? []; $status = $status ?? ''; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Jobs</h2></div>
<form method="get" action="<?= e(url('admin/jobs')) ?>" class="filter-bar">
<select class="input" name="status"><option value="">All</option>
<option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
<option value="running" <?= $status==='running'?'selected':'' ?>>Running</option>
<option value="failed" <?= $status==='failed'?'selected':'' ?>>Failed</option>
<option value="completed" <?= $status==='completed'?'selected':'' ?>>Completed</option>
<option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
</select><button type="submit" class="btn">Filter</button></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Status</th><th style="min-width:220px">Detail</th><th>Attempts</th><th>Created</th><th>Finished</th><th></th></tr></thead>
<tbody><?php if (empty($jobs)): ?><?= empty_state(7, 'No jobs.') ?>
<?php else: foreach ($jobs as $j): ?><tr><td><code><?= e($j['type']) ?></code></td>
<td><?= match($j['status']){'completed'=>'<span class="status status-active">Completed</span>','failed'=>'<span class="badge badge-warning">Failed</span>','running'=>'<span class="status status-active">Running</span>','cancelled'=>'<span class="status status-disabled">Cancelled</span>',default=>'<span class="status status-disabled">'.e($j['status']).'</span>'} ?></td>
<td class="text-muted"><?= e($j['result'] ?? '') ?></td>
<td><?= e($j['attempts']) ?></td><td class="nowrap"><?= e($j['created_at']) ?></td><td class="nowrap"><?= e($j['finished_at']??'—') ?></td>
<td class="actions"><?php if ($j['status']==='failed'): ?><form method="post" action="<?= e(url('admin/jobs/'.$j['id'].'/retry')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm">Retry</button></form><?php endif; ?>
<?php if (in_array($j['status'],['pending','running'],true)): ?><form method="post" action="<?= e(url('admin/jobs/'.$j['id'].'/cancel')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>