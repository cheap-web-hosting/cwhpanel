<?php
$accounts = $accounts ?? [];
$search = $search ?? '';
$status = $status ?? '';
?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">All accounts</h2>
    <a href="<?= e(url('admin/accounts/create')) ?>" class="btn btn-primary">Add Account</a>
  </div>

  <form method="get" action="<?= e(url('admin/accounts')) ?>" class="filter-bar">
    <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search username, domain or email…">
    <select class="input" name="status">
      <option value="">All statuses</option>
      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
      <option value="terminated" <?= $status === 'terminated' ? 'selected' : '' ?>>Terminated</option>
    </select>
    <button type="submit" class="btn">Filter</button>
  </form>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Username</th>
          <th>Domain</th>
          <th>Package</th>
          <th>Disk</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($accounts)): ?>
          <?= empty_state(6, 'No accounts found. <a href="' . e(url('admin/accounts/create')) . '">Create one</a>.') ?>
        <?php else: ?>
          <?php foreach ($accounts as $a): ?>
            <tr>
              <td><strong><?= e($a['username']) ?></strong></td>
              <td><?= e($a['domain']) ?></td>
              <td><?= e($a['package_name'] ?? '—') ?></td>
              <td class="num"><?= e($a['disk_quota_mb'] ?? 0) ?> MB</td>
              <td>
                <?php if ($a['status'] === 'active'): ?><span class="status status-active">Active</span>
                <?php elseif ($a['status'] === 'suspended'): ?><span class="status status-disabled">Suspended</span>
                <?php else: ?><span class="badge badge-warning">Terminated</span><?php endif; ?>
              </td>
              <td class="actions">
                <a href="<?= e(url('admin/accounts/' . $a['id'])) ?>" class="btn btn-sm">Manage</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>