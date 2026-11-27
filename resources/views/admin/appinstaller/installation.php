<?php $inst = $inst ?? []; $logs = $logs ?? []; ?>
<div class="cards-grid">
  <div class="card"><div class="card-label">Application</div><div class="card-value"><?= e($inst['app_name']) ?></div></div>
  <div class="card"><div class="card-label">Account</div><div class="card-value"><?= e($inst['username'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($inst['domain']) ?></div></div>
  <div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($inst['status']) ?></div></div>
</div>
<h2 class="section-title">Log</h2>
<div class="panel" style="padding:16px">
  <?php if (empty($logs)): ?><p class="empty">No log entries.</p>
  <?php else: ?><pre style="white-space:pre-wrap;margin:0;font-size:12px"><?php foreach ($logs as $l): ?><?= e($l['created_at']) ?> [<?= e($l['level']) ?>] <?= e($l['message']) ?><?= "\n" ?><?php endforeach; ?></pre><?php endif; ?>
</div>