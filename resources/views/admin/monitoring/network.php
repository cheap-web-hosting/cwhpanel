<?php
use Modules\Monitoring\Services\NetworkStatsService;
$interfaces = $interfaces ?? [];
$rates = $rates ?? [];
$hostRxTotal = $hostRxTotal ?? 0;
$hostTxTotal = $hostTxTotal ?? 0;
$monthTotal = $monthTotal ?? 0;
$usage = $usage ?? [];
$agentError = $agentError ?? '';
$hasInterfaces = $interfaces !== [];
$rxRate = $txRate = 0;
foreach ($rates as $r) { $rxRate += $r['rx_bps']; $txRate += $r['tx_bps']; }
?>
<?php if ($agentError !== ''): ?>
  <div class="alert alert-warning">⚠ Could not read live network counters (agent error): <code><?= e($agentError) ?></code></div>
<?php endif; ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Receive rate</div><div class="card-value"><?= e(NetworkStatsService::fmt((int) $rxRate)) ?>/s</div></div>
  <div class="card"><div class="card-label">Transmit rate</div><div class="card-value"><?= e(NetworkStatsService::fmt((int) $txRate)) ?>/s</div></div>
  <div class="card"><div class="card-label">Host received (cumulative)</div><div class="card-value"><?= e(NetworkStatsService::fmt($hostRxTotal)) ?></div></div>
  <div class="card"><div class="card-label">Host sent (cumulative)</div><div class="card-value"><?= e(NetworkStatsService::fmt($hostTxTotal)) ?></div></div>
  <div class="card"><div class="card-label">Account bandwidth this month</div><div class="card-value"><?= e(NetworkStatsService::fmt($monthTotal)) ?></div></div>
</div>

<h2 class="section-title">Interfaces</h2>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Interface</th><th>RX rate</th><th>RX total</th><th>TX rate</th><th>TX total</th><th>Errors</th><th>Drops</th></tr></thead>
  <tbody>
    <?php if (!$hasInterfaces): ?><?= empty_state(7, $agentError !== '' ? 'Interface counters unavailable.' : 'No interfaces reported.') ?>
    <?php else: foreach ($interfaces as $i): $r = $rates[$i['name']] ?? ['rx_bps'=>0,'tx_bps'=>0]; ?>
      <tr>
        <td><code><?= e($i['name']) ?></code></td>
        <td class="num"><?= e(NetworkStatsService::fmt((int) $r['rx_bps'])) ?>/s</td>
        <td class="num"><?= e(NetworkStatsService::fmt($i['rx_bytes'])) ?></td>
        <td class="num"><?= e(NetworkStatsService::fmt((int) $r['tx_bps'])) ?>/s</td>
        <td class="num"><?= e(NetworkStatsService::fmt($i['tx_bytes'])) ?></td>
        <td class="num"><?= e($i['rx_errors'] + $i['tx_errors']) ?></td>
        <td class="num"><?= e($i['rx_drop'] + $i['tx_drop']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table></div>

<h2 class="section-title">Account bandwidth — <?= e(date('Y-m')) ?></h2>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Account</th><th>Used</th><th>Limit</th><th>%</th><th>Status</th></tr></thead>
  <tbody>
    <?php if ($usage === []): ?><?= empty_state(5, 'No metered bandwidth usage recorded this month yet.') ?>
    <?php else: foreach ($usage as $u): ?>
      <tr>
        <td><?= e($u['username']) ?></td>
        <td class="num"><?= e($u['mb']) ?> MB</td>
        <td class="num"><?= $u['limit'] > 0 ? e($u['limit']) . ' MB' : 'Unlimited' ?></td>
        <td style="min-width:180px">
          <?php if ($u['pct'] === null): ?>
            <span class="text-muted">—</span>
          <?php else: ?>
            <div class="card-bar"><div class="card-bar-fill" style="width:<?= e(min(100, $u['pct'])) ?>%;<?= $u['pct'] >= 100 ? 'background:var(--danger,#dc2626);' : '' ?>"></div></div>
            <span class="text-muted"><?= e($u['pct']) ?>%</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($u['suspended']): ?><span class="status status-disabled">Suspended</span>
          <?php else: ?><span class="status status-active">OK</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table></div>
