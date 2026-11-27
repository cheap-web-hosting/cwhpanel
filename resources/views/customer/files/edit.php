<?php $account = $account ?? ''; $path = $path ?? ''; $content = $content ?? ''; $basePath = $basePath ?? '/panel/files'; ?>
<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Editing: <?= e($path) ?></h2>
    <a href="<?= e($basePath . '?path=' . urlencode(dirname($path))) ?>" class="btn btn-sm">← Back</a>
  </div>
  <form method="post" action="<?= e($basePath . '/edit') ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="path" value="<?= e($path) ?>">
    <label class="field-label" for="file-content"><?= e($path) ?></label>
    <textarea class="input" id="file-content" name="content" rows="24" style="font-family:monospace"><?= e($content) ?></textarea>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save</button>
    </div>
  </form>
</div>