<?php
$currentPort = $currentPort ?? 22;
$defaultPort = $defaultPort ?? 22;
$readError = $readError ?? null;
?>
<?php if ($readError !== null): ?>
  <div class="alert alert-warning">⚠ Could not read the current sshd port (agent error): <code><?= e($readError) ?></code> — showing the default. The change form still works, but verify the live port before relying on it.</div>
<?php endif; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">SSH server port</h2></div>
  <div class="panel-body" style="padding:16px">
    <p class="text-muted" style="margin-top:0">
      The panel changes the server-wide sshd <code>Port</code> and reloads after
      validating with <code>sshd -t</code> — a bad value never locks access out.
      Always keep at least one reachable path (firewall + network) before closing
      the old port.
    </p>
    <div class="stat" style="margin-bottom:16px"><span class="stat-label">Current SSH port</span><span class="stat-value"><?= e($currentPort) ?></span></div>
    <form method="post" action="<?= e(url('admin/server/ssh/port')) ?>" class="form-stack" style="max-width:420px">
      <?= csrf_field() ?>
      <div class="field">
        <label class="field-label" for="port">New SSH port</label>
        <input class="input" type="number" id="port" name="port" min="1024" max="65535" required placeholder="1024–65535">
        <p class="field-help">Use 1024–65535 (privileged ports &lt; 1024 are refused). Default is 22.</p>
      </div>
      <label class="check" style="margin:8px 0 16px">
        <input type="checkbox" name="keep_old" value="1" checked>
        <span>Keep the previous port open in the firewall (recommended until the new port is verified)</span>
      </label>
      <button type="submit" class="btn btn-primary" data-confirm="Change the SSH port and reload sshd? Verify firewall access before closing the old port.">Change SSH port</button>
    </form>
  </div>
</div>
