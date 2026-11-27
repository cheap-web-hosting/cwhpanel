<?php $website = $website ?? []; $phpVersions = $phpVersions ?? []; $options = $options ?? []; $settings = $settings ?? []; $phpDirs = $phpDirs ?? []; $siteVer = (string) ($website['php_version'] ?? '8.4'); ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">PHP Settings — <?= e($website['label'] ?? '') ?></h2></div>
  <form method="post" action="<?= e(url('panel/websites/'.$website['id'].'/php')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>

    <div class="field">
      <label class="field-label" for="php_version">PHP version</label>
      <select class="input" id="php_version" name="php_version" style="width:100%">
        <?php foreach ($phpVersions as $id => $label): $ver = substr($id,0,1).'.'.substr($id,1,1); ?>
          <option value="<?= e($ver) ?>" <?= (($website['php_version'] ?? '8.4') === $ver ? 'selected' : '') ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="field-help">Additional versions can be installed by an administrator under Server → PHP Versions.</p>
    </div>

    <h3 class="section-title">PHP options</h3>
    <?php foreach ($options as $key => [$label, $type, $default]): ?>
      <div class="field">
        <label class="field-label" for="<?= e($key) ?>"><?= e($label) ?></label>
        <?php if ($type === 'onoff'): ?>
          <select class="input" id="<?= e($key) ?>" name="<?= e($key) ?>" style="max-width:120px">
            <option value="1" <?= ($settings[$key] ?? $default) === '1' ? 'selected' : '' ?>>On</option>
            <option value="0" <?= ($settings[$key] ?? $default) === '0' ? 'selected' : '' ?>>Off</option>
          </select>
        <?php else: ?>
          <input class="input" type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($settings[$key] ?? $default) ?>" style="max-width:160px">
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save PHP settings</button>
      <a href="<?= e(url('panel/websites/'.$website['id'])) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-header"><h2 class="panel-title">Per-directory PHP <span class="text-muted">(site runs <?= e($siteVer) ?>)</span></h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Directory</th><th>Version</th><th>Options</th><th style="width:110px"></th></tr></thead>
    <tbody>
      <?php if (empty($phpDirs)): ?><?= empty_state(4, 'Every directory follows the site version and options.') ?>
      <?php else: foreach ($phpDirs as $d): $dset = json_decode((string) ($d['settings'] ?? ''), true) ?: []; ?>
        <tr>
          <td><code>/<?= e($d['dir']) ?>/</code></td>
          <td><?= e(($d['php_version'] ?? '') !== '' ? ($phpVersions[$d['php_version']] ?? $d['php_version']) : 'Inherit (' . $siteVer . ')') ?></td>
          <td class="ellipsis"><?php if (empty($dset)): ?><span class="text-muted">inherit</span><?php else: ?><?= e(implode(', ', array_map(fn($k, $v) => $k . '=' . $v, array_keys($dset), $dset))) ?><?php endif; ?></td>
          <td class="actions"><form method="post" action="<?= e(url('panel/websites/' . $website['id'] . '/php/dirs/delete')) ?>" class="inline-form" data-confirm="Remove the override for /<?= e($d['dir']) ?>/?"><?= csrf_field() ?><input type="hidden" name="dir_id" value="<?= (int) $d['id'] ?>"><button type="submit" class="btn btn-sm">Remove</button></form></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
  <form method="post" action="<?= e(url('panel/websites/' . $website['id'] . '/php/dirs')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="phpdir">Directory <span class="text-muted">(relative to the document root, e.g. blog)</span></label>
      <input class="input" type="text" id="phpdir" name="dir" required maxlength="255" placeholder="blog" style="max-width:280px">
    </div>
    <div class="field">
      <label class="field-label" for="dir_php_version">PHP version</label>
      <select class="input" id="dir_php_version" name="dir_php_version" style="max-width:280px">
        <option value="inherit">Inherit (<?= e($siteVer) ?>)</option>
        <?php foreach ($phpVersions as $id => $label): $ver = substr($id,0,1).'.'.substr($id,1,1); ?>
          <option value="<?= e($id) ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="text-muted">Options below are locked per directory (blank = inherit the site value):</p>
    <?php foreach ($options as $key => [$label, $type, $default]): ?>
      <div class="field">
        <label class="field-label" for="dir_<?= e($key) ?>"><?= e($label) ?></label>
        <?php if ($type === 'onoff'): ?>
          <select class="input" id="dir_<?= e($key) ?>" name="dir_<?= e($key) ?>" style="max-width:120px">
            <option value="">Inherit</option>
            <option value="1">On</option>
            <option value="0">Off</option>
          </select>
        <?php else: ?>
          <input class="input" type="text" id="dir_<?= e($key) ?>" name="dir_<?= e($key) ?>" value="" placeholder="inherit" style="max-width:160px">
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save directory override</button></div>
  </form>
</div>
