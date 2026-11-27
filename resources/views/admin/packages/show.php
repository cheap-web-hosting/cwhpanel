<?php $package = $package ?? []; $accounts = $accounts ?? []; ?>
<?php function pkgFmt($v, $suffix = '') { return e($v) . ' ' . $suffix; } ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Disk quota</div><div class="card-value"><?= e($package['disk_quota_mb']) ?> MB</div></div>
  <div class="card"><div class="card-label">Bandwidth</div><div class="card-value"><?= e($package['bandwidth_mb']) ?> MB</div></div>
  <div class="card"><div class="card-label">Domains</div><div class="card-value"><?= $package['max_domains'] === null ? 'Unlimited' : e((string) $package['max_domains']) ?></div></div>
  <div class="card"><div class="card-label">Subdomains</div><div class="card-value"><?= e($package['max_subdomains']) ?></div></div>
  <div class="card"><div class="card-label">Databases</div><div class="card-value"><?= e($package['max_databases']) ?></div></div>
  <div class="card"><div class="card-label">Email</div><div class="card-value"><?= e($package['max_email_accounts']) ?></div></div>
  <div class="card"><div class="card-label">Mail out / hour</div><div class="card-value"><?= e($package['mail_max_out_per_hour']) ?></div></div>
  <div class="card"><div class="card-label">Mail out / day</div><div class="card-value"><?= e($package['mail_max_out_per_day']) ?></div></div>
  <div class="card"><div class="card-label">Mail out / mailbox</div><div class="card-value"><?= e($package['mailbox_max_out_per_hour']) ?></div></div>
  <div class="card"><div class="card-label">Max mailbox size</div><div class="card-value"><?= empty($package['mailbox_quota_max_mb']) ? 'Unlimited' : e($package['mailbox_quota_max_mb']) . ' MB' ?></div></div>
  <div class="card"><div class="card-label">MySQL conn / user</div><div class="card-value"><?= e($package['mysql_max_user_connections'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">MySQL queries / hour</div><div class="card-value"><?= e($package['mysql_max_queries_per_hour'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">MySQL kill over</div><div class="card-value"><?= e($package['mysql_max_statement_sec'] ?? '—') ?>s</div></div>
  <div class="card"><div class="card-label">FTP</div><div class="card-value"><?= e($package['max_ftp_accounts']) ?></div></div>
  <div class="card"><div class="card-label">Cron</div><div class="card-value"><?= e($package['max_cron_jobs']) ?></div></div>
  <div class="card"><div class="card-label">Node.js</div><div class="card-value"><?= $package['max_nodejs_apps'] === null ? 'Unlimited' : e((string) $package['max_nodejs_apps']) ?></div></div>
  <div class="card"><div class="card-label">Python</div><div class="card-value"><?= $package['max_python_apps'] === null ? 'Unlimited' : e((string) $package['max_python_apps']) ?></div></div>
  <div class="card"><div class="card-label">CPU</div><div class="card-value"><?= e($package['max_cpu_percent']) ?>%</div></div>
  <div class="card"><div class="card-label">Memory</div><div class="card-value"><?= e($package['max_memory_mb']) ?> MB</div></div>
  <div class="card"><div class="card-label">Processes</div><div class="card-value"><?= e($package['max_processes']) ?></div></div>
  <div class="card"><div class="card-label">I/O</div><div class="card-value"><?= e($package['max_io_mbs'] ?? 0) ?> MB/s</div></div>
  <div class="card"><div class="card-label">IOPS</div><div class="card-value"><?= empty($package['max_iops']) ? 'Unlimited' : e($package['max_iops']) ?></div></div>
  <div class="card"><div class="card-label">Inodes</div><div class="card-value"><?= empty($package['disk_inodes']) ? 'Unlimited' : e($package['disk_inodes']) ?></div></div>
  <div class="card"><div class="card-label">PHP workers</div><div class="card-value"><?= empty($package['php_max_children']) ? '8 (default)' : e($package['php_max_children']) ?></div></div>
  <div class="card"><div class="card-label">PHP memory</div><div class="card-value"><?= empty($package['php_memory_limit_mb']) ? '256 MB (default)' : e($package['php_memory_limit_mb']) . ' MB' ?></div></div>
  <div class="card"><div class="card-label">PHP timeout</div><div class="card-value"><?= empty($package['php_max_execution_time']) ? '120 s (default)' : e($package['php_max_execution_time']) . ' s' ?></div></div>
  <div class="card"><div class="card-label">Node memory</div><div class="card-value"><?= empty($package['max_node_memory_mb']) ? 'Account cap' : e($package['max_node_memory_mb']) . ' MB' ?></div></div>
  <div class="card"><div class="card-label">Python memory</div><div class="card-value"><?= empty($package['max_python_memory_mb']) ? 'Account cap' : e($package['max_python_memory_mb']) . ' MB' ?></div></div>
  <div class="card"><div class="card-label">DB storage</div><div class="card-value"><?= empty($package['database_quota_mb']) ? 'Unlimited' : e($package['database_quota_mb']) . ' MB' ?></div></div>
  <div class="card"><div class="card-label">Connections</div><div class="card-value"><?= empty($package['max_connections']) ? 'Unlimited' : e($package['max_connections']) ?></div></div>
  <div class="card"><div class="card-label">Backup storage</div><div class="card-value"><?= empty($package['backup_quota_mb']) ? 'Unlimited' : e($package['backup_quota_mb']) . ' MB' ?></div></div>
  <div class="card"><div class="card-label">Status</div><div class="card-value"><?= $package['is_active'] ? '<span class="status status-active">Active</span>' : '<span class="status status-disabled">Inactive</span>' ?></div></div>
</div>

<?php if ($package['description']): ?>
  <h2 class="section-title">Description</h2>
  <div class="panel" style="padding:16px"><?= e($package['description']) ?></div>
<?php endif; ?>

<h2 class="section-title">Features</h2>
<div class="panel" style="padding:16px">
  <?php $features = $features ?? []; ?>
  <?php if (empty($features)): ?>
    <p class="text-muted">All features enabled (legacy package).</p>
  <?php else: ?>
    <div class="check-grid">
      <?php foreach ($features as $key => $on): ?>
        <div><?= $on ? '<span class="status status-active">On</span>' : '<span class="status status-disabled">Off</span>' ?> <?= e(\Modules\Packages\Services\FeatureService::label((string) $key)) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<h2 class="section-title">Assigned accounts</h2>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Username</th><th>Domain</th><th>Status</th></tr></thead>
    <tbody>
      <?php if (empty($accounts)): ?>
        <?= empty_state(3, 'No accounts use this package.') ?>
      <?php else: ?>
        <?php foreach ($accounts as $a): ?>
          <tr>
            <td><?= e($a['username']) ?></td>
            <td><?= e($a['domain']) ?></td>
            <td><?= e($a['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
