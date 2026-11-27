<?php $certs = $certs ?? []; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">My certificates</h2>
    <a href="<?= e(url('panel/ssl/create')) ?>" class="btn btn-primary">Issue Certificate</a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Domain</th><th>Type</th><th>Expires</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($certs)): ?>
          <?= empty_state(5, 'No certificates. <a href="' . e(url('panel/ssl/create')) . '">Issue one</a>.') ?>
        <?php else: ?>
          <?php foreach ($certs as $c): ?>
            <tr>
              <td><strong><?= e($c['domain_name']) ?></strong></td>
              <td><?= e($c['type']) ?></td>
              <td class="nowrap"><?= e($c['expires_at'] ?? '—') ?></td>
              <td><?= e($c['status']) ?></td>
              <td class="actions">
                <a href="<?= e(url('panel/ssl/' . $c['id'])) ?>" class="btn btn-sm">View</a>
                <form method="post" action="<?= e(url('panel/ssl/' . $c['id'] . '/delete')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete certificate?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>