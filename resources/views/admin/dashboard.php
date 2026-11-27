<?php
use Modules\Core\Services\SystemInfo;

$system = $system ?? [];
$counts = $counts ?? [];
$audit = $audit ?? [];
$failedBackups = $failedBackups ?? [];
$failedJobs = $failedJobs ?? [];
$expiringSsl = $expiringSsl ?? [];
$suspendedAccounts = $suspendedAccounts ?? [];
$recentWebsites = $recentWebsites ?? [];

$attentionItems = [];

foreach ($failedBackups as $fb) {
    $attentionItems[] = [
        'label' => 'Failed backup: ' . ($fb['username'] ?? 'account #' . $fb['account_id']) . ' — ' . $fb['name'],
        'href' => url('admin/backups'),
    ];
}
foreach ($failedJobs as $fj) {
    $attentionItems[] = [
        'label' => 'Failed job: ' . ($fj['type'] ?? 'job') . ' (' . ($fj['status'] ?? '') . ')',
        'href' => url('admin/jobs'),
    ];
}
foreach ($expiringSsl as $ssl) {
    $attentionItems[] = [
        'label' => 'SSL expiring: ' . $ssl['domain_name'] . ' on ' . ($ssl['expires_at'] ?? 'soon'),
        'href' => url('admin/ssl'),
    ];
}
foreach ($suspendedAccounts as $sa) {
    $attentionItems[] = [
        'label' => 'Account ' . $sa['username'] . ' is ' . $sa['status'],
        'href' => url('admin/accounts'),
    ];
}
?>
<section class="panel" style="padding:16px">
  <h3 class="panel-title">Needs attention</h3>
  <?php if (!$attentionItems): ?>
    <p class="empty" style="margin:12px 0 0">Nothing needs attention right now.</p>
  <?php else: ?>
    <ul style="margin:12px 0 0;padding-left:18px;line-height:1.9">
      <?php foreach ($attentionItems as $item): ?>
        <li><a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<div class="stats-grid">
  <div class="stat">
    <span class="stat-value"><?= e($counts['accounts'] ?? 0) ?></span>
    <span class="stat-label">Accounts</span>
  </div>
  <div class="stat">
    <span class="stat-value"><?= e($counts['websites'] ?? 0) ?></span>
    <span class="stat-label">Websites</span>
  </div>
  <div class="stat">
    <span class="stat-value"><?= e($counts['doms'] ?? 0) ?></span>
    <span class="stat-label">Domains</span>
  </div>
  <div class="stat">
    <span class="stat-value"><?= e($counts['suspended'] ?? 0) ?></span>
    <span class="stat-label">Suspended accounts</span>
  </div>
</div>

<h2 class="section-title">System</h2>
<div class="cards-grid">
  <div class="card"><div class="card-label">Load</div><div class="card-value"><?= e(($system['load']['1m'] ?? 0) . ' / ' . ($system['load']['5m'] ?? 0) . ' / ' . ($system['load']['15m'] ?? 0)) ?></div></div>
  <div class="card"><div class="card-label">Memory</div><div class="card-value"><?= e(SystemInfo::formatBytes(($system['memory']['used'] ?? 0) * 1024) . ' / ' . SystemInfo::formatBytes(($system['memory']['total'] ?? 0) * 1024)) ?></div></div>
  <div class="card"><div class="card-label">Disk</div><div class="card-value"><?= e(SystemInfo::formatBytes($system['disk']['used'] ?? 0) . ' / ' . SystemInfo::formatBytes($system['disk']['total'] ?? 0)) ?></div></div>
  <div class="card"><div class="card-label">Uptime</div><div class="card-value"><?= e($system['uptime']['human'] ?? '—') ?></div></div>
</div>

<h2 class="section-title">Recently added websites</h2>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Website</th><th>Domain</th><th>Account</th></tr></thead>
    <tbody>
      <?php if (!$recentWebsites): ?>
        <?= empty_state(3, 'No websites yet.') ?>
      <?php else: foreach ($recentWebsites as $w): ?>
        <tr><td><?= e($w['label']) ?></td><td><?= e($w['domain_name'] ?? '—') ?></td><td><?= e($w['username'] ?? '—') ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<h2 class="section-title">Recent activity</h2>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>User</th><th>Action</th><th>Resource</th><th>IP</th><th>Time</th></tr></thead>
    <tbody>
      <?php if (empty($audit)): ?>
        <?= empty_state(5, 'No activity recorded yet.') ?>
      <?php else: foreach ($audit as $row): ?>
        <tr>
          <td><?= e($row['email'] ?? 'system') ?></td>
          <td><?= e($row['action'] ?? '') ?></td>
          <td><?= e($row['resource_type'] ?? '') ?><?= $row['resource_id'] ? ' #' . e($row['resource_id']) : '' ?></td>
          <td><?= e($row['ip_address'] ?? '') ?></td>
          <td class="nowrap"><?= e($row['created_at'] ?? '') ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
