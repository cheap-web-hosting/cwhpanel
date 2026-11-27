<?php $user = $user ?? null; $roles = $roles ?? []; $isEdit = $user !== null; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title"><?= $isEdit ? 'Edit Admin: ' . e($user['email']) : 'Add Admin User' ?></h2></div>
  <form method="post" action="<?= e(url($isEdit ? 'admin/access/users/' . $user['id'] : 'admin/access/users')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
    <?php if (!$isEdit): ?>
      <div class="field">
        <label class="field-label" for="user-email">Email</label>
        <input class="input" type="email" id="user-email" name="email" required maxlength="255" style="max-width:320px">
      </div>
    <?php endif; ?>
    <div class="field">
      <label class="field-label" for="user-name">Display name</label>
      <input class="input" type="text" id="user-name" name="name" required maxlength="191" value="<?= e($user['name'] ?? '') ?>" style="max-width:320px">
    </div>
    <div class="field">
      <label class="field-label" for="user-role">Role</label>
      <select class="input" id="user-role" name="role_id" style="max-width:320px">
        <?php foreach ($roles as $r): ?>
          <option value="<?= (int) $r['id'] ?>" <?= ((int) ($user['role_id'] ?? 0) === (int) $r['id']) ? 'selected' : '' ?>>
            <?= e($r['name']) ?><?= $r['is_system'] ? ' (system)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="field-help">Permissions come from the role. Changing your own role is refused.</p>
    </div>
    <?php if ($isEdit): ?>
      <div class="field">
        <label class="field-label" for="user-status">Status</label>
        <select class="input" id="user-status" name="status" style="max-width:160px">
          <option value="active" <?= (($user['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
          <option value="disabled" <?= (($user['status'] ?? 'active') !== 'active') ? 'selected' : '' ?>>Disabled</option>
        </select>
        <p class="field-help">Deactivating yourself is refused.</p>
      </div>
    <?php endif; ?>
    <div class="field">
      <label class="field-label" for="user-password"><?= $isEdit ? 'New password (leave blank to keep)' : 'Password' ?></label>
      <input class="input" type="password" id="user-password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="<?= e(config('auth.min_password_length', 10)) ?>" autocomplete="new-password" style="max-width:320px">
      <p class="field-help">Minimum <?= e(config('auth.min_password_length', 10)) ?> characters.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save' : 'Create admin user' ?></button>
      <a href="<?= e(url('admin/access/users')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
