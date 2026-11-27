<?php
$accounts = $accounts ?? []; $old = $old ?? [];
$runtime = $runtime ?? 'node'; $base = $base ?? '/admin/nodejs'; $prefix = ltrim($base, '/');
$isPython = $runtime === 'python'; $label = $isPython ? 'Python' : 'Node.js';
$versions = \Modules\Applications\Services\AppService::RUNTIMES[$runtime] ?? [];
?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Add <?= e($label) ?> application</h2></div>
<form method="post" action="<?= e(url($prefix)) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="name">App name <span class="text-muted">*</span></label><input class="input" type="text" id="name" name="name" value="<?= e($old['name']??'') ?>" placeholder="my-app" required></div>
<div class="field"><span class="field-label">Runtime</span><div><span class="status status-active"><?= e($label) ?></span></div></div>
<div class="field"><label class="field-label" for="runtime_version"><?= e($label) ?> version</label><select class="input" id="runtime_version" name="runtime_version"><?php foreach ($versions as $v => $vlabel): ?><option value="<?= e($v) ?>"><?= e($vlabel) ?></option><?php endforeach; ?></select></div>
<div class="field"><label class="field-label" for="entry_point">Entry point <span class="text-muted">*</span></label><input class="input" type="text" id="entry_point" name="entry_point" value="<?= e($old['entry_point'] ?? ($isPython ? 'app.py' : 'app.js')) ?>" required></div>
<div class="field"><label class="field-label" for="account_id">Account <span class="text-muted">*</span></label><select class="input" id="account_id" name="account_id" required><option value="">Select…</option><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['username']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label class="field-label" for="git_url">Git repository URL</label><input class="input" type="text" id="git_url" name="git_url" value="<?= e($old['git_url']??'') ?>" placeholder="https://github.com/…"></div>
<div class="field"><label class="field-label" for="git_branch">Branch</label><input class="input" type="text" id="git_branch" name="git_branch" value="main"></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Create</button><a href="<?= e(url($prefix)) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>
