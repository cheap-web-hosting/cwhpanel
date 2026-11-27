<?php $websites = $websites ?? []; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">PHP versions per website</h2>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Website</th><th>Domain</th><th>PHP version</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($websites)): ?>
          <?= empty_state(4, 'You have no websites yet. New websites are added by your administrator.') ?>
        <?php else: ?>
          <?php foreach ($websites as $w): ?>
            <tr>
              <td><strong><?= e($w['label']) ?></strong></td>
              <td><?= e($w['domain_name'] ?? '—') ?></td>
              <td><code><?= e($w['php_version'] ?? '—') ?></code></td>
              <td class="actions">
                <a href="<?= e(url('panel/websites/' . $w['id'] . '/php')) ?>" class="btn btn-sm btn-primary">Change PHP version</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
