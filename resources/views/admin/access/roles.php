<?php $roles = $roles ?? []; ?>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-header"><h2 class="panel-title">Create role</h2></div>
  <form method="post" action="<?= e(url('admin/access/roles')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="role-name">Name</label>
      <input class="input" type="text" id="role-name" name="name" required maxlength="191" placeholder="Support agent" style="max-width:280px">
    </div>
    <div class="field">
      <label class="field-label" for="role-slug">Slug <span class="text-muted">(lowercase, digits, dashes — used in URLs)</span></label>
      <input class="input" type="text" id="role-slug" name="slug" required maxlength="64" placeholder="support" style="max-width:280px">
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Create role</button></div>
  </form>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Slug</th>
        <th>System</th>
        <th>Permissions</th>
        <th>Users</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($roles)): ?>
        <?= empty_state(6, 'No roles defined.') ?>
      <?php else: ?>
        <?php foreach ($roles as $role): ?>
          <tr>
            <td><?= e($role['name']) ?></td>
            <td><code><?= e($role['slug']) ?></code></td>
            <td><?= $role['is_system'] ? '<span class="badge">System</span>' : '—' ?></td>
            <td><?= e($role['permission_count'] ?? 0) ?></td>
            <td><?= e($role['user_count'] ?? 0) ?></td>
            <td class="actions">
              <a href="<?= e(url('admin/access/roles/' . $role['id'])) ?>" class="btn btn-sm">Manage</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
