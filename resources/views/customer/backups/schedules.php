<?php
$schedules = $schedules ?? [];
$remotes = $remotes ?? [];
$frequencies = $frequencies ?? ['daily' => 86400, 'weekly' => 604800];
$types = $types ?? ['full', 'files', 'databases'];
$fmtTs = function ($ts) { return empty($ts) ? '—' : date('M j, Y H:i', (int) $ts); };
?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Backup schedules</h2></div>
  <p class="text-muted" style="padding:12px 16px 0">Automatic backups run daily via the background worker (create → upload → prune). Daily schedules run once per day, weekly ones every 7 days from the last run.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Frequency</th><th>Type</th><th>Keep local</th><th>Remote</th><th>Keep remote</th><th>Status</th><th>Next run</th><th style="width:200px"></th></tr></thead>
    <tbody>
      <?php if (empty($schedules)): ?><?= empty_state(9, 'No schedules yet — create one below.') ?>
      <?php else: foreach ($schedules as $s): ?>
        <tr<?= empty($s['enabled']) ? ' class="row-muted"' : '' ?>>
          <td><strong><?= e($s['name']) ?></strong></td>
          <td><?= e($s['frequency']) ?></td>
          <td><?= e($s['type']) ?></td>
          <td><?= (int) $s['keep_local'] ?></td>
          <td><?= e($s['remote_name'] ?? '—') ?></td>
          <td><?= $s['remote_id'] ? (int) $s['keep_remote'] : '—' ?></td>
          <td>
            <?php if (empty($s['enabled'])): ?><span class="status status-disabled">Paused</span>
            <?php elseif (($s['last_status'] ?? '') === 'failed'): ?><span class="status status-failed" title="<?= e($s['last_error'] ?? '') ?>">Failed</span>
            <?php else: ?><span class="status status-active"><?= e($s['last_status'] ?? 'Scheduled') ?></span><?php endif; ?>
          </td>
          <td class="nowrap"><?= e($fmtTs($s['next_run_at'] ?? null)) ?></td>
          <td class="actions">
            <form method="post" action="<?= e(url('panel/backups/schedules/' . $s['id'] . '/run')) ?>" class="inline-form"><?= csrf_field() ?>
              <button type="submit" class="btn btn-sm btn-primary">Run now</button>
            </form>
            <form method="post" action="<?= e(url('panel/backups/schedules/' . $s['id'] . '/toggle')) ?>" class="inline-form"><?= csrf_field() ?>
              <button type="submit" class="btn btn-sm"><?= empty($s['enabled']) ? 'Enable' : 'Pause' ?></button>
            </form>
            <form method="post" action="<?= e(url('panel/backups/schedules/' . $s['id'] . '/delete')) ?>" class="inline-form" data-confirm="Delete schedule <?= e($s['name']) ?>? Existing backups are kept."><?= csrf_field() ?>
              <button type="submit" class="btn btn-sm btn-danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="panel"><div class="panel-header"><h2 class="panel-title">New schedule</h2></div>
  <form method="post" action="<?= e(url('panel/backups/schedules')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
    <div class="field"><label class="field-label" for="sched-name">Name <span class="text-muted">*</span></label>
      <input class="input" id="sched-name" type="text" name="name" value="Daily backup" required maxlength="64"></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <div class="field"><label class="field-label" for="sched-freq">Frequency</label>
        <select class="input" id="sched-freq" name="frequency"><?php foreach ($frequencies as $f => $secs): ?><option value="<?= e($f) ?>"><?= e(ucfirst($f)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label class="field-label" for="sched-type">Contents</label>
        <select class="input" id="sched-type" name="type"><?php foreach ($types as $t): ?><option value="<?= e($t) ?>"><?= e(ucfirst($t)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label class="field-label" for="sched-keep-local">Keep local (count)</label>
        <input class="input" id="sched-keep-local" type="number" name="keep_local" value="7" min="1" max="90"></div>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <div class="field"><label class="field-label" for="sched-remote">Remote storage</label>
        <select class="input" id="sched-remote" name="remote_id">
          <option value="0">Local only</option>
          <?php foreach ($remotes as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?> (<?= e($r['bucket']) ?>)</option><?php endforeach; ?>
        </select>
        <?php if (empty($remotes)): ?><p class="field-help">Add one under <a href="<?= e(url('panel/backups/remotes')) ?>">Remote Storage</a> first.</p><?php endif; ?></div>
      <div class="field"><label class="field-label" for="sched-keep-remote">Keep remote (count, 0 = all)</label>
        <input class="input" id="sched-keep-remote" type="number" name="keep_remote" value="14" min="0" max="365"></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Create schedule</button></div>
  </form>
</div>
