<?php $templates = $templates ?? []; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">DNS templates</h2></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Name</th><th>Description</th><th>System</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($templates)): ?>
          <?= empty_state(4, 'No templates yet.') ?>
        <?php else: ?>
          <?php foreach ($templates as $t): ?>
            <tr>
              <td><strong><?= e($t['name']) ?></strong></td>
              <td><?= e($t['description'] ?? '—') ?></td>
              <td><?= $t['is_system'] ? '<span class="badge">System</span>' : '—' ?></td>
              <td class="actions">
                <?php if (!$t['is_system']): ?>
                  <form method="post" action="<?= e(url('admin/dns/templates/' . $t['id'] . '/delete')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete template?">Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<h2 class="section-title">Add template</h2>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('admin/dns/templates')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="name">Template name</label>
      <input class="input" type="text" id="name" name="name" required>
    </div>
    <div class="field">
      <label class="field-label" for="description">Description</label>
      <input class="input" type="text" id="description" name="description">
    </div>
    <div class="field">
      <label class="field-label" for="records">Records (one per line: TYPE|NAME|CONTENT|TTL)</label>
      <textarea class="input" id="records" name="records" rows="8" placeholder="A|@|1.2.3.4|3600&#10;MX|@|10 mail.example.com|3600&#10;TXT|@|v=spf1 mx -all|3600" required></textarea>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save template</button>
    </div>
  </form>
</div>
