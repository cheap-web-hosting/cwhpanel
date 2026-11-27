<?php
$email = $email ?? '';
$folder = $folder ?? 'INBOX';
$folders = $folders ?? [];
$listing = $listing ?? ['total' => 0, 'page' => 1, 'pages' => 0, 'messages' => []];
$messages = $listing['messages'] ?? [];
$page = (int) ($listing['page'] ?? 1);
$pages = (int) ($listing['pages'] ?? 0);
$q = $q ?? '';
$trash = $trash ?? null;
$isTrash = $trash !== null && $folder === $trash;
$folderUrl = function (array $extra = []) use ($folder) {
  return url('panel/webmail/folder?name=' . urlencode($folder) . ($extra ? '&' . http_build_query($extra) : ''));
};
?>
<div class="webmail-shell">
  <aside class="panel">
    <div class="panel-header"><h2 class="panel-title">Folders</h2></div>
    <ul class="folder-list">
      <?php foreach ($folders as $f): ?>
        <li>
          <a class="folder-link<?= $f['name'] === $folder ? ' active' : '' ?>" href="<?= e(url('panel/webmail/folder?name=' . urlencode($f['name']))) ?>">
            <span><?= e($f['name']) ?></span>
            <?php if ((int) $f['unread'] > 0): ?><span class="badge"><?= (int) $f['unread'] ?></span><?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </aside>

  <div class="panel">
    <div class="panel-header">
      <h2 class="panel-title"><?= e($folder) ?> <small style="font-weight:400">(<?= (int) ($listing['total'] ?? 0) ?>)</small></h2>
      <div class="actions">
        <a class="btn btn-sm" href="<?= e(url('panel/webmail/compose')) ?>"><?= icon('edit', 14) ?> Compose</a>
        <?php if ($isTrash): ?>
          <form method="post" action="<?= e(url('panel/webmail/trash/empty')) ?>" class="inline-form" data-confirm="Permanently delete ALL messages in Trash? This cannot be undone."><?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-danger">Empty Trash</button>
          </form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('panel/webmail/logout')) ?>" class="inline-form"><?= csrf_field() ?><button class="btn btn-sm" type="submit">Logout</button></form>
      </div>
    </div>

    <form method="get" action="<?= e(url('panel/webmail/folder')) ?>" style="display:flex;gap:8px;padding:12px 16px 0">
      <input type="hidden" name="name" value="<?= e($folder) ?>">
      <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search this folder…" style="flex:1;max-width:420px">
      <button type="submit" class="btn btn-sm">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-sm btn-ghost" href="<?= e($folderUrl()) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($q !== ''): ?><p class="text-muted" style="padding:4px 16px 0">Showing matches for “<?= e($q) ?>”.</p><?php endif; ?>

    <div class="table-wrap"><table class="table">
      <thead><tr><th>From</th><th>Subject</th><th style="width:110px">Date</th><th style="width:150px"></th></tr></thead>
      <tbody>
        <?php if (empty($messages)): ?>
          <?= empty_state(4, $q !== '' ? 'No messages match your search.' : 'No messages in this folder.') ?>
        <?php else: foreach ($messages as $m): ?>
          <tr<?= empty($m['seen']) ? ' class="row-unread"' : '' ?>>
            <td class="ellipsis"><?= e($m['from']) ?></td>
            <td><a href="<?= e(url('panel/webmail/read/' . $m['uid'] . '?folder=' . urlencode($folder))) ?>" class="inherit-link"><?= e($m['subject']) ?></a></td>
            <td class="nowrap"><?= e($m['date']) ?></td>
            <td class="actions">
              <?php if (empty($m['seen'])): ?>
                <form method="post" action="<?= e(url('panel/webmail/read/' . $m['uid'] . '/flag')) ?>" class="inline-form"><?= csrf_field() ?>
                  <input type="hidden" name="flag" value="\Seen"><input type="hidden" name="set" value="1">
                  <button type="submit" class="btn btn-sm" title="Mark as read">Read</button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= e(url('panel/webmail/read/' . $m['uid'] . '/flag')) ?>" class="inline-form"><?= csrf_field() ?>
                  <input type="hidden" name="flag" value="\Seen"><input type="hidden" name="set" value="0">
                  <button type="submit" class="btn btn-sm" title="Mark as unread">Unread</button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= e(url('panel/webmail/delete')) ?>" class="inline-form" data-confirm="<?= e('Permanently delete message \"' . $m['subject'] . '\"? This cannot be undone.') ?>"><?= csrf_field() ?>
                <input type="hidden" name="uid" value="<?= (int) $m['uid'] ?>">
                <input type="hidden" name="folder" value="<?= e($folder) ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>

    <?php if ($pages > 1): ?>
      <div class="pager" style="display:flex;gap:8px;padding:12px 16px;align-items:center">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($folderUrl(['q' => $q, 'page' => $page - 1])) ?>">Prev</a><?php endif; ?>
        <span style="color:#666">Page <?= $page ?> / <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($folderUrl(['q' => $q, 'page' => $page + 1])) ?>">Next</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
