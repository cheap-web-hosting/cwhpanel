<?php $rows = $rows ?? []; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Installed applications</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Application</th><th>Version</th><th>Domain</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <?= empty_state(5, 'You haven\'t installed any applications yet. <a href="' . e(url('panel/appinstaller')) . '">Browse</a>.') ?>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['app_name']) ?></strong></td>
          <td><?= e($r['version']) ?></td>
          <td><?= e($r['domain']) ?><?= $r['directory'] ? '/' . e($r['directory']) : '' ?></td>
          <td><?= $r['status'] === 'completed' ? '<span class="status status-active">Active</span>' : (($r['status'] === 'failed' ? '<span class="status status-failed">Failed</span>' : '<span class="status status-disabled">'.e($r['status']).'</span>')) ?></td>
          <td class="actions"><a href="<?= e(url('panel/appinstaller/installed/'.$r['id'])) ?>" class="btn btn-sm">Manage</a></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>