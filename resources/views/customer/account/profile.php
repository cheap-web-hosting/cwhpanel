<?php $user = $user ?? null; $role = $role ?? '—'; $hosting = $hosting ?? null; $sshMode = $sshMode ?? null; $sessions = $sessions ?? []; ?>

<div class="cards-grid">
  <div class="card">
    <div class="card-label">Name</div>
    <div class="card-value"><?= e($user->name ?? '—') ?></div>
  </div>
  <div class="card">
    <div class="card-label">Email</div>
    <div class="card-value"><?= e($user->email ?? '—') ?></div>
  </div>
  <div class="card">
    <div class="card-label">Role</div>
    <div class="card-value"><?= e($role) ?></div>
  </div>
  <div class="card">
    <div class="card-label">Last login</div>
    <div class="card-value"><?= e($user->lastLoginAt ?? '—') ?></div>
  </div>
</div>

<div class="two-col">
  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">Profile</h2></div>
    <form method="post" action="<?= e(url('panel/account')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <div class="field">
        <label class="field-label" for="name">Display name</label>
        <input class="input" type="text" id="name" name="name" value="<?= e(old('name', $user->name ?? '')) ?>" required>
      </div>
      <div class="field">
        <label class="field-label" for="email">Email address</label>
        <input class="input" type="email" id="email" value="<?= e($user->email ?? '') ?>" disabled>
        <p class="field-help">Email changes must be requested from your administrator.</p>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">Change panel password</h2></div>
    <p class="text-muted" style="padding:0 16px">Signs into this customer panel only — SSH, FTP, mailboxes and databases each have their own passwords (see <a href="<?= e(url('panel/account')) ?>#logins">Logins &amp; access</a> below).</p>
    <form method="post" action="<?= e(url('panel/account/password')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <div class="field">
        <label class="field-label" for="current_password">Current password</label>
        <input class="input" type="password" id="current_password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="field">
        <label class="field-label" for="password">New password</label>
        <input class="input" type="password" id="password" name="password" required minlength="<?= e(config('auth.min_password_length', 10)) ?>" autocomplete="new-password">
        <p class="field-help">Minimum <?= e(config('auth.min_password_length', 10)) ?> characters.</p>
      </div>
      <div class="field">
        <label class="field-label" for="password_confirmation">Confirm new password</label>
        <input class="input" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary" data-confirm="Change your password?">Change password</button>
      </div>
    </form>
  </div>
</div>

<div class="panel" style="margin-top:16px" id="logins">
  <div class="panel-header"><h2 class="panel-title">Logins &amp; access</h2></div>
  <div style="padding:16px">
<div class="panel" style="margin-top:16px">
  <div class="panel-header"><h2 class="panel-title">Active sessions</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Session</th><th>IP address</th><th>Browser / device</th><th>Last active</th><th style="width:110px"></th></tr></thead>
    <tbody>
      <?php if (empty($sessions)): ?><?= empty_state(5, 'Only this session — nothing else to show.') ?>
      <?php else: foreach ($sessions as $s): ?>
        <tr>
          <td><code><?= e($s['short']) ?></code>
            <?php if (!empty($s['current'])): ?><br><span class="status status-active">This device</span><?php endif; ?></td>
          <td><?= e($s['ip'] ?? '—') ?></td>
          <td class="ellipsis"><?= e($s['agent'] ?? '—') ?></td>
          <td class="nowrap"><?= e(date('M j H:i', (int) $s['last_activity'])) ?></td>
          <td class="actions">
            <?php if (empty($s['current'])): ?><form method="post" action="<?= e(url('panel/account/sessions/revoke')) ?>" class="inline-form"><?= csrf_field() ?>
              <input type="hidden" name="session_id" value="<?= e($s['token']) ?>">
              <button type="submit" class="btn btn-sm" data-confirm="Sign out this session?">Revoke</button>
            </form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
  <?php $others = count(array_filter($sessions, fn($s) => empty($s['current']))); ?>
  <?php if ($others > 0): ?>
  <form method="post" action="<?= e(url('panel/account/sessions/revoke-others')) ?>" style="padding:0 16px 16px" data-confirm="Sign out all <?= $others ?> other session<?= $others === 1 ? '' : 's' ?>?"><?= csrf_field() ?>
    <button type="submit" class="btn">Sign out all other sessions</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($hosting): ?>
      <p><strong>System username:</strong> <code><?= e($hosting['username']) ?></code>
        <span class="text-muted">(SSH, FTP and file manager — same password everywhere below)</span></p>
      <p><strong>SSH access:</strong> <?= e($sshMode ?? 'unknown') ?></p>
      <p class="text-muted">Mailbox, FTP and database passwords are managed where those services live:
        <a href="<?= e(url('panel/email')) ?>">Email</a> ·
        <a href="<?= e(url('panel/ftp')) ?>">FTP</a> ·
        <a href="<?= e(url('panel/databases')) ?>">Databases</a></p>
    <?php else: ?>
      <p class="text-muted">No hosting account is linked to this login yet — your administrator links one after setup.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($hosting): ?>
<div class="panel" style="margin-top:16px">
  <div class="panel-header"><h2 class="panel-title">Change system password</h2></div>
  <p class="text-muted" style="padding:0 16px">One credential for <strong>SSH + FTP</strong> (both authenticate against the system user). Manage it here or on the <a href="<?= e(url('panel/security/ssh')) ?>">SSH Access</a> page. Mailbox and database passwords live on their own pages.</p>
  <form method="post" action="<?= e(url('panel/account/system-password')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="sys_current_password">Current panel password</label>
      <input class="input" type="password" id="sys_current_password" name="current_password" required autocomplete="current-password">
      <p class="field-help">Confirms it is really you (the system password itself can never be shown).</p>
    </div>
    <div class="field">
      <label class="field-label" for="sys_password">New system password</label>
      <input class="input" type="password" id="sys_password" name="password" required minlength="<?= e(config('auth.min_password_length', 10)) ?>" autocomplete="new-password">
      <p class="field-help">Minimum <?= e(config('auth.min_password_length', 10)) ?> characters. Used for SSH and FTP.</p>
    </div>
    <div class="field">
      <label class="field-label" for="sys_password_confirmation">Confirm new system password</label>
      <input class="input" type="password" id="sys_password_confirmation" name="password_confirmation" required autocomplete="new-password">
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary" data-confirm="Change your system password?">Change system password</button>
    </div>
  </form>
</div>
<?php endif; ?>