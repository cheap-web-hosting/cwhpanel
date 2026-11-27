<div class="panel"><div class="panel-header"><h2 class="panel-title">Create backup</h2></div>
<form method="post" action="<?= e(url('panel/backups')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="type">Backup type</label>
<select class="input" id="type" name="type">
  <option value="full" selected>Full (files + databases)</option>
  <option value="files">Files only</option>
  <option value="databases">Databases only</option>
</select>
<p class="field-help">Files back up account home directories. Skip database backup if provisioning is disabled.</p></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Create backup</button><a href="<?= e(url('panel/backups')) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>