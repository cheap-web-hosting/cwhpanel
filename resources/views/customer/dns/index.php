<?php $zones = $zones ?? []; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">My DNS zones</h2></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Zone</th><th>DNSSEC</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($zones)): ?>
          <?= empty_state(3, 'No DNS zones assigned to your account.') ?>
        <?php else: ?>
          <?php foreach ($zones as $z): ?>
            <tr>
              <td><strong><?= e($z['name']) ?></strong></td>
              <td><?= $z['dnssec'] ? 'Enabled' : 'Disabled' ?></td>
              <td class="actions">
                <a href="<?= e(url('panel/dns/zones/' . $z['name'])) ?>" class="btn btn-sm">Manage</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
