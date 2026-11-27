<?php $mailboxes = $mailboxes ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Your mailboxes</h2></div>
<?php if (empty($mailboxes)): ?>
  <p class="empty" style="padding:16px">You have no mailboxes yet. Create one under Email first.</p>
<?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Mailbox</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($mailboxes as $mb): ?>
        <tr>
          <td><strong><?= e($mb['email']) ?></strong></td>
          <td class="actions"><a href="<?= e(url('panel/webmail/roundcube/' . $mb['id'])) ?>" class="btn btn-primary btn-sm" title="Full-featured Roundcube (contacts, folders, search)">Open webmail (Roundcube)</a> <a href="<?= e(url('panel/webmail/open/' . $mb['id'])) ?>" class="btn btn-sm">Basic webmail</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p style="padding:0 16px 16px;color:#666">Roundcube is the default webmail (uses your panel login — no separate password). The basic webmail is still available.</p>
<?php endif; ?>
</div>