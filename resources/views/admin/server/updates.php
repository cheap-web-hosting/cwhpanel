<?php
$mode = $mode ?? 'off';
$status = $status ?? [];
$rootForward = $rootForward ?? null;
$agentError = $agentError ?? null;
?>
<?php if ($agentError !== null): ?>
  <div class="flash flash-error" role="alert"><?= e($agentError) ?></div>
<?php endif; ?>

<?php $panelManagerBlocked = $panelManagerBlocked ?? false; ?>
<?php if ($panelManagerBlocked): ?>
  <div class="alert alert-warning" role="alert">
    <strong>Updates are blocked by the Panel Manager.</strong> This server's
    hostname was flagged to refuse all future updates. Automatic and manual
    update actions are disabled until the dashboard re-enables updates.
    <?php if (in_array($panelManagerLicenseStatus ?? '', ['invalid', 'expired', 'revoked'], true)): ?>
      Reason: <strong>license <?= e($panelManagerLicenseStatus) ?></strong>
      (<?= e($panelManagerLicenseReason) ?>). Check the license in your panel
      configuration and on the dashboard.
    <?php endif; ?>
    <?php if (($s = $panelManagerStatus ?? null) !== null && !empty($s['at'])): ?>
      (last policy sync <?= e((string) $s['at']) ?> UTC)
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (($reboot = $reboot ?? null) !== null): ?>
  <div class="panel">
    <div class="panel-header"><h2 class="panel-title">Reboot status</h2></div>
    <div style="padding:16px">
      <?php if (!empty($reboot['required'])): ?>
        <p style="margin:0">
          <span class="alert alert-warning" role="alert">
            A reboot is required for the latest updates to take effect. Running
            <code><?= e((string) $reboot['running_kernel']) ?></code> — newest
            installed <code><?= e((string) $reboot['newest_installed_kernel']) ?></code>.
            <?php if (!empty($reboot['detail'])): ?>
              Detail: <?= e((string) $reboot['detail']) ?>
            <?php endif; ?>
          </span>
        </p>
        <form method="post" action="<?= e(url('admin/server/updates/reboot')) ?>" style="margin-top:12px">
          <?= csrf_field() ?>
          <label class="check" style="margin:0 0 10px">
            <input type="checkbox" name="confirm_reboot" value="1" required>
            <span>I understand this restarts the server and disconnects every session.</span>
          </label><br>
          <button type="submit" class="btn btn-sm btn-primary">Reboot now</button>
        </form>
      <?php else: ?>
        <p style="margin:0;color:#666">No reboot required — the running kernel is current.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Automatic updates</h2></div>
  <div style="padding:16px">
    <p style="margin-top:0;color:#666">
      System packages are upgraded from a managed cron job
      (<code>/etc/cron.d/cwhpanel-updates</code>, daily at 03:30). The job's output is
      mailed to <code>root</code>; forward root mail below to receive it off-box.
    </p>
    <form method="post" action="<?= e(url('admin/server/updates/mode')) ?>" class="inline-form" style="margin-bottom:8px">
      <?= csrf_field() ?>
      <label for="mode" style="margin-right:8px;font-weight:600">Mode</label>
      <select name="mode" id="mode">
        <option value="off" <?= $mode === 'off' ? 'selected' : '' ?>>Off</option>
        <option value="security" <?= $mode === 'security' ? 'selected' : '' ?>>Install security updates automatically</option>
        <option value="all" <?= $mode === 'all' ? 'selected' : '' ?>>Install all updates automatically</option>
      </select>
      <button type="submit" class="btn btn-sm btn-primary">Save</button>
    </form>
    <p style="margin:0;color:#666">
      Current mode:
      <strong><?= e($mode === 'security' ? 'Security updates only' : ($mode === 'all' ? 'All updates' : 'Off')) ?></strong>
    </p>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Panel version</h2></div>
  <div style="padding:16px">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center">
      <div>
        <div style="font-size:12px;color:#6b7280">Installed</div>
        <div style="font-size:20px;font-weight:600"><?= e($panelVersion ?? 'unknown') ?></div>
      </div>
      <div>
        <div style="font-size:12px;color:#6b7280">Latest release</div>
        <div style="font-size:20px;font-weight:600">
          <?php if (($panelUpdate['latest'] ?? null) !== null): ?>
            <?= e('v' . $panelUpdate['latest']) ?>
            <?php if (!empty($panelUpdate['up_to_date'])): ?>
              <span class="status status-active">up to date</span>
            <?php else: ?>
              <span class="status status-active" style="background:#f59e0b">update available</span>
            <?php endif; ?>
          <?php elseif (!empty($panelUpdate['checked_at'])): ?>
            <span style="color:#9ca3af;font-size:14px">no releases found</span>
          <?php else: ?>
            <span style="color:#9ca3af">unchecked</span>
          <?php endif; ?>
        </div>
      </div>
      <div style="color:#6b7280;font-size:13px">
        <?php if (($panelUpdate['checked_at'] ?? null) !== null): ?>
          Last checked <?= e(date('Y-m-d H:i', (int) $panelUpdate['checked_at'])) ?>
        <?php endif; ?>
        <?php if (!empty($panelUpdate['remote'])): ?>
          · upstream <code><?= e($panelUpdate['remote']) ?></code>
        <?php endif; ?>
      </div>
      <form method="post" action="<?= e(url('admin/server/updates/panel-check')) ?>" style="display:inline;margin:0">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-primary">Check now</button>
      </form>
      <?php if (!empty($panelUpdate['latest']) && empty($panelUpdate['up_to_date'])): ?>
        <form method="post" action="<?= e(url('admin/server/updates/panel-apply')) ?>" style="display:inline;margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="version" value="<?= e($panelUpdate['latest']) ?>">
          <button type="submit" class="btn btn-sm" style="color:#7a2e0e;border-color:#d97706" data-confirm="Apply panel update to v<?= e($panelUpdate['latest']) ?>? The panel will reload briefly — sessions are preserved.">Apply update</button>
        </form>
      <?php endif; ?>
    </div>
    <div style="margin-top:12px;border-top:1px solid #e5e7eb;padding-top:12px;display:flex;flex-wrap:wrap;gap:12px;align-items:center">
      <form method="post" action="<?= e(url('admin/server/updates/panel-settings')) ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0">
        <?= csrf_field() ?>
        <label for="panel_update_mode" style="font-weight:600;font-size:13px">Auto-update</label>
        <select name="mode" id="panel_update_mode" class="input" style="width:auto">
          <option value="off" <?= $panelUpdateMode === 'off' ? 'selected' : '' ?>>Off</option>
          <option value="notify" <?= $panelUpdateMode === 'notify' ? 'selected' : '' ?>>Notify only</option>
          <option value="auto" <?= $panelUpdateMode === 'auto' ? 'selected' : '' ?>>Auto-apply</option>
        </select>
        <button type="submit" class="btn btn-sm">Save</button>
      </form>
      <span style="font-size:12px;color:#6b7280">
        <?php if ($panelUpdateMode === 'auto'): ?>
          New releases install automatically at the daily check (04:17), with backup + automatic rollback. Releases that add database migrations are held for manual review.
        <?php elseif ($panelUpdateMode === 'off'): ?>
          No automatic checks/notifications; use Check now manually.
        <?php else: ?>
          Checks daily; notifies when a new release appears (no auto-install).
        <?php endif; ?>
      </span>
    </div>
    <p style="margin:10px 0 0;color:#6b7280;font-size:13px">
      The panel's own code is version-controlled separately from system packages (above).
      Checking here queries the release feed for this panel. Applying swaps the code tree
      (with a backup + automatic rollback if the panel fails its health check) and runs
      database migrations.
    </p>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Pending updates</h2></div>
  <div style="padding:16px">
    <?php if (empty($status['checked_at'])): ?>
      <p style="margin-top:0;color:#666">No check has run yet.</p>
    <?php else: ?>
      <p style="margin-top:0">
        <span class="status <?= ((int) ($status['total'] ?? 0)) > 0 ? 'status-active' : 'status-disabled' ?>">
          <?= (int) ($status['total'] ?? 0) ?> package(s)
        </span>
        pending
        <?php if ((int) ($status['security'] ?? 0) > 0): ?>
          — <strong><?= (int) $status['security'] ?> security</strong>
        <?php endif; ?>
      </p>
      <p style="color:#666;margin:0 0 8px">Last checked: <?= e((string) $status['checked_at']) ?></p>
      <?php if (!empty($status['packages'])): ?>
        <details style="margin-bottom:8px">
          <summary style="cursor:pointer">Show packages</summary>
          <p style="font-family:monospace;color:#333;margin:8px 0 0">
            <?= e(implode(', ', array_map('strval', (array) $status['packages']))) ?><?= count((array) $status['packages']) >= 25 ? ', …' : '' ?>
          </p>
        </details>
      <?php endif; ?>
    <?php endif; ?>
    <form method="post" action="<?= e(url('admin/server/updates/check')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-sm">Check for updates</button>
    </form>
    <form method="post" action="<?= e(url('admin/server/updates/apply')) ?>" class="inline-form" style="margin-top:8px" data-confirm="Install <?= e($mode === 'all' ? 'all' : 'security') ?> updates now? This runs dnf immediately.">
      <?= csrf_field() ?>
      <label for="apply-mode" style="margin-right:8px;font-weight:600">Install now</label>
      <select name="mode" id="apply-mode">
        <option value="security" <?= $mode === 'security' ? 'selected' : '' ?>>Security updates</option>
        <option value="all" <?= $mode === 'all' ? 'selected' : '' ?>>All updates</option>
      </select>
      <button type="submit" class="btn btn-sm btn-primary">Install updates</button>
    </form>
    <p style="color:#666;margin:8px 0 0">Install runs in the background (queued job); refresh this page in a moment for the result.</p>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Recent update runs</h2></div>
  <div style="padding:16px">
    <?php $runs = $runs ?? []; ?>
    <?php if ($runs === []): ?>
      <p style="margin:0;color:#666">No update runs yet.</p>
    <?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>#</th><th>Type</th><th>Status</th><th>Queued</th><th>Result</th></tr></thead>
        <tbody>
          <?php foreach ($runs as $run): ?>
            <tr>
              <td class="nowrap"><?= (int) $run['id'] ?></td>
              <td><code><?= e($run['type']) ?></code></td>
              <td>
                <span class="status status-<?= $run['status'] === 'completed' ? 'active' : ($run['status'] === 'failed' ? 'error' : 'disabled') ?>">
                  <?= e($run['status']) ?>
                </span>
              </td>
              <td class="nowrap"><?= e($run['created_at']) ?></td>
              <td style="max-width:480px"><small style="white-space:pre-wrap;word-break:break-word;color:#666"><?= e(mb_strimwidth($run['result'], 0, 400, '…')) ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Root email forwarding</h2></div>
  <div style="padding:16px">
    <p style="margin-top:0;color:#666">
      Forward mail addressed to <code>root</code> (cron output, update reports, system alerts)
      to an external address. Leave empty to deliver to the local root mailbox instead.
    </p>
    <form method="post" action="<?= e(url('admin/server/updates/root-forward')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <input type="email" name="email" value="<?= e((string) ($rootForward ?? '')) ?>"
             placeholder="<?= e($hostname !== '' ? 'admin@' . $hostname : 'you@example.com') ?>" style="min-width:280px">
      <button type="submit" class="btn btn-sm btn-primary">Save</button>
    </form>
    <p style="margin:8px 0 0;color:#666">
      Current:
      <strong><?= $rootForward !== null ? e((string) $rootForward) : 'Local root mailbox' ?></strong>
    </p>
  </div>
</div>