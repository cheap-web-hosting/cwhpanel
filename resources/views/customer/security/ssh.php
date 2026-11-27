<?php $account = $account ?? []; $keys = $keys ?? []; $shell = $shell ?? 'unknown'; $sshEnabled = $sshEnabled ?? false; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">SSH access</h2></div>
  <div style="padding:16px">
    <?php $sshMode = $sshMode ?? ($sshEnabled ? 'full' : 'off'); ?>
    <p>Login shell: <code><?= e($shell) ?></code>
      <?php if ($sshMode === 'jailed'): ?><span class="status status-active">Jailed (chroot)</span>
      <?php elseif ($sshMode === 'full'): ?><span class="status status-active">Full access (legacy)</span>
      <?php elseif ($sshMode === 'unknown'): ?><span class="status status-disabled">Unknown (agent unreachable)</span>
      <?php else: ?><span class="status status-disabled">Disabled</span><?php endif; ?></p>
    <p class="text-muted">SSH and FTP share one system password (both authenticate against the system user). Mailbox and database passwords are separate, on their own pages.</p>
    <form method="post" action="<?= e(url('panel/security/ssh/shell')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <div class="field">
        <label class="check"><input type="radio" name="mode" value="jailed" <?= $sshMode === 'jailed' ? 'checked' : '' ?>> <span>Jailed <span class="text-muted">— chroot to own files only</span></span></label>
        <label class="check"><input type="radio" name="mode" value="off" <?= $sshMode === 'off' ? 'checked' : '' ?>> <span>Disabled <span class="text-muted">— no SSH logins</span></span></label>
      </div>
      <?php if ($sshMode === 'off'): ?>
      <div class="field">
        <label class="field-label" for="ssh_password">SSH password <span class="text-muted">*</span></label>
        <input class="input" type="password" id="ssh_password" name="password" autocomplete="new-password">
        <p class="field-help">Required to enable access — the system password is never shown, so it must be (re-)entered every time SSH is switched on. Minimum <?= e(config('auth.min_password_length', 10)) ?> characters.</p>
      </div>
      <div class="field">
        <label class="field-label" for="ssh_password_confirmation">Confirm SSH password <span class="text-muted">*</span></label>
        <input class="input" type="password" id="ssh_password_confirmation" name="password_confirmation" autocomplete="new-password">
      </div>
      <?php endif; ?>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update SSH access</button>
      </div>
    </form>
  </div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>#</th><th>Type</th><th>Comment</th><th>Fingerprint</th><th class="actions">Actions</th></tr></thead>
    <tbody>
      <?php if (empty($keys)): ?>
        <?= empty_state(5, 'No authorized keys.') ?>
      <?php else: ?>
        <?php foreach ($keys as $k): ?>
          <tr>
            <td><?= e((string) ($k['index'] + 1)) ?></td>
            <td><code><?= e($k['type']) ?></code></td>
            <td><?= e($k['comment'] !== '' ? $k['comment'] : '—') ?></td>
            <td><code><?= e($k['fingerprint']) ?></code></td>
            <td class="actions">
              <form method="post" action="<?= e(url('panel/security/ssh/keys/' . $k['index'] . '/delete')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove this key?">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table></div>
  <form method="post" action="<?= e(url('panel/security/ssh/keys')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <h3 class="panel-title">Add public key</h3>
    <div class="field">
      <label class="field-label" for="key">Public key (ssh-ed25519 / ssh-rsa / ecdsa…)</label>
      <textarea class="input" id="key" name="key" rows="3" required placeholder="ssh-ed25519 AAAA… you@host" style="font-family:monospace"></textarea>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Add key</button>
    </div>
  </form>
</div>

<div class="panel" style="margin-top:16px">
  <div class="panel-header"><h2 class="panel-title">SSH password</h2></div>
  <form method="post" action="<?= e(url('panel/security/ssh/password')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <p class="text-muted">Same credential as FTP (one system password for both). Mailbox and database passwords are separate.</p>
    <div class="field">
      <label class="field-label" for="ssh_current_password">Current panel password</label>
      <input class="input" type="password" id="ssh_current_password" name="current_password" required autocomplete="current-password">
      <p class="field-help">Confirms it is really you (the system password itself can never be shown).</p>
    </div>
    <div class="field">
      <label class="field-label" for="ssh_new_password">New system password</label>
      <input class="input" type="password" id="ssh_new_password" name="password" required minlength="<?= e(config('auth.min_password_length', 10)) ?>" autocomplete="new-password">
      <p class="field-help">Minimum <?= e(config('auth.min_password_length', 10)) ?> characters.</p>
    </div>
    <div class="field">
      <label class="field-label" for="ssh_new_password_confirmation">Confirm new system password</label>
      <input class="input" type="password" id="ssh_new_password_confirmation" name="password_confirmation" required autocomplete="new-password">
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary" data-confirm="Change your system password?">Change system password</button>
    </div>
  </form>
</div>
