<?php
// Shared file-browser UI for admin + customer file managers.
// Expects: $account, $path, $entries, $q, $lk (relpath => browse URL),
// $actBase (POST action prefix), $editPrefix (file-edit URL prefix ending
// with ?path=), $dlPrefix (download URL prefix ending with ?path=).
$account = $account ?? '';
$path = $path ?? '';
$entries = $entries ?? [];
$q = $q ?? '';
$lk = $lk ?? fn ($p) => '#';
$actBase = $actBase ?? '#';
$editPrefix = $editPrefix ?? '#?path=';
$dlPrefix = $dlPrefix ?? '#?path=';
$extraQuery = $extraQuery ?? [];
$headerExtra = $headerExtra ?? '';

$crumbs = [];
if ($path !== '' && $path !== '.') {
    $acc = '';
    foreach (explode('/', trim($path, '/')) as $part) {
        $acc = trim($acc . '/' . $part, '/');
        $crumbs[] = ['label' => $part, 'url' => $lk($acc)];
    }
}

$iconFor = function (array $e): string {
    if ($e['type'] === 'dir') {
        return 'folder';
    }
    $ext = strtolower(pathinfo((string) ($e['name'] ?? ''), PATHINFO_EXTENSION));
    if (in_array($ext, ['zip', 'tar', 'gz', 'tgz', 'bz2', '7z', 'rar'], true)) {
        return 'archive';
    }
    if (in_array($ext, ['php', 'js', 'css', 'html', 'htm', 'json', 'xml', 'yml', 'yaml', 'py', 'sh', 'sql'], true)) {
        return 'code-2';
    }
    if (in_array($ext, ['sql', 'db', 'sqlite'], true)) {
        return 'database';
    }
    if (in_array($ext, ['htpasswd', 'key', 'pem'], true) || str_starts_with((string) ($e['name'] ?? ''), '.ht')) {
        return 'lock';
    }
    return 'circle';
};

// Symbolic perms (drwxr-xr-x) => octal triplet for the chmod placeholder.
$octalOf = function (string $perms): string {
    $map = ['r' => 4, 'w' => 2, 'x' => 1, '-' => 0];
    $out = '';
    $bits = substr($perms, 1, 9);
    if (strlen($bits) !== 9) {
        return '';
    }
    for ($i = 0; $i < 9; $i += 3) {
        $out .= (string) (($map[$bits[$i]] ?? 0) + ($map[$bits[$i + 1]] ?? 0) + ($map[$bits[$i + 2]] ?? 0));
    }
    return $out;
};

