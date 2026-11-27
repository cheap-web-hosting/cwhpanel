<?php $modules = $modules ?? []; ?>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Module</th>
        <th>Version</th>
        <th>Scope</th>
        <th>Dependencies</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($modules as $m): ?>
        <tr>
          <td>
            <strong><?= e($m['name']) ?></strong>
            <?php if ($m['system']): ?><span class="badge">System</span><?php endif; ?>
            <div><small class="text-muted"><?= e($m['id']) ?></small></div>
          </td>
          <td><?= e($m['version']) ?></td>
          <td>
            <?= $m['admin'] ? 'Admin' : '' ?>
            <?= $m['admin'] && $m['customer'] ? ' / ' : '' ?>
            <?= $m['customer'] ? 'Customer' : '' ?>
          </td>
          <td><?= e(implode(', ', $m['requires']) ?: '—') ?></td>
          <td>
            <?php if ($m['enabled']): ?>
              <span class="status status-active">Enabled</span>
            <?php elseif (!empty($m['reason']) && str_starts_with((string) $m['reason'], 'disabled by configuration')): ?>
              <span class="status status-disabled">Disabled</span>
            <?php else: ?>
              <span class="status status-disabled">Not loaded</span>
            <?php endif; ?>
            <?php if (!empty($m['reason']) && !str_starts_with((string) $m['reason'], 'disabled by configuration')): ?>
              <div><small class="text-muted"><?= e($m['reason']) ?></small></div>
            <?php endif; ?>
          </td>
          <td class="actions">
            <?php if (!$m['system']): ?>
              <form method="post" action="<?= e(url('admin/settings/modules/' . $m['id'] . '/toggle')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm <?= $m['enabled'] ? 'btn-danger' : 'btn-primary' ?>"
                        data-confirm="<?= e(($m['enabled'] ? 'Disable' : 'Enable') . ' module ' . $m['name'] . '?') ?>">
                  <?= $m['enabled'] ? 'Disable' : 'Enable' ?>
                </button>
                <?php if ($m['enabled'] && !empty($m['dependents'])): ?>
                  <button type="submit" name="cascade" value="1" class="btn btn-sm btn-danger"
                          data-confirm="<?= e('Disable ' . $m['name'] . ' AND ' . implode(', ', $m['dependents']) . '?') ?>">
                    Disable + dependents
                  </button>
                <?php endif; ?>
              </form>
            <?php else: ?>
              <span class="text-muted">Required</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>