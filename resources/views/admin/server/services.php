<?php $statuses = $statuses ?? []; $agentError = $agentError ?? null; ?>
<?php if ($agentError !== null): ?>
  <div class="flash flash-error" role="alert"><?= e($agentError) ?></div>
<?php endif; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Host services</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Service</th><th>State</th><th class="actions">Actions</th></tr></thead>
    <tbody>
      <?php if (empty($statuses)): ?>
        <?= empty_state(3, 'No services reported.') ?>
      <?php else: ?>
        <?php foreach ($statuses as $s): ?>
          <tr>
            <td><code><?= e($s['name']) ?></code></td>
            <td><?= $s['active'] ? '<span class="status status-active">' . e($s['state']) . '</span>' : '<span class="status status-disabled">' . e($s['state']) . '</span>' ?></td>
            <td class="actions">
              <?php if ($s['active']): ?>
                <form method="post" action="<?= e(url('admin/server/services/control')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="service" value="<?= e($s['name']) ?>">
                  <input type="hidden" name="action" value="restart">
                  <button type="submit" class="btn btn-sm" data-confirm="Restart <?= e($s['name']) ?>?">Restart</button>
                </form>
                <form method="post" action="<?= e(url('admin/server/services/control')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="service" value="<?= e($s['name']) ?>">
                  <input type="hidden" name="action" value="stop">
                  <button type="submit" class="btn btn-sm btn-danger" data-confirm="Stop <?= e($s['name']) ?>?">Stop</button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= e(url('admin/server/services/control')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="service" value="<?= e($s['name']) ?>">
                  <input type="hidden" name="action" value="start">
                  <button type="submit" class="btn btn-sm btn-primary">Start</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table></div>
  <p style="padding:0 16px 16px;color:#666">Service names are restricted to a curated allowlist enforced by the privileged agent.</p>
</div>
