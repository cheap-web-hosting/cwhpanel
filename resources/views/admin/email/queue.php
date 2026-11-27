<?php $messages = $messages ?? []; $raw = $raw ?? ''; $error = $error ?? ''; ?>
<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Mail Queue</h2>
    <form method="post" action="<?= e(url('admin/email/queue/flush')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary" data-confirm="Flush the entire mail queue?">Flush queue</button>
    </form>
  </div>
  <?php if ($error): ?>
    <div class="alert alert-warning" role="alert">⚠ Could not read the live mail queue (agent error): <code><?= e($error) ?></code></div>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>ID</th><th>From</th><th>To</th><th>Size</th><th>Arrived</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (empty($messages)): ?>
          <?= empty_state(6, 'Mail queue is empty.') ?>
        <?php else: foreach ($messages as $qid => $msg): ?>
          <tr>
            <td><code><?= e($qid) ?></code></td>
            <td><?= e($msg['sender'] ?? '—') ?></td>
            <td>
              <?php foreach (($msg['recipients'] ?? []) as $r): ?>
                <div><?= e($r['address'] ?? '') ?> <?= !empty($r['delay_reason']) ? ' <span class="badge badge-warning">' . e($r['delay_reason']) . '</span>' : '' ?></div>
              <?php endforeach; ?>
            </td>
            <td><?= e($msg['size'] ?? 0) ?></td>
            <td class="nowrap"><?= e($msg['arrival_time'] ?? '') ?></td>
            <td class="actions">
              <form method="post" action="<?= e(url('admin/email/queue/' . $qid . '/requeue')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm">Requeue</button>
              </form>
              <form method="post" action="<?= e(url('admin/email/queue/' . $qid . '/delete')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete this queued message?">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
