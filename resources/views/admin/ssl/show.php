<?php $cert = $cert ?? []; ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($cert['domain_name'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Type</div><div class="card-value"><?= e($cert['type']) ?></div></div>
  <div class="card"><div class="card-label">Issued</div><div class="card-value"><?= e($cert['issued_at'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Expires</div><div class="card-value"><?= e($cert['expires_at'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($cert['status']) ?></div></div>
  <div class="card"><div class="card-label">Auto-renew</div><div class="card-value"><?= $cert['auto_renew'] ? 'Enabled' : 'Disabled' ?></div></div>
  <div class="card"><div class="card-label">Retry after</div><div class="card-value"><?= e($cert['retry_after'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Last error</div><div class="card-value"><?= e($cert['last_error'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Cert path</div><div class="card-value"><code><?= e($cert['cert_path'] ?? '—') ?></code></div></div>
</div>

<div class="panel" style="padding:16px">
  <div class="form-actions">
    <?php if ($cert['type'] === 'acme'): ?>
      <form method="post" action="<?= e(url('admin/ssl/' . $cert['id'] . '/auto-renew')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn"><?= $cert['auto_renew'] ? 'Disable' : 'Enable' ?> auto-renew</button>
      </form>
    <?php endif; ?>
    <form method="post" action="<?= e(url('admin/ssl/' . $cert['id'] . '/renew')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn" data-confirm="Renew certificate?">Renew</button>
    </form>
    <form method="post" action="<?= e(url('admin/ssl/' . $cert['id'] . '/delete')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-danger" data-confirm="Delete certificate?">Delete</button>
    </form>
  </div>
</div>