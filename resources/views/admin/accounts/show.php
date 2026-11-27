<?php $account = $account ?? []; $logs = $logs ?? []; $sshKeys = $sshKeys ?? []; $sshShell = $sshShell ?? 'unknown'; $sshEnabled = $sshEnabled ?? false; ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Username</div><div class="card-value"><?= e($account['username']) ?></div></div>
  <div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($account['domain']) ?></div></div>
  <div class="card"><div class="card-label">Email</div><div class="card-value"><?= e($account['email'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Package</div><div class="card-value"><?= e($account['package_name'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Home</div><div class="card-value"><code><?= e($account['home_dir'] ?? '—') ?></code></div></div>
  <div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($account['status']) ?></div></div>
  <div class="card"><div class="card-label">Nginx worker</div><div class="card-value"><?= !empty($account['nginx_worker_enabled']) ? 'isolated' : 'shared' ?></div></div>
</div>

<h2 class="section-title">Resource limits</h2>
<div class="cards-grid">
  <div class="card"><div class="card-label">Disk</div><div class="card-value"><?= e($account['disk_quota_mb'] ?? 0) ?> MB</div></div>
  <div class="card"><div class="card-label">Bandwidth</div><div class="card-value"><?= e($account['bandwidth_mb'] ?? 0) ?> MB</div></div>
  <div class="card"><div class="card-label">Domains</div><div class="card-value"><?= ($account['max_domains'] ?? null) === null ? 'Unlimited' : e((string) $account['max_domains']) ?></div></div>
  <div class="card"><div class="card-label">Databases</div><div class="card-value"><?= ($account['max_databases'] ?? null) === null ? 'Unlimited' : e((string) $account['max_databases']) ?></div></div>
  <div class="card"><div class="card-label">Email</div><div class="card-value"><?= ($account['max_email_accounts'] ?? null) === null ? 'Unlimited' : e((string) $account['max_email_accounts']) ?></div></div>
  <div class="card"><div class="card-label">CPU</div><div class="card-value"><?= e($account['max_cpu_percent'] ?? 0) ?>%</div></div>
  <div class="card"><div class="card-label">Memory</div><div class="card-value"><?= e($account['max_memory_mb'] ?? 0) ?> MB</div></div>
  <div class="card"><div class="card-label">Processes</div><div class="card-value"><?= e($account['max_processes'] ?? 0) ?></div></div>
</div>

<h2 class="section-title">Actions</h2>
<div class="panel" style="padding:16px">
  <div class="form-actions">
    <a href="<?= e(url('admin/accounts/' . $account['id'] . '/edit')) ?>" class="btn">Edit</a>
    <?php if ($account['status'] === 'active'): ?>
      <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/suspend')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn" data-confirm="Suspend account <?= e($account['username']) ?>?">Suspend</button>
      </form>
    <?php elseif ($account['status'] === 'suspended'): ?>
      <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/unsuspend')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary">Unsuspend</button>
      </form>
    <?php endif; ?>
    <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/reprovision')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn" data-confirm="Reprovision account <?= e($account['username']) ?>?">Reprovision</button>
    </form>
    <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/toggle-nginx-worker')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn" data-confirm="Toggle per-account nginx worker isolation for <?= e($account['username']) ?>?">
        <?= !empty($account['nginx_worker_enabled']) ? 'Disable nginx worker' : 'Enable nginx worker' ?>
      </button>
    </form>
    <?php if ($account['status'] !== 'terminated'): ?>
      <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/terminate')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger" data-confirm="Terminate account <?= e($account['username']) ?>? This is irreversible.">Terminate</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<h2 class="section-title">SSH access</h2>
<?php if (!empty($agentError)): ?>
  <div class="flash flash-error" style="margin-bottom:12px"><?= e($agentError) ?></div>
<?php endif; ?>
<div class="panel" style="padding:16px">
  <?php $sshMode = $sshMode ?? ($sshEnabled ? 'full' : 'off'); ?>
  <p>Login shell: <code><?= e($sshShell) ?></code>
    <?php if ($sshMode === 'jailed'): ?><span class="status status-active">Jailed (chroot)</span>
    <?php elseif ($sshMode === 'full'): ?><span class="status status-active">Full access (legacy — switch to jailed)</span>
    <?php elseif ($sshMode === 'unknown'): ?><span class="status status-disabled">Unknown (agent unreachable)</span>
    <?php else: ?><span class="status status-disabled">Disabled</span><?php endif; ?></p>
  <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/ssh/shell')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="check"><input type="radio" name="mode" value="jailed" <?= $sshMode === 'jailed' ? 'checked' : '' ?>> <span>Jailed <span class="text-muted">— chroot to own files only (/jail)</span></span></label>
      <label class="check"><input type="radio" name="mode" value="off" <?= $sshMode === 'off' ? 'checked' : '' ?>> <span>Disabled <span class="text-muted">— no SSH logins</span></span></label>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Update SSH access</button>
    </div>
  </form>
</div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>#</th><th>Type</th><th>Comment</th><th>Fingerprint</th><th class="actions">Actions</th></tr></thead>
  <tbody>
    <?php if (empty($sshKeys)): ?>
      <?= empty_state(5, 'No authorized keys.') ?>
    <?php else: ?>
      <?php foreach ($sshKeys as $k): ?>
        <tr>
          <td><?= e((string) ($k['index'] + 1)) ?></td>
          <td><code><?= e($k['type']) ?></code></td>
          <td><?= e($k['comment'] !== '' ? $k['comment'] : '—') ?></td>
          <td><code><?= e($k['fingerprint']) ?></code></td>
          <td class="actions">
            <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/ssh/keys/' . $k['index'] . '/delete')) ?>" class="inline-form">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove this key?">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table></div>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/ssh/keys')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="ssh-key">Add public key</label>
      <textarea class="input" id="ssh-key" name="key" rows="3" required placeholder="ssh-ed25519 AAAA… you@host" style="font-family:monospace"></textarea>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Add key</button>
    </div>
  </form>
</div>

<?php if ($account['user_id']): ?>
  <h2 class="section-title">Panel login password</h2>
  <p class="text-muted">Changes the panel login of the linked user (not the system/SSH password).</p>
  <div class="panel" style="padding:16px">
    <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/change-password')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <div class="field">
        <label class="field-label" for="password">New password</label>
        <input class="input" type="password" autocomplete="new-password" id="password" name="password" required minlength="<?= e(config('auth.min_password_length', 10)) ?>">
        <p class="field-help">Minimum <?= e(config('auth.min_password_length', 10)) ?> characters.</p>
      </div>
      <div class="field">
        <label class="field-label" for="password_confirmation">Confirm password</label>
        <input class="input" type="password" autocomplete="new-password" id="password_confirmation" name="password_confirmation" required>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Change password</button>
      </div>
    </form>
  </div>
<?php else: ?>
  <h2 class="section-title">Panel login password</h2>
  <p class="text-muted">No panel login is linked to this account, so there is no login password to change. Link a user to enable this.</p>
<?php endif; ?>

<?php if (!empty($has_system_user)): ?>
  <h2 class="section-title">System password (SSH)</h2>
  <p class="text-muted">Changes the system password of <code><?= e($account['username']) ?></code> (SSH, and any service using system auth).</p>
  <div class="panel" style="padding:16px">
    <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/change-system-password')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <div class="field">
        <label class="field-label" for="sys_password">New system password</label>
        <input class="input" type="password" autocomplete="new-password" id="sys_password" name="password" required minlength="<?= e(config('auth.min_password_length', 10)) ?>">
        <p class="field-help">Minimum <?= e(config('auth.min_password_length', 10)) ?> characters.</p>
      </div>
      <div class="field">
        <label class="field-label" for="sys_password_confirmation">Confirm system password</label>
        <input class="input" type="password" autocomplete="new-password" id="sys_password_confirmation" name="password_confirmation" required>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Change system password</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if (!empty($account['user_id'])): ?>
<h2 class="section-title">Impersonate</h2>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/impersonate')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <p class="text-muted" style="margin:0">Log in as this account's customer (audited; admins only). Use <strong>Return to admin</strong> in the customer bar to come back.</p>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary" data-confirm="Log in as this customer?">Login as customer</button>
    </div>
  </form>
</div>
<?php endif; ?>

<h2 class="section-title">Backup encryption</h2>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('admin/accounts/' . $account['id'] . '/backup-encryption')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <input type="hidden" name="enabled" value="<?= !empty($account['backup_encrypted']) ? '0' : '1' ?>">
    <p class="text-muted" style="margin:0">New backups are <?= !empty($account['backup_encrypted']) ? 'sealed (AES-256-GCM)' : 'stored unencrypted' ?>. Existing archives keep their form either way.</p>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= !empty($account['backup_encrypted']) ? 'Disable encryption' : 'Enable encryption' ?></button>
    </div>
  </form>
</div>

<h2 class="section-title">Provisioning log</h2>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Operation</th><th>Status</th><th>Command</th><th>Time</th></tr></thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <?= empty_state(4, 'No provisioning operations recorded.') ?>
      <?php else: ?>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><code><?= e($l['operation']) ?></code></td>
            <td><?= e($l['status']) ?></td>
            <td><code class="text-muted"><?= e($l['command'] ?? '') ?></code></td>
            <td class="nowrap"><?= e($l['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
