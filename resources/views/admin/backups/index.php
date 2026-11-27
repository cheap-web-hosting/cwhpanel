<?php $backups = $backups ?? []; ?>
<?php
$plan = $plan ?? null;
$planComponents = $planComponents ?? [];
$planEnabled = $planEnabled ?? ['files', 'databases'];
$fmtTs = static fn ($t) => ((int) $t) > 0 ? date('Y-m-d H:i', (int) $t) : '—';
?>
<div class="panel" id="backup-plan" style="margin-bottom:16px">
  <div class="panel-header"><h2 class="panel-title">Automatic backups — all accounts</h2></div>
  <form method="post" action="<?= e(url('admin/backups/plan')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <label class="check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" <?= !empty($plan['enabled']) ? 'checked' : '' ?>> <span>Back up <strong>every active account</strong> automatically</span></label>
    <div class="check-grid">
      <?php foreach ($planComponents as $key => $label): ?>
        <label class="check"><input type="checkbox" name="components[]" value="<?= e($key) ?>" <?= in_array($key, $planEnabled, true) ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="filter-bar" style="border:0;padding:0">
      <label class="field-label" for="plan_frequency" style="margin:0">Frequency</label>
      <select class="input" id="plan_frequency" name="frequency" style="max-width:180px">
        <option value="daily" <?= (($plan['frequency'] ?? 'daily') === 'daily') ? 'selected' : '' ?>>Daily</option>
        <option value="weekly" <?= (($plan['frequency'] ?? '') === 'weekly') ? 'selected' : '' ?>>Weekly</option>
      </select>
      <label class="field-label" for="plan_keep" style="margin:0">Keep (sets per account)</label>
      <input class="input" id="plan_keep" type="number" name="keep_local" min="1" max="365" value="<?= (int) ($plan['keep_local'] ?? 7) ?>" style="max-width:100px">
      <button type="submit" class="btn btn-primary">Save plan</button>
    </div>
    <p class="field-help" style="margin:0">Runs one backup set per account (selected components) on the schedule, then prunes to the keep count. DNS exports are stored as <code>&lt;set&gt;.dns.txt</code> next to the archives.</p>
  </form>
  <div style="padding:0 16px 16px">
    <p class="field-help" style="margin:0 0 8px">
      Last run: <?= e($fmtTs($plan['last_run_at'] ?? 0)) ?> —
      <?= e((string) ($plan['last_status'] ?? 'never run')) ?>;
      next run: <?= e($fmtTs($plan['next_run_at'] ?? 0)) ?>
      <?php if (!empty($plan['last_error'])): ?><br><span class="text-muted"><?= e($plan['last_error']) ?></span><?php endif; ?>
    </p>
    <form method="post" action="<?= e(url('admin/backups/plan/run')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm" <?= empty($plan['enabled']) ? 'disabled' : '' ?>>Run now</button></form>
  </div>
</div>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Backups</h2><a href="<?= e(url('admin/backups/create')) ?>" class="btn btn-primary">Create Backup</a></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Account</th><th>Type</th><th>Status</th><th></th></tr></thead>
<tbody><?php if (empty($backups)): ?><?= empty_state(5, 'No backups yet.') ?>
<?php else: foreach ($backups as $b): ?><tr><td><code><?= e($b['name']) ?></code></td><td><?= e($b['username']??'—') ?></td><td><?= e(\Modules\Backups\Services\BackupService::typeLabel($b['type'])) ?></td>
<td><span class="status <?= $b['status'] === 'completed' ? 'status-active' : ($b['status'] === 'failed' ? 'status-failed' : 'status-disabled') ?>"><?= e($b['status']) ?></span></td>
<td class="actions"><?php if (!empty($b['artifacts'])): foreach ($b['artifacts'] as $a): ?><a class="btn btn-sm" href="<?= e(url('admin/backups/'.$b['id'].'/download?file='.urlencode($a['file']))) ?>">Download <?= e($a['file']) ?></a><?php endforeach; endif; ?><?php if ($b['status'] === 'completed'): ?><form method="post" action="<?= e(url('admin/backups/'.$b['id'].'/restore')) ?>" class="inline-form"><?= csrf_field() ?><label><input type="checkbox" name="confirm_restore" value="1" required> I confirm restoring <?= e($b['name']) ?> may overwrite current files and databases for <?= e($b['username'] ?? $b['account_id']) ?>.</label><button type="submit" class="btn btn-sm">Restore</button></form><?php endif; ?>
<form method="post" action="<?= e(url('admin/backups/'.$b['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete backup?">Delete</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>
