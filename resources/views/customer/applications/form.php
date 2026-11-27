<?php
$old = $old ?? [];
$runtime = $runtime ?? 'node';
$base = $base ?? '/panel/nodejs';
$isPython = $runtime === 'python';
$label = $isPython ? 'Python' : 'Node.js';
$versions = \Modules\Applications\Services\AppService::RUNTIMES[$runtime] ?? [];
$defaultEntry = $isPython ? 'app.py' : 'app.js';
?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Add <?= e($label) ?> application</h2></div>
<form method="post" action="<?= e(url(ltrim($base, '/'))) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="name">App name <span class="text-muted">*</span></label><input class="input" type="text" id="name" name="name" value="<?= e($old['name']??'') ?>" placeholder="my-app" required></div>
<div class="field"><span class="field-label">Runtime</span><div><span class="status status-active"><?= e($label) ?></span></div></div>
<div class="field"><label class="field-label" for="runtime_version"><?= e($label) ?> version</label><select class="input" id="runtime_version" name="runtime_version"><?php foreach ($versions as $v => $vlabel): ?><option value="<?= e($v) ?>"><?= e($vlabel) ?></option><?php endforeach; ?></select></div>
<div class="field"><label class="field-label" for="entry_point">Entry point <span class="text-muted">*</span></label><input class="input" type="text" id="entry_point" name="entry_point" value="<?= e($old['entry_point'] ?? $defaultEntry) ?>" required><?php if ($isPython): ?><p class="field-help">Runs inside an isolated per-app virtualenv.</p><?php endif; ?></div>
<div class="field"><label class="field-label" for="git_url">Git repository URL</label><input class="input" type="text" id="git_url" name="git_url" value="<?= e($old['git_url']??'') ?>" placeholder="https://github.com/…"></div>
<div class="field"><label class="field-label" for="git_branch">Branch</label><input class="input" type="text" id="git_branch" name="git_branch" value="main"></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Create</button><a href="<?= e(url(ltrim($base, '/'))) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>
