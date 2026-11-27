<?php $old = $old ?? []; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Add parked domain</h2></div>
  <form method="post" action="<?= e(url('panel/domains')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="parked">

    <div class="field">
      <label class="field-label" for="name">Domain name <span class="text-muted">*</span></label>
      <input class="input" type="text" id="name" name="name" value="<?= e($old['name'] ?? '') ?>" placeholder="example.com" required>
      <p class="field-help">A parked domain shows the same content as your main site, or redirects to a URL below.</p>
    </div>

    <div class="field">
      <label class="field-label" for="redirect_url">Redirect to <span class="text-muted">(optional)</span></label>
      <input class="input" type="text" id="redirect_url" name="redirect_url" value="<?= e($old['redirect_url'] ?? '') ?>" placeholder="https://example.com">
      <p class="field-help">Leave empty to serve your main site's content. Otherwise visitors are redirected here.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Add parked domain</button>
      <a href="<?= e(url('panel/domains')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>