<?php $domain = $domain ?? []; ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($domain['name']) ?></div></div>
  <div class="card"><div class="card-label">Account</div><div class="card-value"><?= e($domain['username'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Type</div><div class="card-value"><?= e($domain['type']) ?></div></div>
  <div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($domain['status']) ?></div></div>
  <div class="card"><div class="card-label">Document root</div><div class="card-value"><code><?= e($domain['document_root'] ?? '—') ?></code></div></div>
  <div class="card"><div class="card-label">Redirect</div><div class="card-value"><?= e($domain['redirect_url'] ?? '—') ?></div></div>
</div>

<div class="panel" style="padding:16px">
  <div class="form-actions">
    <a href="<?= e(url('admin/domains/' . $domain['id'] . '/edit')) ?>" class="btn">Edit</a>
    <form method="post" action="<?= e(url('admin/domains/' . $domain['id'] . '/delete')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-danger" data-confirm="Delete domain <?= e($domain['name']) ?>?">Delete</button>
    </form>
  </div>
</div>