<?php $installed = $installed ?? []; $available = $available ?? []; $usage = $usage ?? []; $usedBy = []; ?>
<?php foreach ($usage as $u): ?>
<?php $usedBy[\Modules\Core\Services\PhpVersionsService::id((string)($u['v'] ?? ''))][] = ['n' => (int)($u['n'] ?? 0), 'sites' => (string)($u['sites'] ?? '')]; ?>
<?php endforeach; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">PHP versions</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Version</th><th>Status</th><th>Used by</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($available as $id => $label): $id = (string) $id; $isInstalled = isset($installed[$id]); $use = $usedBy[$id] ?? []; $useCount = array_sum(array_column($use, 'n')); ?>
        <tr>
          <td><strong>PHP <?= e($label) ?></strong><?= $id === '84' ? ' <span class="text-muted">(system)</span>' : '' ?></td>
          <td><?= $isInstalled ? '<span class="status status-active">Installed</span>' : '<span class="status status-disabled">Not installed</span>' ?></td>
          <td><?= $useCount > 0 ? e($useCount . ' site' . ($useCount === 1 ? '' : 's')) : '<span class="text-muted">—</span>' ?></td>
          <td class="actions">
            <?php if ($isInstalled): ?>
              <a href="<?= e(url('admin/server/php-config/' . $id)) ?>" class="btn btn-sm">PHP config</a>
            <?php endif; ?>
            <?php if (!$isInstalled && $id !== '84'): ?>
              <form method="post" action="<?= e(url('admin/server/php-versions/install')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="version" value="<?= e($id) ?>">
                <button type="submit" class="btn btn-sm btn-primary" data-confirm="Install PHP <?= e($label) ?> via dnf/Remi? This may take a few minutes.">Install</button>
              </form>
            <?php elseif ($isInstalled && $id !== '84' && $useCount === 0): ?>
              <form method="post" action="<?= e(url('admin/server/php-versions/uninstall')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="version" value="<?= e($id) ?>">
                <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove PHP <?= e($label) ?> via dnf? Its FPM daemon is stopped and disabled first.">Uninstall</button>
              </form>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p style="padding:0 16px 16px;color:#666">Additional versions are installed via the Remi repository into <code>/opt/remi/phpXX</code>. After installing, websites can be assigned to that version. A version in use by websites cannot be removed — move those sites first.</p>
</div>