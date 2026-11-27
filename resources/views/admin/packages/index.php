<?php $packages = $packages ?? []; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">All packages</h2>
    <a href="<?= e(url('admin/packages/create')) ?>" class="btn btn-primary">Add Package</a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Disk</th>
          <th>Bandwidth</th>
          <th>Domains</th>
          <th>Databases</th>
          <th>Email</th>
          <th>CPU</th>
          <th>Accounts</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($packages)): ?>
          <?= empty_state(10, 'No packages yet. <a href="' . e(url('admin/packages/create')) . '">Create one</a>.') ?>
        <?php else: ?>
          <?php foreach ($packages as $p): ?>
            <tr>
              <td>
                <strong><?= e($p['name']) ?></strong>
                <?php if ($p['is_system']): ?><span class="badge">System</span><?php endif; ?>
                <div><small class="text-muted"><?= e($p['slug']) ?></small></div>
              </td>
              <td><?= e($p['disk_quota_mb']) ?> MB</td>
              <td><?= e($p['bandwidth_mb']) ?> MB</td>
              <td><?= $p['max_domains'] === null ? 'Unlimited' : e((string) $p['max_domains']) ?></td>
              <td><?= $p['max_databases'] === null ? 'Unlimited' : e((string) $p['max_databases']) ?></td>
              <td><?= $p['max_email_accounts'] === null ? 'Unlimited' : e((string) $p['max_email_accounts']) ?></td>
              <td><?= e($p['max_cpu_percent']) ?>%</td>
              <td><?= e($p['account_count'] ?? 0) ?></td>
              <td>
                <?php if ($p['is_active']): ?>
                  <span class="status status-active">Active</span>
                <?php else: ?>
                  <span class="status status-disabled">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="actions">
                <a href="<?= e(url('admin/packages/' . $p['id'])) ?>" class="btn btn-sm">View</a>
                <a href="<?= e(url('admin/packages/' . $p['id'] . '/edit')) ?>" class="btn btn-sm">Edit</a>
                <form method="post" action="<?= e(url('admin/packages/' . $p['id'] . '/duplicate')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm">Duplicate</button>
                </form>
                <?php if (!$p['is_system']): ?>
                  <form method="post" action="<?= e(url('admin/packages/' . $p['id'] . '/delete')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete package <?= e($p['name']) ?>?">Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>