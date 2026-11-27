<?php
$role = $role ?? [];
$groupedPermissions = $groupedPermissions ?? [];
$assignedIds = $assignedIds ?? [];
?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Role: <?= e($role['name']) ?></h2>
    <span class="badge"><?= e($role['slug']) ?></span>
    <?php if ($role['is_system']): ?>
      <span class="badge badge-warning">System role — permissions are managed automatically</span>
    <?php endif; ?>
  </div>

  <form method="post" action="<?= e(url('admin/access/roles/' . $role['id'])) ?>" class="form-stack">
    <?= csrf_field() ?>

    <?php foreach ($groupedPermissions as $module => $permissions): ?>
      <fieldset class="fieldset">
        <legend class="fieldset-legend"><?= e($module) ?></legend>
        <div class="check-grid">
          <?php foreach ($permissions as $p): ?>
            <label class="check">
              <input type="checkbox" name="permissions[]" value="<?= e($p['id']) ?>"
                     <?= in_array($p['id'], $assignedIds) ? 'checked' : '' ?>
                     <?= $role['is_system'] ? 'disabled' : '' ?>>
              <span><?= e($p['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php endforeach; ?>

    <?php if (!$role['is_system']): ?>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save permissions</button>
      </div>
    <?php endif; ?>
  </form>
</div>

<?php if (!$role['is_system']): ?>
<div class="panel" style="margin-top:16px">
  <div class="panel-header"><h2 class="panel-title">Delete role</h2></div>
  <form method="post" action="<?= e(url('admin/access/roles/' . $role['id'] . '/delete')) ?>" style="padding:16px" data-confirm="Delete the <?= e($role['name']) ?> role? Users must be reassigned first."><?= csrf_field() ?>
    <p class="text-muted">Refused while any user holds this role (reassign them first), and you cannot delete your own role.</p>
    <div class="form-actions"><button type="submit" class="btn btn-danger">Delete role</button></div>
  </form>
</div>
<?php endif; ?>