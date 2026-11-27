<?php $old = $old ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Add database</h2></div>
<form method="post" action="<?= e(url('panel/databases')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="name">Database suffix <span class="text-muted">*</span></label><input class="input" type="text" id="name" name="name" value="<?= e($old['name']??'') ?>" placeholder="e.g. wordpress" required><p class="field-help">Full name: <code>yourusername_suffix</code></p></div>
<div class="field"><label class="field-label" for="username">DB username suffix</label><input class="input" type="text" id="username" name="username" value="user" required></div>
<div class="field"><label class="field-label" for="password">DB password <span class="text-muted">*</span></label><input class="input" type="password" autocomplete="new-password" id="password" name="password" required minlength="10"><p class="field-help">Minimum 10 characters. Shown once — copy it into the app config.</p></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Create</button><a href="<?= e(url('panel/databases')) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>