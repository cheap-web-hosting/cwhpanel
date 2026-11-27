<?php
$website = $website ?? null;
$old = $old ?? [];
$accounts = $accounts ?? [];
$domains = $domains ?? [];

function wsValue(?array $website, array $old, string $key, $default = '') {
    if (array_key_exists($key, $old)) return $old[$key];
    return $website[$key] ?? $default;
}
?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title"><?= $website ? 'Edit website' : 'Add website' ?></h2></div>
  <form method="post" action="<?= e($website ? url('admin/websites/' . $website['id']) : url('admin/websites')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>

    <div class="field">
      <label class="field-label" for="label">Label <span class="text-muted">*</span></label>
      <input class="input" type="text" id="label" name="label" value="<?= e(wsValue($website, $old, 'label')) ?>" required>
    </div>

    <div class="field">
      <label class="field-label" for="account_id">Account <span class="text-muted">*</span></label>
      <select class="input" id="account_id" name="account_id" required <?= $website ? 'disabled' : '' ?>>
        <option value="">Select account…</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= e($a['id']) ?>" <?= (string) wsValue($website, $old, 'account_id') === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['username']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($website): ?><input type="hidden" name="account_id" value="<?= e($website['account_id']) ?>"><?php endif; ?>
    </div>

    <div class="field">
      <label class="field-label" for="domain_id">Domain <span class="text-muted">*</span></label>
      <select class="input" id="domain_id" name="domain_id" required <?= $website ? 'disabled' : '' ?>>
        <option value="">Select domain…</option>
        <?php foreach ($domains as $d): ?>
          <option value="<?= e($d['id']) ?>" <?= (string) wsValue($website, $old, 'domain_id') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($website): ?><input type="hidden" name="domain_id" value="<?= e($website['domain_id']) ?>"><?php endif; ?>
    </div>

    <div class="field">
      <label class="field-label" for="document_root">Document root <span class="text-muted">*</span></label>
      <input class="input" type="text" id="document_root" name="document_root" value="<?= e(wsValue($website, $old, 'document_root', '/home/' . ($website['username'] ?? 'user') . '/public_html')) ?>" required>
    </div>

    <div class="field">
      <label class="field-label" for="php_version">PHP version</label>
      <select class="input" id="php_version" name="php_version">
        <?php $curPhp = \Modules\Core\Services\PhpVersionsService::id((string) wsValue($website, $old, 'php_version', '')); ?>
        <option value="" <?= $website === null && !array_key_exists('php_version', $old) ? 'selected' : '' ?>>Package default</option>
        <?php foreach (\Modules\Core\Services\PhpVersionsService::AVAILABLE as $vid => $vlabel): ?>
          <option value="<?= e((string) $vid) ?>" <?= $curPhp === (string) $vid ? 'selected' : '' ?>><?= e('PHP ' . $vlabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save</button>
      <a href="<?= e(url('admin/websites')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>