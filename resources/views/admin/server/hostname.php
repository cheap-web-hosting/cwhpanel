<?php
$current = (string) ($current ?? '');
$domain = (string) ($domain ?? '');
$dns = $dns ?? ['state' => 'unknown', 'addrs' => []];
$cert = $cert ?? null;
$status = $status ?? null;

$dnsLabel = [
    'direct' => ['is-warning-does-not-exist', 'resolves to this server'],
    'remote' => ['status-disabled', 'resolves elsewhere (CDN/proxy?)'],
    'none' => ['status-disabled', 'does not resolve (Let\'s Encrypt will fail)'],
    'unknown' => ['status-disabled', 'unknown'],
][$dns['state']] ?? ['status-disabled', $dns['state']];
?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Server hostname</h2></div>
  <div style="padding:16px">
    <p style="margin-top:0">
      Current hostname: <strong><?= e($current) ?></strong>
      <span style="color:#666">(domain <code><?= e($domain) ?></code>)</span>
    </p>
    <p style="margin:0 0 4px">
      DNS:
      <?php if (in_array($dns['state'], ['direct'], true)): ?>
        <span class="status status-active">resolves to this server</span>
      <?php else: ?>
        <span class="status status-disabled"><?= e($dnsLabel[1]) ?></span>
      <?php endif; ?>
      <?php if (!empty($dns['addrs'])): ?>
        <code style="margin-left:6px"><?= e(implode(', ', (array) $dns['addrs'])) ?></code>
      <?php endif; ?>
    </p>
    <p style="margin:0 0 8px">
      Certificate:
      <?php if ($cert !== null): ?>
        CN=<code><?= e((string) $cert['cn']) ?></code>,
        expires in <?= (int) $cert['days'] ?> days
        <?php if (!empty($cert['sans'])): ?> (SANs: <code><?= e(implode(', ', (array) $cert['sans'])) ?></code>)<?php endif; ?>
      <?php else: ?>
        <span class="status status-disabled">none installed</span>
      <?php endif; ?>
    </p>
    <form method="post" action="<?= e(url('admin/server/hostname/reprovision')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-sm">Re-run provisioning (DNS + TLS)</button>
    </form>
    <p style="color:#666;margin:8px 0 0">Re-applies /etc/hosts, the local DNS zone (A/AAAA, MX, SPF, DKIM, DMARC), the mail identity and the TLS certificate for the current hostname.</p>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Change hostname</h2></div>
  <div style="padding:16px">
    <p style="margin-top:0;color:#666">
      Changing the hostname cascades through the system hostname, <code>/etc/hosts</code>, the local DNS zone
      (including SPF/DKIM/DMARC), the postfix mail identity, the panel vhost and a fresh Let's Encrypt certificate —
      which also becomes the certificate for nginx, postfix (587/465), dovecot (143/993) and vsftpd.
    </p>
    <p style="margin:0 0 10px;color:#8a6d3b;background:#fcf8e3;border:1px solid #faebcc;padding:8px;border-radius:4px">
      Before changing: point the new hostname's DNS at this server. Let's Encrypt can only issue the certificate once it resolves here
      (you can change now and re-run provisioning later — the page will show the failure).
    </p>
    <form method="post" action="<?= e(url('admin/server/hostname/change')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <input type="text" name="fqdn" value="<?= e($current) ?>" placeholder="panel.example.com" style="min-width:280px">
      <button type="submit" class="btn btn-sm btn-danger"
              data-confirm="Change the server hostname and re-issue the certificate? This briefly reloads nginx, postfix, dovecot and restarts vsftpd.">Change hostname</button>
    </form>
  </div>
</div>

<?php if (is_array($status) && !empty($status['steps'])): ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Last change (<?= e((string) ($status['fqdn'] ?? '')) ?><?= isset($status['at']) ? ' — ' . e((string) $status['at']) : '' ?>)</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Step</th><th>Result</th><th>Detail</th></tr></thead>
    <tbody>
      <?php foreach ($status['steps'] as $s): ?>
        <tr>
          <td><?= e((string) $s['step']) ?></td>
          <td><?= !empty($s['ok']) ? '<span class="status status-active">ok</span>' : '<span class="status status-disabled">failed</span>' ?></td>
          <td style="color:#666"><?= e((string) $s['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>