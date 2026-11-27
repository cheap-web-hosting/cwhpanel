<?php $cert = $cert ?? []; ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($cert['domain_name'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Type</div><div class="card-value"><?= e($cert['type']) ?></div></div>
  <div class="card"><div class="card-label">Issued</div><div class="card-value"><?= e($cert['issued_at'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Expires</div><div class="card-value"><?= e($cert['expires_at'] ?? '—') ?></div></div>
</div>

<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('panel/ssl/' . $cert['id'] . '/delete')) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger" data-confirm="Delete certificate?">Delete certificate</button>
  </form>
</div>