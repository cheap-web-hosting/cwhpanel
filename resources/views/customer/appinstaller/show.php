<?php $app = $app ?? []; $manifest = $manifest ?? []; ?>
<div class="cards-grid">
  <div class="card"><div class="card-label">Application</div><div class="card-value"><?= e($app['name']) ?></div></div>
  <div class="card"><div class="card-label">Version</div><div class="card-value"><?= e($app['version']) ?></div></div>
  <div class="card"><div class="card-label">Category</div><div class="card-value"><?= e($app['category']) ?></div></div>
  <div class="card"><div class="card-label">License</div><div class="card-value"><?= e($app['license'] ?? '—') ?></div></div>
</div>

<div class="panel" style="padding:16px">
  <?php if ($app['description']): ?><p><?= e($app['description']) ?></p><?php endif; ?>
  <?php if (!empty($manifest['post_install'])): ?><p><strong>After install:</strong> <?= e($manifest['post_install']) ?></p><?php endif; ?>
  <?php if (($manifest['requirements']['php_min'] ?? null)): ?>
    <p class="text-muted">Requires PHP >= <?= e($manifest['requirements']['php_min']) ?><?= !empty($manifest['requirements']['extensions']) ? ' and extensions: ' . e(implode(', ', $manifest['requirements']['extensions'])) : '' ?></p>
  <?php endif; ?>
  <?php if ($app['website']): ?><p><a href="<?= e($app['website']) ?>" target="_blank" rel="noopener">Official website</a></p><?php endif; ?>
  <a href="<?= e(url('panel/appinstaller/install/'.$app['id'])) ?>" class="btn btn-primary">Install application</a>
  <a href="<?= e(url('panel/appinstaller')) ?>" class="btn btn-ghost">Back</a>
</div>