<?php
$email = $email ?? '';
$message = $message ?? [];
$html = (string) ($message['html'] ?? '');
$text = (string) ($message['text'] ?? '');
$uid = (int) ($message['uid'] ?? 0);
$folders = $folders ?? [];
$folder = $folder ?? 'INBOX';
$folderParam = '?folder=' . urlencode($folder);
?>
<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title"><?= e($message['subject'] ?? '(no subject)') ?></h2>
    <div class="actions">
      <a class="btn btn-sm" href="<?= e(url('panel/webmail/compose/' . $uid)) ?>">Reply</a>
      <a class="btn btn-sm" href="<?= e(url('panel/webmail/folder?name=' . urlencode($folder))) ?>">Back</a>
      <form method="post" action="<?= e(url('panel/webmail/read/' . $uid . '/flag')) ?>" class="inline-form"><?= csrf_field() ?>
        <input type="hidden" name="flag" value="\Seen"><input type="hidden" name="set" value="0">
        <button type="submit" class="btn btn-sm">Mark unread</button>
      </form>
      <?php if (count($folders) > 1): ?>
        <form method="post" action="<?= e(url('panel/webmail/move')) ?>" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="uid" value="<?= $uid ?>">
          <input type="hidden" name="folder" value="<?= e($folder) ?>">
          <select name="to_folder" class="input" style="padding:4px 8px" required>
            <option value="">Move to…</option>
            <?php foreach ($folders as $f): if ($f['name'] === $folder) continue; ?>
              <option value="<?= e($f['name']) ?>"><?= e($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm">Move</button>
        </form>
      <?php endif; ?>
      <form method="post" action="<?= e(url('panel/webmail/delete')) ?>" class="inline-form" data-confirm="<?= e('Permanently delete message "' . ($message['subject'] ?? '') . '"? This cannot be undone.') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="uid" value="<?= $uid ?>">
        <input type="hidden" name="folder" value="<?= e($folder) ?>">
        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
      </form>
    </div>
  </div>

  <div class="message-meta">
    <div><strong>From:</strong> <?= e($message['from'] ?? '') ?></div>
    <div><strong>To:</strong> <?= e($message['to'] ?? '') ?></div>
    <div><strong>Date:</strong> <?= e($message['date'] ?? '') ?></div>
  </div>

  <div class="message-body">
    <?php if ($html !== ''): ?>
      <?php // Attacker-supplied HTML is rendered in an inert sandboxed iframe
      // (no scripts, forms, or top-navigation; images still load). The body
      // never touches the panel DOM directly. ?>
      <div class="webmail-html-wrap">
        <iframe class="webmail-html" sandbox="" srcdoc="<?= e($html) ?>" title="<?= e('Message body') ?>"></iframe>
        <details class="webmail-raw"><summary>View source</summary><pre class="message-text"><?= e($html) ?></pre></details>
      </div>
    <?php elseif ($text !== ''): ?>
      <pre class="message-text"><?= e($text) ?></pre>
    <?php else: ?>
      <p class="empty">This message has no readable body.</p>
    <?php endif; ?>
  </div>

  <?php if (!empty($message['attachments'])): ?>
    <div class="message-attachments">
      <h3 class="section-title">Attachments</h3>
      <ul class="attachment-list">
        <?php foreach ($message['attachments'] as $i => $a): ?>
          <li>
            <a href="<?= e(url('panel/webmail/read/' . $uid . '/attachment/' . $i . $folderParam)) ?>"><?= e($a['name']) ?></a>
            <span class="text-muted">(<?= e($a['type']) ?><?= isset($a['size']) ? ', ' . number_format((int) $a['size']) . ' B' : '' ?>)</span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
