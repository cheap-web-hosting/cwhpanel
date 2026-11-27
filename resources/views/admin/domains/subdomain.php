<?php
$domain = $domain ?? null;
$old = $old ?? [];
$accounts = $accounts ?? [];
$parentDomains = $parentDomains ?? [];
?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Add subdomain</h2></div>
  <form method="post" action="<?= e(url('admin/domains')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="subdomain">

    <div class="field">
      <label class="field-label" for="parent_domain_id">Parent domain <span class="text-muted">*</span></label>
      <select class="input" id="parent_domain_id" name="parent_domain_id" required>
        <option value="">Select parent domain…</option>
        <?php foreach ($parentDomains as $p): ?>
          <option value="<?= e($p['id']) ?>" <?= (int)($old['parent_domain_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="field-label" for="subdomain">Subdomain <span class="text-muted">*</span></label>
      <input class="input" type="text" id="subdomain" name="subdomain" value="<?= e($old['subdomain'] ?? '') ?>" placeholder="blog" required>
      <p class="field-help">Creates <code>blog.example.com</code>.</p>
    </div>

    <div class="field">
      <label class="field-label" for="account_id">Account <span class="text-muted">(inherits parent if empty)</span></label>
      <select class="input" id="account_id" name="account_id">
        <option value="">Inherit from parent</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= e($a['id']) ?>"><?= e($a['username']) ?> (<?= e($a['domain']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Add subdomain</button>
      <a href="<?= e(url('admin/domains')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>