$isArchive = function (string $name): string {
    $lower = strtolower($name);
    if (str_ends_with($lower, '.zip')) {
        return 'zip';
    }
    if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz') || str_ends_with($lower, '.tar')) {
        return 'tar';
    }
    return '';
};
?>
<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">File Manager</h2>
    <span class="badge"><?= e('/' . $account . '/' . $path) ?></span>
    <?= $headerExtra ?? '' ?>
  </div>

  <div class="breadcrumbs" style="padding:8px 16px">
    <a href="<?= e($lk('')) ?>">Home</a>
    <?php foreach ($crumbs as $c): ?>
      <span class="text-muted"> / </span><a href="<?= e($c['url']) ?>"><?= e($c['label']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="filter-bar" style="align-items:flex-end">
    <form method="get" action="" class="inline-form fm-form">
      <input type="hidden" name="path" value="<?= e($path) ?>">
      <?php foreach ($extraQuery as $k => $v): ?>
        <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
      <?php endforeach; ?>
      <label class="field" style="margin:0">
        <span class="field-label">Search this folder</span>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="filter by name…">
      </label>
      <button type="submit" class="btn btn-sm">Filter</button>
      <?php if ($q !== ''): ?><a href="<?= e($lk($path)) ?>" class="btn btn-sm">Clear</a><?php endif; ?>
    </form>
  </div>

  <fieldset class="fieldset" style="margin:0 16px">
    <legend class="fieldset-legend">New in this folder</legend>
    <div class="filter-bar" style="align-items:flex-end;border:0;padding:0 0 8px">
      <form method="post" action="<?= e($actBase . '/mkdir') ?>" class="inline-form fm-form">
        <?= csrf_field() ?>
        <input type="hidden" name="parent" value="<?= e($path) ?>">
        <label class="field" style="margin:0">
          <span class="field-label">Folder name</span>
          <input class="input" type="text" name="path" value="<?= e(trim($path . '/', '/') . '/') ?>" placeholder="folder-name">
        </label>
        <button type="submit" class="btn btn-sm">Create folder</button>
      </form>
      <form method="post" action="<?= e($actBase . '/create') ?>" class="inline-form fm-form">
        <?= csrf_field() ?>
        <input type="hidden" name="parent" value="<?= e($path) ?>">
        <label class="field" style="margin:0">
          <span class="field-label">File name</span>
          <input class="input" type="text" name="name" placeholder="file.txt">
        </label>
        <button type="submit" class="btn btn-sm">Create &amp; edit</button>
      </form>
      <form method="post" action="<?= e($actBase . '/upload') ?>" enctype="multipart/form-data" class="inline-form fm-form">
        <?= csrf_field() ?>
        <input type="hidden" name="path" value="<?= e($path) ?>">
        <label class="field" style="margin:0">
          <span class="field-label">Upload file</span>
          <input class="input" type="file" name="file">
        </label>
        <button type="submit" class="btn btn-sm">Upload</button>
      </form>
    </div>
  </fieldset>

  <details class="fm-archive" style="margin:8px 16px 0">
    <summary class="btn btn-sm">Archive this folder…</summary>
    <div class="details-body">
      <form method="post" action="<?= e($actBase . '/archive') ?>" class="inline-form fm-form">
        <?= csrf_field() ?>
        <input type="hidden" name="path" value="<?= e($path === '' ? '.' : $path) ?>">
        <input type="hidden" name="parent" value="<?= e($path) ?>">
        <label class="field" style="margin:0">
          <span class="field-label">Archive name</span>
          <input class="input" type="text" name="name" value="archive">
        </label>
        <label class="field" style="margin:0">
          <span class="field-label">Format</span>
          <select class="input" name="format">
            <option value="tar">tar.gz</option>
            <option value="zip">zip</option>
          </select>
        </label>
        <button type="submit" class="btn btn-sm">Archive</button>
      </form>
    </div>
  </details>

  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th><th>Perms</th><th></th></tr></thead>
      <tbody>
      <?php if ($path !== ''): ?>
        <?php $up = dirname($path); if ($up === '.') $up = ''; ?>
        <tr><td colspan="6"><a href="<?= e($lk($up)) ?>"><?= icon('folder-up', 14) ?> Parent directory</a></td></tr>
      <?php endif; ?>
      <?php if (empty($entries)): ?>
        <?= empty_state(6, $q !== '' ? 'No matches.' : 'Empty directory.') ?>
      <?php else: foreach ($entries as $e): ?>
        <?php $full = trim($path . '/' . $e['name'], '/'); $fmt = $isArchive($e['name']); ?>
        <tr>
          <td>
            <span style="margin-right:6px"><?= icon($iconFor($e), 14) ?></span><?php if ($e['type'] === 'dir'): ?><a href="<?= e($lk($full)) ?>"><?= e($e['name']) ?>/</a><?php else: ?><a href="<?= e($editPrefix . urlencode($full)) ?>"><?= e($e['name']) ?></a><?php endif; ?>
          </td>
          <td><?= e($e['type']) ?></td>
          <td><?= e($e['type'] === 'dir' ? '—' : number_format($e['size'] ?? 0)) ?></td>
          <td class="nowrap"><?= e($e['date'] ?? '') ?></td>
          <td><code><?= e($e['perms'] ?? '') ?></code></td>
          <td class="actions">
            <?php if ($e['type'] !== 'dir'): ?>
              <a href="<?= e($editPrefix . urlencode($full)) ?>" class="btn btn-sm">Edit</a>
              <a href="<?= e($dlPrefix . urlencode($full)) ?>" class="btn btn-sm">Download</a>
            <?php endif; ?>
            <?php if ($fmt !== ''): ?>
              <form method="post" action="<?= e($actBase . '/extract') ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="name" value="<?= e($e['name']) ?>">
                <input type="hidden" name="path" value="<?= e($path === '' ? '.' : $path) ?>">
                <input type="hidden" name="format" value="<?= e($fmt) ?>">
                <input type="hidden" name="parent" value="<?= e($path) ?>">
                <button type="submit" class="btn btn-sm">Extract</button>
              </form>
            <?php endif; ?>
            <details class="inline-details">
              <summary class="btn btn-sm">More</summary>
              <div class="details-body">
                <p class="field-label" style="margin:4px 0">Organize</p>
                <form method="post" action="<?= e($actBase . '/rename') ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="from" value="<?= e($full) ?>">
                  <input type="hidden" name="parent" value="<?= e($path) ?>">
                  <input class="input" type="text" name="to" value="<?= e($full) ?>" style="min-width:160px" aria-label="Rename to">
                  <button type="submit" class="btn btn-sm">Rename / Move</button>
                </form>
                <form method="post" action="<?= e($actBase . '/copy') ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="from" value="<?= e($full) ?>">
                  <input type="hidden" name="parent" value="<?= e($path) ?>">
                  <input class="input" type="text" name="to" value="<?= e($full) ?>-copy" style="min-width:160px" aria-label="Copy to">
                  <button type="submit" class="btn btn-sm">Copy</button>
                </form>
                <p class="field-label" style="margin:8px 0 4px">Permissions</p>
                <form method="post" action="<?= e($actBase . '/chmod') ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="path" value="<?= e($full) ?>">
                  <input type="hidden" name="parent" value="<?= e($path) ?>">
                  <input class="input" type="text" name="mode" value="<?= e($octalOf($e['perms'] ?? '')) ?>" pattern="[0-7]{3}" maxlength="3" style="width:64px" aria-label="Octal mode">
                  <button type="submit" class="btn btn-sm">Chmod</button>
                </form>
                <hr>
                <form method="post" action="<?= e($actBase . '/delete') ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="path" value="<?= e($full) ?>">
                  <input type="hidden" name="parent" value="<?= e($path) ?>">
                  <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete <?= e($e['name']) ?>?">Delete</button>
                </form>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
