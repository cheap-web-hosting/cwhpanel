<?php $system = $system ?? []; ?>
<?php function fKB(int $kb): string { if ($kb===0) return '0 KB'; $u=['KB','MB','GB','TB']; $i=min(floor(log($kb,1024)),count($u)-1); return round($kb/pow(1024,$i),1).' '.$u[$i]; } ?>

<div class="cards-grid">
  <div class="card">
    <div class="card-label">Hostname</div>
    <div class="card-value"><?= e($system['hostname'] ?? '—') ?></div>
  </div>
  <div class="card">
    <div class="card-label">Operating System</div>
    <div class="card-value"><?= e($system['os']['pretty'] ?? '—') ?></div>
  </div>
  <div class="card">
    <div class="card-label">Kernel</div>
    <div class="card-value"><?= e($system['kernel'] ?? '—') ?></div>
  </div>
  <div class="card">
    <div class="card-label">Uptime</div>
    <div class="card-value"><?= e($system['uptime']['human'] ?? '—') ?></div>
  </div>
  <div class="card">
    <div class="card-label">Load Average</div>
    <div class="card-value"><?= e(($system['load']['1m'] ?? 0) . ' / ' . ($system['load']['5m'] ?? 0) . ' / ' . ($system['load']['15m'] ?? 0)) ?></div>
  </div>
  <div class="card">
    <div class="card-label">CPU</div>
    <div class="card-value"><?= e(($system['cpu']['cores'] ?? 0) . ' cores') ?><br><small class="text-muted"><?= e($system['cpu']['model'] ?? '') ?></small></div>
  </div>
  <div class="card">
    <div class="card-label">Memory</div>
    <div class="card-value"><?= e(fKB($system['memory']['used'] ?? 0) . ' / ' . fKB($system['memory']['total'] ?? 0)) ?></div>
    <div class="card-bar"><div class="card-bar-fill" style="width:<?= e($system['memory']['used_percent'] ?? 0) ?>%"></div></div>
  </div>
  <div class="card">
    <div class="card-label">Disk</div>
    <div class="card-value"><?= e(fKB($system['disk']['used'] ?? 0) . ' / ' . fKB($system['disk']['total'] ?? 0)) ?></div>
    <div class="card-bar"><div class="card-bar-fill" style="width:<?= e($system['disk']['used_percent'] ?? 0) ?>%"></div></div>
  </div>
  <div class="card">
    <div class="card-label">PHP Version</div>
    <div class="card-value"><?= e($system['php'] ?? '—') ?></div>
  </div>
  <div class="card">
    <div class="card-label">Database</div>
    <div class="card-value"><?= e($system['database'] ?? '—') ?></div>
  </div>
</div>