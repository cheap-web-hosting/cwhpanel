<?php $groupedPermissions = $groupedPermissions ?? []; ?>

<?php foreach ($groupedPermissions as $module => $permissions): ?>
  <div class="panel">
    <div class="panel-header">
      <h2 class="panel-title"><?= e($module) ?></h2>
      <span class="badge"><?= e(count($permissions)) ?> permission(s)</span>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Name</th><th>Module</th></tr>
        </thead>
        <tbody>
          <?php foreach ($permissions as $p): ?>
            <tr>
              <td><code><?= e($p['name']) ?></code></td>
              <td><?= e($p['module']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>