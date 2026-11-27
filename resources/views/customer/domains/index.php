<?php $domains = $domains ?? []; ?>
<?php
$typeLabel = function (string $t): string {
    switch ($t) {
        case 'main': return 'Main';
        case 'addon': return 'Addon';
        case 'parked': return 'Parked';
        case 'subdomain': return 'Subdomain';
        default: return ucfirst($t);
    }
};
?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">My domains</h2>
    <a href="<?= e(url('panel/domains/create')) ?>" class="btn btn-primary">Add Domain</a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Domain</th><th>Type</th><th>Document root</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($domains)): ?>
          <?= empty_state(5, 'You have no domains yet. <a href="' . e(url('panel/domains/create')) . '">Add one</a>.') ?>
        <?php else: ?>
          <?php foreach ($domains as $d): ?>
            <tr>
              <td><strong><?= e($d['name']) ?></strong></td>
              <td><?= e($typeLabel($d['type'])) ?></td>
              <td><code><?= e($d['document_root'] ?? '—') ?></code></td>
              <td><?= $d['status'] === 'active' ? '<span class="status status-active">Active</span>' : '<span class="status status-disabled">' . e($d['status']) . '</span>' ?></td>
              <td class="actions">
                <a href="<?= e(url('panel/domains/' . $d['id'])) ?>" class="btn btn-sm">Manage</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>