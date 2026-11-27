<?php $old = $old ?? []; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Add domain</h2></div>
  <form method="post" action="<?= e(url('panel/domains')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="main">

    <div class="field">
      <label class="field-label" for="name">Domain name <span class="text-muted">*</span></label>
      <input class="input" type="text" id="name" name="name" value="<?= e($old['name'] ?? '') ?>" placeholder="example.com" required>
      <p class="field-help">Your primary website is served from <code>public_html</code>.</p>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Add domain</button>
      <a href="<?= e(url('panel/domains')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>