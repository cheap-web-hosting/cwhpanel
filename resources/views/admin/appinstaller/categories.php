<?php $categories = $categories ?? []; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Categories</h2></div>
  <div class="panel" style="padding:16px">
    <form method="post" action="<?= e(url('admin/appinstaller/categories')) ?>" class="filter-bar" style="border-bottom:0;padding:0">
      <?= csrf_field() ?>
      <input class="input" type="text" name="name" placeholder="Category name" required>
      <input class="input" type="number" name="sort_order" placeholder="Sort" value="0" style="max-width:90px">
      <button type="submit" class="btn btn-primary btn-sm">Add</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Slug</th><th>Sort</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($categories)): ?><?= empty_state(4, 'No categories.') ?>
      <?php else: foreach ($categories as $c): ?>
        <tr><td><?= e($c['name']) ?></td><td><code><?= e($c['slug']) ?></code></td><td><?= e($c['sort_order']) ?></td>
        <td class="actions"><form method="post" action="<?= e(url('admin/appinstaller/categories/'.$c['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete category?">Delete</button></form></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>