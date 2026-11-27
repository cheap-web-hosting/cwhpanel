<?php $rows = $rows ?? []; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Installations</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>App</th><th>Account</th><th>Domain</th><th>Status</th><th>When</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?><?= empty_state(6, 'No installations yet.') ?>
      <?php else: foreach ($rows as $r): ?>
        <tr><td><?= e($r['app_name']) ?></td><td><?= e($r['username'] ?? '—') ?></td><td><?= e($r['domain']) ?></td>
        <td><?= $r['status'] === 'completed' ? '<span class="status status-active">Completed</span>' : (($r['status'] === 'failed' ? '<span class="status status-failed">Failed</span>' : '<span class="status status-disabled">'.e($r['status']).'</span>')) ?></td>
        <td><?= e($r['created_at']) ?></td>
        <td class="actions"><a href="<?= e(url('admin/appinstaller/installations/'.$r['id'])) ?>" class="btn btn-sm">View</a></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>