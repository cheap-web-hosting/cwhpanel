<?php $account = $account ?? ''; $path = $path ?? ''; $content = $content ?? ''; ?>
<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Editing: <?= e($path) ?></h2>
    <a href="<?= e(url('admin/files?account=' . urlencode($account) . '&path=' . urlencode(dirname($path)))) ?>" class="btn btn-sm">← Back</a>
  </div>
  <form method="post" action="<?= e(url('admin/files/' . urlencode($account) . '/edit')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="path" value="<?= e($path) ?>">
    <textarea class="input" name="content" rows="24" style="font-family:monospace"><?= e($content) ?></textarea>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save</button>
    </div>
  </form>
</div>