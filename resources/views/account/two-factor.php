<?php $enabled = $enabled ?? false; $secret = $secret ?? null; $otpauth = $otpauth ?? null; $area = $area ?? 'customer'; ?>
<?php $prefix = $area === 'admin' ? 'admin' : 'panel'; ?>

<div class="cards-grid">
  <div class="card">
    <div class="card-label">Status</div>
    <div class="card-value"><?= $enabled ? '<span class="status status-active">Enabled</span>' : '<span class="status status-disabled">Disabled</span>' ?></div>
  </div>
</div>

<?php if (!$enabled): ?>
  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">Enable two-factor authentication</h2></div>
    <div style="padding:16px">
      <p>Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, …).</p>
      <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap">
        <div data-otpauth="<?= e($otpauth ?? '') ?>">
          <canvas width="220" height="220"></canvas>
        </div>
        <div style="max-width:360px;flex:1;min-width:240px">
          <p class="text-muted" style="margin:0 0 6px">Can’t scan? Add the account manually using this secret key:</p>
          <div class="card" style="margin:0 0 8px;font-family:monospace;letter-spacing:2px;word-break:break-all" id="totp-secret"><?= e(trim(chunk_split($secret ?? '', 4, ' '))) ?></div>
          <button type="button" class="btn btn-sm" data-copy-target="totp-secret">Copy key</button>
        </div>
      </div>
      <form method="post" action="<?= e(url($prefix . '/account/2fa/enable')) ?>" class="form-stack" style="margin-top:16px">
        <?= csrf_field() ?>
        <div class="field">
          <label class="field-label" for="code">Enter the 6-digit code to confirm</label>
          <input class="input" type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn btn-primary">Enable</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">Disable two-factor authentication</h2></div>
    <div style="padding:16px">
      <form method="post" action="<?= e(url($prefix . '/account/2fa/disable')) ?>" class="form-stack">
        <?= csrf_field() ?>
        <div class="field">
          <label class="field-label" for="code">Enter a current code to confirm</label>
          <input class="input" type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn btn-danger" data-confirm="Disable two-factor authentication?">Disable</button>
      </form>
    </div>
  </div>
<?php endif; ?>