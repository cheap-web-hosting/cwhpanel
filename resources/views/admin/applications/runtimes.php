<?php $installed = $installed ?? ['node' => [], 'python' => []]; $catalog = $catalog ?? []; $probeErrors = $probeErrors ?? []; ?>
<?php if ($probeErrors !== []): ?>
  <div class="alert alert-warning">⚠ Could not probe some runtime binaries (agent error) — statuses below may be incomplete: <code><?= e(implode('; ', array_slice($probeErrors, 0, 3))) ?></code></div>
<?php endif; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Application runtimes</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Runtime</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($catalog as $rt => $vers): ?>
        <?php foreach ($vers as $v => $label): $isInstalled = in_array((string) $v, $installed[$rt] ?? [], true); $isDefault = $v === \Modules\Applications\Services\AppService::defaultVersion($rt); ?>
          <tr>
            <td><strong><?= e($label) ?></strong><?= $isDefault ? ' <span class="text-muted">(default)</span>' : '' ?></td>
            <td><?= $isInstalled ? '<span class="status status-active">Installed</span>' : '<span class="status status-disabled">Not installed</span>' ?></td>
            <td class="actions">
              <?php if ($isInstalled || $isDefault): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <form method="post" action="<?= e(url('admin/runtimes/install')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="runtime" value="<?= e($rt) ?>">
                  <input type="hidden" name="version" value="<?= e($v) ?>">
                  <button type="submit" class="btn btn-sm btn-primary" data-confirm="Install <?= e($label) ?>? Node downloads a release tarball (verified); Python uses dnf. Runs in the background.">Install</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p style="padding:0 16px 16px;color:#666">Customers pick a runtime version per app at creation. Python apps additionally get an isolated virtualenv on that version.</p>
</div>
