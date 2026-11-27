<?php
$package = $package ?? null;
$old = $old ?? [];
$fields = $fields ?? [];

function pkgValue(?array $package, array $old, string $key, $default = 0) {
    if (array_key_exists($key, $old)) return $old[$key];
    return $package[$key] ?? $default;
}

$labels = [
    'name' => 'Package name',
    'slug' => 'Slug',
    'disk_quota_mb' => 'Disk quota (MB, 0 = unlimited)',
    'bandwidth_mb' => 'Bandwidth (MB/month, 0 = unlimited)',
    'max_domains' => 'Max domains (0 = none)',
    'max_subdomains' => 'Max subdomains (0 = none)',
    'max_databases' => 'Max databases (0 = none)',
    'max_email_accounts' => 'Max email accounts (0 = none)',
    'max_ftp_accounts' => 'Max FTP accounts (0 = none)',
    'max_cron_jobs' => 'Max cron jobs (0 = none)',
    'max_nodejs_apps' => 'Max Node.js apps (0 = none)',
    'max_python_apps' => 'Max Python apps (0 = none)',
    'max_cpu_percent' => 'Max CPU (%, 0 = unlimited)',
    'max_memory_mb' => 'Max memory (MB, 0 = unlimited)',
    'max_processes' => 'Max processes (0 = unlimited)',
    'max_io_mbs' => 'Max I/O (MB/s, 0 = unlimited)',
    'disk_inodes' => 'Max inodes (files, 0 = unlimited)',
    'max_iops' => 'Max IOPS (0 = unlimited)',
    'php_max_children' => 'PHP-FPM workers (0 = default 8, Unlimited = 64)',
    'php_memory_limit_mb' => 'PHP memory limit (MB, 0 = default 256, Unlimited = no cap)',
    'php_max_execution_time' => 'PHP max execution time (s, 0 = default 120, Unlimited = no cap)',
    'max_node_memory_mb' => 'Node.js memory limit (MB, 0 = account cap)',
    'max_python_memory_mb' => 'Python memory limit (MB, 0 = account cap)',
    'database_quota_mb' => 'Database storage quota (MB, 0 = unlimited)',
    'max_connections' => 'Max simultaneous connections (0 = unlimited)',
    'backup_quota_mb' => 'Backup storage quota (MB, 0 = unlimited)',
    'nginx_workers' => 'Nginx workers (isolated accounts, 0 = 1)',
    'nginx_max_body_mb' => 'Max upload size (MB, isolated accounts)',
    'nginx_req_per_sec' => 'Nginx rate limit (req/sec, 0 = disabled)',
    'nginx_req_burst' => 'Nginx rate limit burst',
    'mail_max_out_per_hour' => 'Outbound mail / hour (account, 0 = unlimited)',
    'mail_max_out_per_day' => 'Outbound mail / day (account, 0 = unlimited)',
    'mailbox_max_out_per_hour' => 'Outbound mail / hour (mailbox, 0 = unlimited)',
    'mailbox_quota_max_mb' => 'Max mailbox size (MB, 0 = unlimited)',
    'mysql_max_user_connections' => 'MySQL max connections / user (0 = unlimited)',
    'mysql_max_queries_per_hour' => 'MySQL max queries / hour (0 = unlimited)',
    'mysql_max_updates_per_hour' => 'MySQL max updates / hour (0 = unlimited)',
    'mysql_max_statement_sec' => 'MySQL kill queries over (sec, 0 = off)',
];
?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title"><?= $package ? 'Edit package' : 'Add package' ?></h2>
  </div>
  <form method="post" action="<?= e($package ? url('admin/packages/' . $package['id']) : url('admin/packages')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>

    <div class="field">
      <label class="field-label" for="name"><?= e($labels['name']) ?> <span class="text-muted">*</span></label>
      <input class="input" type="text" id="name" name="name" value="<?= e(pkgValue($package, $old, 'name')) ?>" required>
    </div>

    <div class="field">
      <label class="field-label" for="slug"><?= e($labels['slug']) ?> <span class="text-muted">*</span></label>
      <input class="input" type="text" id="slug" name="slug" value="<?= e(pkgValue($package, $old, 'slug')) ?>" required pattern="[A-Za-z0-9_-]+">
      <p class="field-help">Lowercase letters, numbers, dashes and underscores.</p>
    </div>

    <div class="field">
      <label class="field-label" for="description">Description</label>
      <textarea class="input" id="description" name="description" rows="3"><?= e($old['description'] ?? $package['description'] ?? '') ?></textarea>
    </div>

    <fieldset class="fieldset">
      <legend class="fieldset-legend">Resource limits</legend>
      <p class="field-help">Count limits: blank/<strong>Unlimited</strong> = no cap, <strong>0</strong> = none allowed. Capacity limits: <strong>Unlimited</strong> removes the cap, <strong>Disabled</strong> turns an enforcement mechanism off. The PHP fields treat 0 as the engine default.</p>
      <div class="check-grid">
        <?php
        $sentinels = ['php_max_children' => 64, 'php_memory_limit_mb' => -1, 'php_max_execution_time' => -1];
        // A limit is either removable (Unlimited) or a mechanism that can be
        // switched off (Disabled) — never both. Fields in $noToggle have no
        // meaningful off state (nginx_workers floors at 1), so no checkbox.
        $toggleLabels = [
            'mysql_max_statement_sec' => 'Disabled (no query killing)',
            'nginx_req_per_sec' => 'Disabled (no default throttle)',
        ];
        $countFields = ['max_domains', 'max_subdomains', 'max_databases', 'max_email_accounts',
            'max_ftp_accounts', 'max_cron_jobs', 'max_nodejs_apps', 'max_python_apps'];
        // Count limits need no toggle (blank = unlimited, 0 = none); neither
        // does nginx_workers (0 floors at 1).
        $noToggle = array_merge(['nginx_workers'], $countFields);
        foreach ($fields as $field):
          $isCount = in_array($field, $countFields, true);
          $stored = array_key_exists($field, $old) ? $old[$field] : ($package[$field] ?? null);
          if ($isCount) {
              // NULL = unlimited (blank input); 0 = none allowed.
              $isOff = ($stored === null || $stored === '');
              $rawVal = $isOff ? '' : (string) (int) $stored;
          } else {
              $rawVal = (int) ($stored ?? ($field === 'mailbox_quota_max_mb' ? 1024 : 0));
              $isOff = $rawVal === ($sentinels[$field] ?? 0);
          }
          $toggleLabel = $toggleLabels[$field] ?? 'Unlimited (no cap)';
        ?>
          <div class="field">
            <label class="field-label" for="<?= e($field) ?>"><?= e($labels[$field]) ?></label>
            <input class="input" type="number" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($rawVal) ?>" min="<?= in_array($field, ['php_memory_limit_mb', 'php_max_execution_time'], true) ? '-1' : '0' ?>" <?= $isCount ? '' : 'required' ?><?= $isCount ? ' placeholder="Unlimited"' : '' ?>>
            <?php if ($isCount): ?>
            <p class="field-help">Blank = unlimited, 0 = none allowed.</p>
            <?php elseif (!in_array($field, $noToggle, true)): ?>
            <label class="check" style="margin-top:4px">
              <input type="checkbox" name="unlimited[<?= e($field) ?>]" value="1" <?= $isOff ? 'checked' : '' ?>>
              <span class="text-muted"><?= e($toggleLabel) ?></span>
            </label>
            <?php else: ?>
            <p class="field-help">0 falls back to 1 worker.</p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="field">
      <label class="field-label" for="php_version">Default PHP version</label>
      <select class="input" id="php_version" name="php_version">
        <?php foreach (($phpVersions ?? []) as $vid => $vlabel): ?>
          <option value="<?= e((string) $vid) ?>" <?= (string) pkgValue($package, $old, 'php_version', '84') === (string) $vid ? 'selected' : '' ?>><?= e('PHP ' . $vlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="field-help">New accounts get their PHP-FPM pool on this version; websites created without an explicit choice inherit it. Existing sites are not changed.</p>
    </div>

    <fieldset class="fieldset">
      <legend class="fieldset-legend">Features (customer panel)</legend>
      <p class="field-help">Unchecked features are hidden from the customer's panel and their pages answer with an upgrade notice. Dashboard and Account stay always on.</p>
      <div class="check-grid">
        <?php foreach (($featureCatalog ?? []) as $key => [$flabel, $fdesc]): ?>
          <label class="check">
            <input type="checkbox" name="features[]" value="<?= e($key) ?>" <?= !empty($featureStates[$key]) ? 'checked' : '' ?>>
            <span><?= e($flabel) ?> <span class="text-muted">— <?= e($fdesc) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="field">
      <label class="check">
        <input type="checkbox" name="is_active" value="1" <?= pkgValue($package, $old, 'is_active', 1) ? 'checked' : '' ?>>
        <span>Active</span>
      </label>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save package</button>
      <a href="<?= e(url('admin/packages')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>