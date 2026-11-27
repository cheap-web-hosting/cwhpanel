<?php $old = $old ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Add FTP account</h2></div>
<form method="post" action="<?= e(url('panel/ftp')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="username">Username <span class="text-muted">*</span></label><input class="input" type="text" id="username" name="username" value="<?= e($old['username']??'') ?>" required></div>
<div class="field"><label class="field-label" for="password">Password <span class="text-muted">*</span></label><input class="input" type="password" autocomplete="new-password" id="password" name="password" required minlength="8"><p class="field-help">Minimum 8 characters.</p></div>
<div class="field"><label class="field-label" for="home_dir">Home directory <span class="text-muted">*</span></label><input class="input" type="text" id="home_dir" name="home_dir" placeholder="/home/username/public_html" required></div>
<div class="field"><label class="field-label" for="quota_mb">Quota (MB)</label><input class="input" type="number" id="quota_mb" name="quota_mb" value="1024" min="1"></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Create</button><a href="<?= e(url('panel/ftp')) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>