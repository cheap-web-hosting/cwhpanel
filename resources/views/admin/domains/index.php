<?php $domains = $domains ?? []; $search = $search ?? ''; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">All domains</h2>
    <a href="<?= e(url('admin/domains/create')) ?>" class="btn btn-primary">Add Domain</a>
  </div>
  <form method="get" action="<?= e(url('admin/domains')) ?>" class="filter-bar">
    <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search domain…">
    <button type="submit" class="btn">Search</button>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Domain</th><th>Account</th><th>Type</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($domains)): ?>
          <?= empty_state(5, 'No domains found. <a href="' . e(url('admin/domains/create')) . '">Add one</a>.') ?>
        <?php else: ?>
          <?php foreach ($domains as $d): ?>
            <tr>
              <td><strong><?= e($d['name']) ?></strong></td>
              <td><?= e($d['username'] ?? '—') ?></td>
              <td><?= e($d['type']) ?></td>
              <td><?= $d['status'] === 'active' ? '<span class="status status-active">Active</span>' : '<span class="status status-disabled">' . e($d['status']) . '</span>' ?></td>
              <td class="actions">
                <a href="<?= e(url('admin/domains/' . $d['id'])) ?>" class="btn btn-sm">View</a>
                <a href="<?= e(url('admin/domains/' . $d['id'] . '/edit')) ?>" class="btn btn-sm">Edit</a>
                <form method="post" action="<?= e(url('admin/domains/' . $d['id'] . '/delete')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete domain <?= e($d['name']) ?>?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>