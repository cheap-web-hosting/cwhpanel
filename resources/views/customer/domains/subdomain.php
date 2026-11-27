<?php $old = $old ?? []; $parentDomains = $parentDomains ?? []; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Add subdomain</h2></div>

  <?php if (empty($parentDomains)): ?>
    <p class="empty" style="padding:16px">You need a domain first — add a main or addon domain, then create subdomains under it.</p>
  <?php else: ?>
  <form method="post" action="<?= e(url('panel/domains')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="subdomain">

    <div class="field">
      <label class="field-label" for="parent_domain_id">Parent domain <span class="text-muted">*</span></label>
      <select class="input" id="parent_domain_id" name="parent_domain_id" required>
        <?php foreach ($parentDomains as $p): ?>
          <option value="<?= e($p['id']) ?>" <?= (int)($old['parent_domain_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="field-label" for="subdomain">Subdomain <span class="text-muted">*</span></label>
      <input class="input" type="text" id="subdomain" name="subdomain" value="<?= e($old['subdomain'] ?? '') ?>" placeholder="blog" required>
      <p class="field-help">Creates <code>blog.yourdomain.com</code>, served from <code>public_html/blog</code>.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Add subdomain</button>
      <a href="<?= e(url('panel/domains')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
</div>