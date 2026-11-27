<?php $apps = $apps ?? []; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Applications</h2><div class="actions"><span class="text-muted">Upstream versions refresh weekly (cron); installs always use the catalog version.</span> <a href="<?= e(url('admin/appinstaller/applications/create')) ?>" class="btn btn-primary">Add Application</a></div></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Slug</th><th>Category</th><th>Version</th><th>Upstream</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($apps)): ?><?= empty_state(7, 'No applications. Add WordPress or another app.') ?>
      <?php else: foreach ($apps as $a): ?>
        <tr>
          <td><strong><?= e($a['name']) ?></strong><?= $a['is_featured'] ? ' ★' : '' ?></td>
          <td><code><?= e($a['slug']) ?></code></td>
          <td><?= e($a['category']) ?></td>
          <td><?= e($a['display_version'] ?? $a['version']) ?><?php if (!empty($a['update_available'])): ?> <span class="status status-warn">update</span><?php endif; ?></td>
          <td><?= e($a['latest_version'] ?? '—') ?><?= !empty($a['latest_checked_at']) ? '<br><small class="text-muted">checked ' . e(date('M j', (int) $a['latest_checked_at'])) . '</small>' : '' ?></td>
          <td><?= $a['is_active'] ? '<span class="status status-active">Active</span>' : '<span class="status status-disabled">Disabled</span>' ?></td>
          <td class="actions">
            <a href="<?= e(url('admin/appinstaller/applications/'.$a['id'].'/edit')) ?>" class="btn btn-sm">Edit</a>
            <form method="post" action="<?= e(url('admin/appinstaller/applications/'.$a['id'].'/refresh')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm">Refresh</button></form>
            <?php if (!empty($a['update_available'])): ?><form method="post" action="<?= e(url('admin/appinstaller/applications/'.$a['id'].'/apply-update')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-primary">Apply update</button></form><?php endif; ?>
            <form method="post" action="<?= e(url('admin/appinstaller/applications/'.$a['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete application?">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>