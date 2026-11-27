<?php
$account = $account ?? null;
$old = $old ?? [];
$packages = $packages ?? [];

function acctValue(?array $account, array $old, string $key, $default = '') {
    if (array_key_exists($key, $old)) return $old[$key];
    return $account[$key] ?? $default;
}
?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title"><?= $account ? 'Edit account' : 'Add account' ?></h2>
  </div>
  <?php if (!$packages): ?>
    <p style="padding:16px">Create or activate a hosting package before saving an account. <a href="<?= e(url('admin/packages')) ?>">Manage packages</a></p>
  <?php else: ?>
  <form method="post" action="<?= e($account ? url('admin/accounts/' . $account['id']) : url('admin/accounts')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>

    <?php if (!$account): ?>
      <div class="field">
        <label class="field-label" for="username">System username <span class="text-muted">*</span></label>
        <input class="input" type="text" id="username" name="username" value="<?= e(acctValue($account, $old, 'username')) ?>" required pattern="[A-Za-z0-9_-]+">
        <p class="field-help">Lowercase letters, numbers, dashes and underscores. This becomes the Linux system user.</p>
      </div>
    <?php else: ?>
      <div class="field">
        <label class="field-label">System username</label>
        <input class="input" type="text" value="<?= e($account['username']) ?>" disabled>
      </div>
    <?php endif; ?>

    <div class="field">
      <label class="field-label" for="domain">Primary domain <span class="text-muted">*</span></label>
      <input class="input" type="text" id="domain" name="domain" value="<?= e(acctValue($account, $old, 'domain')) ?>" required>
    </div>

    <div class="field">
      <label class="field-label" for="email">Contact email <span class="text-muted">*</span></label>
      <input class="input" type="email" id="email" name="email" value="<?= e(acctValue($account, $old, 'email')) ?>" required>
      <p class="field-help">Also becomes the customer panel login (created automatically).</p>
    </div>

    <div class="field">
      <label class="field-label" for="package_id">Hosting package <span class="text-muted">*</span></label>
      <select class="input" id="package_id" name="package_id" required>
        <option value="">Select a package…</option>
        <?php foreach ($packages as $p): ?>
          <option value="<?= e($p['id']) ?>" <?= (string) acctValue($account, $old, 'package_id') === (string) $p['id'] ? 'selected' : '' ?>>
            <?= e($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if (!$account): ?>
      <div class="field">
        <label class="field-label" for="password">Password <span class="text-muted">*</span></label>
        <input class="input" type="password" autocomplete="new-password" id="password" name="password" required minlength="<?= e(config('auth.min_password_length', 10)) ?>">
        <p class="field-help">Used for the customer panel login and system account. Minimum <?= e(config('auth.min_password_length', 10)) ?> characters.</p>
      </div>
    <?php endif; ?>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= $account ? 'Save account' : 'Create account' ?></button>
      <a href="<?= e(url('admin/accounts')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
</div>
