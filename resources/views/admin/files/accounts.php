<?php $accounts = $accounts ?? []; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Select an account</h2></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Account</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($accounts)): ?>
          <?= empty_state(2, 'No accounts.') ?>
        <?php else: foreach ($accounts as $a): ?>
          <tr>
            <td><strong><?= e($a['username']) ?></strong></td>
            <td class="actions"><a href="<?= e(url('admin/files?account=' . urlencode($a['username']))) ?>" class="btn btn-sm">Browse</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
