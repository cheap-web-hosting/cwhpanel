<?php $certs = $certs ?? []; $search = $search ?? ''; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Certificates</h2>
    <a href="<?= e(url('admin/ssl/create')) ?>" class="btn btn-primary">Issue Certificate</a>
  </div>
  <form method="get" action="<?= e(url('admin/ssl')) ?>" class="filter-bar">
    <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search domain…">
    <button type="submit" class="btn">Search</button>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Domain</th><th>Account</th><th>Type</th><th>Issued</th><th>Expires</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($certs)): ?>
          <?= empty_state(7, 'No certificates yet. <a href="' . e(url('admin/ssl/create')) . '">Issue one</a>.') ?>
        <?php else: ?>
          <?php foreach ($certs as $c): ?>
            <tr>
              <td><strong><?= e($c['domain_name']) ?></strong></td>
              <td><?= e($c['username'] ?? '—') ?></td>
              <td><?= e($c['type']) ?></td>
              <td class="nowrap"><?= e($c['issued_at'] ?? '—') ?></td>
              <td class="nowrap"><?= e($c['expires_at'] ?? '—') ?></td>
              <td><?= $c['status'] === 'active' ? '<span class="status status-active">Active</span>' : '<span class="status status-disabled">' . e($c['status']) . '</span>' ?></td>
              <td class="actions">
                <a href="<?= e(url('admin/ssl/' . $c['id'])) ?>" class="btn btn-sm">View</a>
                <form method="post" action="<?= e(url('admin/ssl/' . $c['id'] . '/renew')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm" data-confirm="Renew certificate?">Renew</button>
                </form>
                <form method="post" action="<?= e(url('admin/ssl/' . $c['id'] . '/delete')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete certificate for <?= e($c['domain_name']) ?>?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>