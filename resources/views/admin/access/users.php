<?php $users = $users ?? []; ?>
<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Admin Users</h2>
    <a href="<?= e(url('admin/access/users/create')) ?>" class="btn btn-primary btn-sm">Add admin user</a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($users)): ?>
          <?= empty_state(6, 'No admin users.') ?>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= e($u['email']) ?></td>
              <td><?= e($u['name']) ?></td>
              <td><?= e($u['role_name'] ?? '—') ?><br><small class="text-muted"><?= e($u['role_slug'] ?? '') ?></small></td>
              <td><?= ($u['status'] ?? 'active') === 'active' ? '<span class="status status-active">Active</span>' : '<span class="status status-disabled">Disabled</span>' ?></td>
              <td class="nowrap"><?= e($u['last_login_at'] ?? '—') ?></td>
              <td class="actions"><a href="<?= e(url('admin/access/users/' . $u['id'])) ?>" class="btn btn-sm">Manage</a></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="text-muted" style="padding:8px 16px">Admin logins pass the admin gate via <code>is_admin</code>; what they can touch afterwards comes from their role's permissions. Customer logins are managed through Accounts, not here.</p>
</div>
