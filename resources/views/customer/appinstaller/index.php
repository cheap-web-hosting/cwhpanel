<?php $apps = $apps ?? []; $categories = $categories ?? []; $q = $q ?? ''; $activeCategory = $activeCategory ?? 'all'; $activeTech = $activeTech ?? 'all'; $techOptions = $techOptions ?? []; ?>
<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Available applications</h2>
    <form method="get" action="<?= e(url('panel/appinstaller')) ?>" class="filter-bar" style="border-bottom:0;padding:0">
      <input class="input" type="text" name="q" value="<?= e($q) ?>" placeholder="Search applications…" style="min-width:200px">
      <select class="input" name="category" onchange="this.form.submit()">
        <option value="all" <?= $activeCategory === 'all' ? 'selected' : '' ?>>All categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= e($c['slug']) ?>" <?= $activeCategory === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="input" name="tech" onchange="this.form.submit()">
        <option value="all" <?= $activeTech === 'all' ? 'selected' : '' ?>>All technologies</option>
        <?php foreach ($techOptions as $tv => $tlabel): ?>
          <option value="<?= e($tv) ?>" <?= $activeTech === $tv ? 'selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm">Search</button>
    </form>
  </div>

  <?php if (empty($apps)): ?>
    <p class="empty" style="padding:24px">No applications found.</p>
  <?php else: ?>
    <div class="cards-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));padding:16px">
      <?php foreach ($apps as $a): ?>
        <a class="card" href="<?= e(url('panel/appinstaller/app/'.$a['id'])) ?>" style="text-decoration:none;color:inherit">
          <div class="card-label"><?= e($a['name']) ?></div>
          <div class="card-value" style="font-size:13px;font-weight:400"><?= e($a['display_version'] ?? $a['version']) ?><?= $a['is_featured'] ? ' ★' : '' ?> <small class="text-muted"><?= e($techOptions[$a['tech'] ?? ''] ?? '') ?></small></div>
          <p class="text-muted" style="margin:6px 0 0;font-size:12px"><?= e(mb_substr((string)$a['description'], 0, 90)) ?></p>
          <div style="margin-top:8px"><span class="btn btn-primary btn-sm">Install</span></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>