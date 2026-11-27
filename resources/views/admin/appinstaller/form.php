<?php $app = $app ?? null; $old = $old ?? []; $categories = $categories ?? [];

function aiVal(?array $app, array $old, string $key, $default = '') {
    if ($app && array_key_exists($key, $app)) return (string) $app[$key];
    if (array_key_exists($key, $old)) return (string) $old[$key];
    return (string) $default;
}
$m = $old; // manifest fields come through `old` on validation; otherwise derived from app
$manifest = is_array($app) ? (json_decode((string)($app['manifest'] ?? '{}'), true) ?: []) : [];
/** Nested manifest lookup with array-safe stringify (never "Array", never fatal). */
function mn(array $manifest, string $path, $default = '') {
    $cur = $manifest;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) return (string) $default;
        $cur = $cur[$seg];
    }
    if (is_array($cur)) {
        $vals = array_values($cur);
        $flat = true;
        foreach ($vals as $v) { if (is_array($v)) { $flat = false; break; } }
        if ($flat) return implode(', ', array_map('strval', $vals));
        return (string) json_encode($cur);
    }
    if (is_bool($cur)) return $cur ? '1' : '0';
    return (string) $cur;
}
/** Remainder of the manifest not covered by the flat fields below (pip/npm/
 *  app/file/update_check sections). Shown in Advanced JSON so edits round-trip
 *  instead of being wiped on save. */
