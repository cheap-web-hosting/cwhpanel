<?php $accounts = $accounts ?? []; $domains = $domains ?? []; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Issue certificate</h2></div>
  <form method="post" action="<?= e(url('admin/ssl')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="domain_name">Domain <span class="text-muted">*</span></label>
      <input class="input" type="text" id="domain_name" name="domain_name" placeholder="example.com" required>
    </div>
    <div class="field">
      <label class="field-label" for="account_id">Account <span class="text-muted">*</span></label>
      <select class="input" id="account_id" name="account_id" required>
        <option value="">Select account…</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= e($a['id']) ?>"><?= e($a['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="domain_id">Domain record <span class="text-muted">*</span></label>
      <select class="input" id="domain_id" name="domain_id" required>
        <option value="">Select…</option>
        <?php foreach ($domains as $d): ?>
          <option value="<?= e($d['id']) ?>"><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="type">Certificate type</label>
      <select class="input" id="type" name="type">
        <option value="self-signed">Self-signed (instant)</option>
        <option value="acme">Let's Encrypt (ACME, requires public DNS)</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label">
        <input type="checkbox" name="auto_renew" value="1" style="vertical-align:middle">
        Auto-renew before expiry (Let's Encrypt only)
      </label>
    </div>
    <div class="field">
      <label class="field-label" for="web_root">Web root (for ACME HTTP-01 challenge)</label>
      <input class="input" type="text" id="web_root" name="web_root" placeholder="/home/username/public_html">
      <p class="field-help">Only needed for Let's Encrypt; the challenge file is written here.</p>
    </div>
    <div class="field">
      <label class="field-label" for="days">Validity (days)</label>
      <input class="input" type="number" id="days" name="days" value="90" min="1" max="3650">
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Issue certificate</button>
      <a href="<?= e(url('admin/ssl')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>