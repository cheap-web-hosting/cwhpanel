<?php $app = $app ?? []; $manifest = $manifest ?? []; $requirements = $requirements ?? []; $domains = $domains ?? []; $old = $old ?? []; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Install <?= e($app['name']) ?></h2></div>

  <div class="panel" style="padding:16px;margin:16px">
    <h3 class="section-title">Requirements</h3>
    <ul style="list-style:none;padding:0;margin:0">
      <?php foreach ($requirements as [$ok, $label, $detail]): ?>
        <li style="padding:2px 0"><?= $ok ? '<span style="color:var(--primary)">✓</span>' : '<span style="color:#d33">✗</span>' ?> <?= e($label) ?> <span class="text-muted">(<?= e($detail) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <form method="post" action="<?= e(url('panel/appinstaller/install/'.$app['id'])) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>

    <div class="field">
      <label class="field-label" for="domain_id">Domain <span class="text-muted">*</span></label>
      <select class="input" id="domain_id" name="domain_id" required style="width:100%">
        <option value="">Select domain…</option>
        <?php foreach ($domains as $d): ?>
          <option value="<?= e($d['id']) ?>"><?= e($d['name']) ?> (<?= e($d['document_root']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if (!empty($manifest['root_only'])): ?>
    <div class="field">
      <p class="text-muted" style="margin:0">This application must be installed at a website root (its frontend does not support subdirectories) — leave the directory empty and pick a domain whose document root is empty.</p>
      <input type="hidden" name="directory" value="">
    </div>
    <?php else: ?>
    <div class="field">
      <label class="field-label" for="directory">Install directory <span class="text-muted">(blank = domain root)</span></label>
      <input class="input" type="text" id="directory" name="directory" value="<?= e($old['directory'] ?? '') ?>" placeholder="e.g. shop" style="width:100%">
    </div>
    <?php endif; ?>

    <div class="field">
      <label class="field-label" for="title">Site title</label>
      <input class="input" type="text" id="title" name="title" value="<?= e($old['title'] ?? '') ?>" placeholder="My Site" style="width:100%">
    </div>

    <div class="field">
      <label class="field-label" for="admin_user">Admin username</label>
      <input class="input" type="text" id="admin_user" name="admin_user" value="<?= e($old['admin_user'] ?? 'admin') ?>" style="width:100%">
    </div>

    <div class="field">
      <label class="field-label" for="admin_password">Admin password <span class="text-muted">(app login — min 8 chars)</span></label>
      <input class="input" type="password" autocomplete="new-password" id="admin_password" name="admin_password" style="width:100%">
    </div>

    <div class="field">
      <label class="field-label" for="admin_email">Admin email <span class="text-muted">(app account + notifications)</span></label>
      <input class="input" type="email" id="admin_email" name="admin_email" value="<?= e($old['admin_email'] ?? '') ?>" placeholder="you@example.com" style="width:100%">
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Install</button>
      <a href="<?= e(url('panel/appinstaller/app/'.$app['id'])) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>