$flatSections = ['type','requirements','database','download','config','admin'];
$remainder = array_diff_key($manifest, array_flip($flatSections));
$manifestJsonDefault = $remainder !== [] ? json_encode($remainder, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
$dlFormat = mn($manifest, 'download.format', 'zip');
$cfgTemplate = mn($manifest, 'config.template', 'wp');
// Effective download source for display (url or version-templated block).
$dlSource = mn($manifest, 'download.url', '');
if ($dlSource === '' && is_array($manifest['download']['github'] ?? null)) {
    $g = $manifest['download']['github'];
    $dlSource = 'github:' . ($g['repo'] ?? '?') . ' :: ' . ($g['asset'] ?? '?');
}
if ($dlSource === '' && is_array($manifest['download']['codeberg'] ?? null)) {
    $g = $manifest['download']['codeberg'];
    $dlSource = 'codeberg:' . ($g['repo'] ?? '?') . ' :: ' . ($g['asset'] ?? '?');
}
?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title"><?= $app ? 'Edit application' : 'Add application' ?></h2></div>
  <form method="post" action="<?= e($app ? url('admin/appinstaller/applications/'.$app['id']) : url('admin/appinstaller/applications')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>

    <div class="field">
      <label class="field-label">Name <span class="text-muted">*</span></label>
      <input class="input" type="text" name="name" value="<?= e(aiVal($app, $old, 'name')) ?>" required style="width:100%">
    </div>
    <div class="field">
      <label class="field-label">Slug <span class="text-muted">*</span></label>
      <input class="input" type="text" name="slug" value="<?= e(aiVal($app, $old, 'slug')) ?>" required pattern="[a-z0-9-]+" style="width:100%">
    </div>
    <div class="field">
      <label class="field-label">Category</label>
      <select class="input" name="category" style="width:100%">
        <?php foreach ($categories as $c): ?>
          <option value="<?= e($c['slug']) ?>" <?= aiVal($app, $old, 'category') === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
        <option value="CMS" <?= aiVal($app, $old, 'category') === 'CMS' ? 'selected' : '' ?>>CMS</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label">Version</label>
      <input class="input" type="text" name="version" value="<?= e(aiVal($app, $old, 'version', 'latest')) ?>" style="width:100%">
    </div>
    <div class="field">
      <label class="field-label">Type</label>
      <select class="input" name="app_type" style="width:100%">
        <?php foreach (['php-mysql' => 'PHP + MySQL', 'php' => 'PHP (no database)', 'python-pip' => 'Python (pip package)', 'node-npm' => 'Node.js (npm package)', 'node-archive' => 'Node.js (archive)', 'binary' => 'Single binary (Go/Rust)'] as $tv => $tlabel): ?>
          <option value="<?= e($tv) ?>" <?= ($manifest['type'] ?? 'php-mysql') === $tv ? 'selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="field-help">Python/Node.js/binary apps provision a supervised process + reverse-proxy mount; their extra manifest keys (pip/npm/app sections) go in Advanced JSON below. Download URLs may use {version}/{tag} placeholders resolved from the Version field.</p>
    </div>
    <div class="field">
      <label class="field-label">Description</label>
      <textarea class="input" name="description" rows="3" style="width:100%"><?= e(aiVal($app, $old, 'description')) ?></textarea>
    </div>
    <div class="field">
      <label class="field-label">Website</label>
      <input class="input" type="text" name="website" value="<?= e(aiVal($app, $old, 'website')) ?>" style="width:100%">
    </div>

    <fieldset class="fieldset"><legend class="fieldset-legend">Download</legend>
      <?php if ($dlSource !== '' && mn($manifest, 'download.url', '') === ''): ?>
        <p class="field-help">Version-templated source: <code><?= e($dlSource) ?></code> (managed via Advanced JSON below; entering a URL overrides it).</p>
      <?php endif; ?>
      <?php if (mn($manifest, 'file.name', '') !== ''): ?>
        <p class="field-help">Single-file app, installed as <code><?= e(mn($manifest, 'file.name', '')) ?></code>.</p>
      <?php endif; ?>
      <div class="field">
        <label class="field-label">Download URL (HTTPS)</label>
        <input class="input" type="text" name="download_url" value="<?= e(array_key_exists('download_url', $old) ? (string)$old['download_url'] : mn($manifest, 'download.url', '')) ?>" placeholder="https://…/app.tar.gz" style="width:100%">
      </div>
      <div class="field">
        <label class="field-label">SHA-256 (optional)</label>
        <input class="input" type="text" name="sha256" value="<?= e(array_key_exists('sha256', $old) ? (string)$old['sha256'] : mn($manifest, 'download.sha256', '')) ?>" style="width:100%">
      </div>
      <div class="field">
        <label class="field-label">Archive format</label>
        <select class="input" name="archive_format" style="width:100%">
          <option value="zip" <?= $dlFormat === 'zip' ? 'selected' : '' ?>>zip</option>
          <option value="tar" <?= $dlFormat === 'tar' ? 'selected' : '' ?>>tar.gz</option>
          <option value="file" <?= $dlFormat === 'file' ? 'selected' : '' ?>>single file (no extract)</option>
        </select>
      </div>
    </fieldset>

    <fieldset class="fieldset"><legend class="fieldset-legend">Installation</legend>
      <div class="field">
        <label class="field-label">PHP minimum</label>
        <input class="input" type="text" name="php_min" value="<?= e(array_key_exists('php_min', $old) ? (string)$old['php_min'] : mn($manifest, 'requirements.php_min', '7.4')) ?>" style="width:100%">
      </div>
      <div class="field">
        <label class="field-label">Required PHP extensions (comma-separated)</label>
        <input class="input" type="text" name="extensions" value="<?= e(array_key_exists('extensions', $old) ? (string)$old['extensions'] : mn($manifest, 'requirements.extensions', '')) ?>" placeholder="mysqli, curl, gd" style="width:100%">
      </div>
      <div class="field">
        <label class="field-label">Config file</label>
        <input class="input" type="text" name="config_file" value="<?= e(array_key_exists('config_file', $old) ? (string)$old['config_file'] : mn($manifest, 'config.file', 'wp-config.php')) ?>" style="width:100%">
      </div>
      <div class="field">
        <label class="field-label">Config template</label>
        <select class="input" name="config_template" style="width:100%">
          <option value="wp" <?= $cfgTemplate === 'wp' ? 'selected' : '' ?>>WordPress (wp-config.php)</option>
          <?php if ($cfgTemplate !== '' && $cfgTemplate !== 'wp'): ?>
            <option value="<?= e($cfgTemplate) ?>" selected><?= e($cfgTemplate) ?> (existing — keep to preserve)</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label">DB table prefix</label>
        <input class="input" type="text" name="db_prefix" value="<?= e(array_key_exists('db_prefix', $old) ? (string)$old['db_prefix'] : mn($manifest, 'database.prefix', 'wp_')) ?>" style="width:100%">
      </div>
      <div class="field">
        <label class="field-label">Admin path</label>
        <input class="input" type="text" name="admin_path" value="<?= e(array_key_exists('admin_path', $old) ? (string)$old['admin_path'] : mn($manifest, 'admin.path', 'wp-admin/')) ?>" style="width:100%">
      </div>
      <div class="field">
        <label class="check"><input type="checkbox" name="db_required" value="1" <?= ($manifest['database']['required'] ?? true) ? 'checked' : '' ?>> Requires database</label>
      </div>
      <div class="field">
        <label class="check"><input type="checkbox" name="strip_root" value="1" <?= ($manifest['download']['strip_root'] ?? true) ? 'checked' : '' ?>> Strip single top-level folder</label>
      </div>
    </fieldset>

    <div class="field">
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= aiVal($app, $old, 'is_active', '1') === '1' ? 'checked' : '' ?>> Active</label>
      <label class="check" style="margin-left:16px"><input type="checkbox" name="is_featured" value="1" <?= aiVal($app, $old, 'is_featured', '0') === '1' ? 'checked' : '' ?>> Featured</label>
    </div>

    <div class="field">
      <label class="field-label">Advanced manifest JSON <span class="text-muted">(merged over the fields above; e.g. pip/npm/app/update_check sections)</span></label>
      <textarea class="input" name="manifest_json" rows="6" spellcheck="false" style="width:100%;font-family:monospace"><?= e((string)($old['manifest_json'] ?? $manifestJsonDefault)) ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save</button>
      <a href="<?= e(url('admin/appinstaller/applications')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
