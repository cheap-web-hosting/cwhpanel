<?php $system = $system ?? []; ?>
<?php function fmtKb(int $kb): string { if ($kb===0) return '0 KB'; $u=['KB','MB','GB','TB']; $i=min(floor(log($kb,1024)),count($u)-1); return round($kb/pow(1024,$i),1).' '.$u[$i]; } ?>

<div class="table-wrap">
  <table class="table table-info">
    <tbody>
      <tr><th>Hostname</th><td><?= e($system['hostname'] ?? '—') ?></td></tr>
      <tr><th>Operating System</th><td><?= e($system['os']['pretty'] ?? '—') ?></td></tr>
      <tr><th>Kernel</th><td><?= e($system['kernel'] ?? '—') ?></td></tr>
      <tr><th>Uptime</th><td><?= e($system['uptime']['human'] ?? '—') ?> (<?= e($system['uptime']['seconds'] ?? 0) ?> seconds)</td></tr>
      <tr><th>Load (1m / 5m / 15m)</th><td><?= e(($system['load']['1m'] ?? 0) . ' / ' . ($system['load']['5m'] ?? 0) . ' / ' . ($system['load']['15m'] ?? 0)) ?></td></tr>
      <tr><th>CPU</th><td><?= e(($system['cpu']['cores'] ?? 0) . ' cores — ' . ($system['cpu']['model'] ?? 'Unknown')) ?></td></tr>
      <tr><th>Memory Total</th><td><?= e(fmtKb($system['memory']['total'] ?? 0)) ?></td></tr>
      <tr><th>Memory Used</th><td><?= e(fmtKb($system['memory']['used'] ?? 0) . ' (' . ($system['memory']['used_percent'] ?? 0) . '%)') ?></td></tr>
      <tr><th>Disk Total</th><td><?= e(fmtKb($system['disk']['total'] ?? 0)) ?></td></tr>
      <tr><th>Disk Used</th><td><?= e(fmtKb($system['disk']['used'] ?? 0) . ' (' . ($system['disk']['used_percent'] ?? 0) . '%)') ?></td></tr>
      <tr><th>PHP Version</th><td><?= e($system['php'] ?? '—') ?></td></tr>
      <tr><th>Database Version</th><td><?= e($system['database'] ?? '—') ?></td></tr>
    </tbody>
  </table>
</div>