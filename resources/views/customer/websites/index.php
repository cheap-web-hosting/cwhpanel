<?php $websites = $websites ?? []; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">My websites</h2>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Label</th><th>Domain</th><th>PHP</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($websites)): ?>
          <?= empty_state(5, 'You have no websites yet. New websites are added by your administrator.') ?>
        <?php else: ?>
          <?php foreach ($websites as $w): ?>
            <tr>
              <td><strong><?= e($w['label']) ?></strong></td>
              <td><?= e($w['domain_name'] ?? '—') ?></td>
              <td><?= e($w['php_version'] ?? '—') ?></td>
              <td><?= $w['status'] === 'active' ? '<span class="status status-active">Active</span>' : '<span class="status status-disabled">' . e($w['status']) . '</span>' ?></td>
              <td class="actions">
                <a href="<?= e(url('panel/websites/' . $w['id'])) ?>" class="btn btn-sm">Manage</a>
                <a href="<?= e(url('panel/websites/' . $w['id'] . '/php')) ?>" class="btn btn-sm" title="PHP settings (<?= e($w['php_version'] ?? '') ?>)"><?= icon('settings', 14) ?> PHP</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>