<?php $accounts = $accounts ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Create backup</h2></div>
<form method="post" action="<?= e(url('admin/backups')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="account_id">Account <span class="text-muted">*</span></label><select class="input" id="account_id" name="account_id" required><option value="">Select…</option><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['username']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label class="field-label" for="type">Backup type</label>
<select class="input" id="type" name="type">
  <option value="full" selected>Full (files + databases)</option>
  <option value="files">Files only</option>
  <option value="databases">Databases only</option>
</select>
<p class="field-help">Files back up account home directories; databases use mysqldump. Skip database backup if provisioning is disabled.</p></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Create backup</button><a href="<?= e(url('admin/backups')) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>