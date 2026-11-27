<?php $old = $old ?? []; $domains = $domains ?? []; $phpVersions = $phpVersions ?? ['84' => '8.4 (system)']; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Add website</h2></div>
  <?php if (!$domains): ?>
    <p style="padding:16px">Add an active domain before creating a website. <a href="<?= e(url('panel/domains')) ?>">Manage domains</a></p>
  <?php else: ?>
  <form method="post" action="<?= e(url('panel/websites')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="label">Label <span class="text-muted">*</span></label>
      <input class="input" type="text" id="label" name="label" value="<?= e($old['label'] ?? '') ?>" required>
    </div>
    <div class="field">
      <label class="field-label" for="domain_id">Domain <span class="text-muted">*</span></label>
      <select class="input" id="domain_id" name="domain_id" required>
        <option value="">Select domain…</option>
        <?php foreach ($domains as $d): ?>
          <option value="<?= e($d['id']) ?>" <?= (string) ($old['domain_id'] ?? '') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="document_root">Document root <span class="text-muted">*</span></label>
      <input class="input" type="text" id="document_root" name="document_root" value="<?= e($old['document_root'] ?? '') ?>" placeholder="/home/username/public_html" required>
    </div>
    <div class="field">
      <label class="field-label" for="php_version">PHP version</label>
      <select class="input" id="php_version" name="php_version">
        <?php foreach ($phpVersions as $id => $label): $ver = substr($id,0,1) . '.' . substr($id,1,1); ?>
          <option value="<?= e($ver) ?>" <?= (($old['php_version'] ?? '8.4') === $ver ? 'selected' : '') ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Create website</button>
      <a href="<?= e(url('panel/websites')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
</div>
