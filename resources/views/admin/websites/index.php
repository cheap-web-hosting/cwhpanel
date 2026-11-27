<?php $websites = $websites ?? []; $search = $search ?? ''; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">All websites</h2>
    <a href="<?= e(url('admin/websites/create')) ?>" class="btn btn-primary">Add website</a>
  </div>
  <form method="get" action="<?= e(url('admin/websites')) ?>" class="filter-bar">
    <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search label or domain…">
    <button type="submit" class="btn">Search</button>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Label</th><th>Domain</th><th>Account</th><th>PHP</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($websites)): ?>
          <?= empty_state(6, 'No websites yet. <a href="' . e(url('admin/websites/create')) . '">Create one</a>.') ?>
        <?php else: ?>
          <?php foreach ($websites as $w): ?>
            <tr>
              <td><strong><?= e($w['label']) ?></strong></td>
              <td><?= e($w['domain_name'] ?? '—') ?></td>
              <td><?= e($w['username'] ?? '—') ?></td>
              <td><?= e($w['php_version'] ?? '—') ?></td>
              <td><?= $w['status'] === 'active' ? '<span class="status status-active">Active</span>' : '<span class="status status-disabled">' . e($w['status']) . '</span>' ?></td>
              <td class="actions">
                <a href="<?= e(url('admin/websites/' . $w['id'])) ?>" class="btn btn-sm">View</a>
                <a href="<?= e(url('admin/websites/' . $w['id'] . '/edit')) ?>" class="btn btn-sm">Edit</a>
                <a href="<?= e(url('admin/websites/' . $w['id'] . '/nginx')) ?>" class="btn btn-sm">Nginx</a>
                <form method="post" action="<?= e(url('admin/websites/' . $w['id'] . '/delete')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete website <?= e($w['label']) ?>?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>