<?php $jobs = $jobs ?? []; ?>
<?php $dur = function ($ms) { if ($ms === null || $ms === '') return '—'; $ms = (int) $ms; return $ms < 1000 ? $ms . ' ms' : number_format($ms / 1000, 2) . ' s'; }; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">My cron jobs</h2><a href="<?= e(url('panel/cron/create')) ?>" class="btn btn-primary">Add Cron Job</a></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Schedule</th><th>Command</th><th>Status</th><th>Last run</th><th>Exit</th><th>Duration</th><th>Output</th><th></th></tr></thead>
<tbody><?php if (empty($jobs)): ?><?= empty_state(8, 'No cron jobs.') ?>
<?php else: foreach ($jobs as $j): ?><tr><td><code><?= e($j['schedule']) ?></code></td><td><code><?= e($j['command']) ?></code></td>
<td><?= $j['status']==='enabled'?'<span class="status status-active">Enabled</span>':'<span class="status status-disabled">Disabled</span>' ?></td>
<td class="nowrap"><?= e($j['last_run_at'] ?? '—') ?></td>
<td><?= ($j['last_exit'] ?? null) === null ? '—' : ((int) $j['last_exit'] === 0 ? '<span class="status status-active">0</span>' : '<span class="status status-error">' . (int) $j['last_exit'] . '</span>') ?></td>
<td class="nowrap"><?= e($dur($j['last_duration_ms'] ?? null)) ?></td>
<td class="ellipsis" title="<?= e($j['last_output'] ?? '') ?>"><?= e(mb_substr((string) ($j['last_output'] ?? ''), 0, 120)) ?></td>
<td class="actions"><form method="post" action="<?= e(url('panel/cron/'.$j['id'].'/run')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm">Run now</button></form>
<form method="post" action="<?= e(url('panel/cron/'.$j['id'].'/toggle')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm">Toggle</button></form>
<form method="post" action="<?= e(url('panel/cron/'.$j['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete?">Delete</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>