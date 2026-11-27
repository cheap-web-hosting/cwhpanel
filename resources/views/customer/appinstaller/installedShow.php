<?php $inst = $inst ?? []; $logs = $logs ?? []; ?>
<div class="cards-grid">
  <div class="card"><div class="card-label">Application</div><div class="card-value"><?= e($inst['app_name']) ?></div></div>
  <div class="card"><div class="card-label">Version</div><div class="card-value"><?= e($inst['version']) ?></div></div>
  <div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($inst['domain']) ?></div></div>
  <div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($inst['status']) ?></div></div>
</div>

<?php if ($inst['status'] === 'completed'): ?>
<div class="cards-grid">
  <?php if ($inst['site_url']): ?><div class="card"><div class="card-label">Site URL</div><div class="card-value"><a href="<?= e($inst['site_url']) ?>" target="_blank" rel="noopener"><?= e($inst['site_url']) ?></a></div></div><?php endif; ?>
  <?php if ($inst['admin_url']): ?><div class="card"><div class="card-label">Admin URL</div><div class="card-value"><a href="<?= e($inst['site_url']) ?>/wp-admin/" target="_blank" rel="noopener"><?= e($inst['site_url']) ?>/wp-admin/</a></div></div><?php endif; ?>
  <?php if ($inst['admin_user']): ?><div class="card"><div class="card-label">Admin user</div><div class="card-value"><?= e($inst['admin_user']) ?></div></div><?php endif; ?>
</div>
<?php endif; ?>

<h2 class="section-title">Installation log</h2>
<div class="panel" style="padding:16px">
  <?php if (empty($logs)): ?><p class="empty">No log entries.</p>
  <?php else: ?><pre style="white-space:pre-wrap;margin:0;font-size:12px"><?php foreach ($logs as $l): ?><?= e($l['created_at']) ?> [<?= e($l['level']) ?>] <?= e($l['message']) ?><?= "\n" ?><?php endforeach; ?></pre><?php endif; ?>
</div>

<div class="panel" style="padding:16px"><div class="form-actions">
  <?php $st = ($inst['status'] ?? ''); ?>
  <?php if ($st === 'completed'): ?>
  <form method="post" action="<?= e(url('panel/appinstaller/installed/'.$inst['id'].'/update')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn">Update</button></form>
  <?php endif; ?>
  <?php if ($st === 'completed' || $st === 'failed'): ?>
  <form method="post" action="<?= e(url('panel/appinstaller/installed/'.$inst['id'].'/uninstall')) ?>" class="inline-form" style="align-items:center">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="remove_files" value="1" checked> Remove files</label>
    <label class="check"><input type="checkbox" name="remove_db" value="1" checked> Remove database</label>
    <button type="submit" class="btn btn-danger" data-confirm="Uninstall this application? This cannot be undone.">Uninstall</button>
  </form>
  <?php else: ?>
  <p class="text-muted" style="margin:0">This installation is <?= e($inst['status'] ?? 'not active') ?> — actions are unavailable. Re-install from the catalog for a fresh copy.</p>
  <?php endif; ?>
</div></div>