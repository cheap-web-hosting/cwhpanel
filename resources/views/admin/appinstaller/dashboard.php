<?php $apps=$apps??0; $enabled=$enabled??0; $installs=$installs??0; $active=$active??0; $failed=$failed??0; ?>
<div class="cards-grid">
  <div class="card"><div class="card-label">Applications</div><div class="card-value"><?= e($apps) ?></div></div>
  <div class="card"><div class="card-label">Enabled</div><div class="card-value"><?= e($enabled) ?></div></div>
  <div class="card"><div class="card-label">Installations</div><div class="card-value"><?= e($installs) ?></div></div>
  <div class="card"><div class="card-label">Active</div><div class="card-value"><?= e($active) ?></div></div>
  <div class="card"><div class="card-label">Failed</div><div class="card-value"><?= e($failed) ?></div></div>
</div>
<div class="panel" style="padding:16px;margin-top:16px">
  <p>Manage the application catalog, categories, and monitor installations from the sidebar links.</p>
  <a href="<?= e(url('admin/appinstaller/applications')) ?>" class="btn btn-primary">Manage applications</a>
  <a href="<?= e(url('admin/appinstaller/categories')) ?>" class="btn">Categories</a>
  <a href="<?= e(url('admin/appinstaller/installations')) ?>" class="btn">Installations</a>
</div>