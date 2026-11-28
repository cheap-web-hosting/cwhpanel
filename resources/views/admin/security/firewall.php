<?php
$rules = $rules ?? [];
$attacks = $attacks ?? [];
$ports = $ports ?? ['ports' => [], 'services' => [], 'rich' => []];
$openPorts = $ports['ports'] ?? [];
$services = $ports['services'] ?? [];
$richRules = $ports['rich'] ?? [];
$mysqlRemote = $mysqlRemote ?? false;
$mysqlHosts = $mysqlHosts ?? [];
$botShield = $botShield ?? ['enabled' => false, 'threshold' => 8, 'window' => 600];
$botShieldBlocks = $botShieldBlocks ?? [];
$botShieldLastSweep = $botShieldLastSweep ?? null;
$stateError = $stateError ?? '';
?>
<?php if ($stateError !== ''): ?>
  <div class="alert alert-warning">⚠ Could not read live firewall state from firewalld (agent error): <code><?= e($stateError) ?></code>. The lists below may be incomplete.</div>
<?php endif; ?>

<h2 class="section-title">Open ports</h2>
<div class="cards-grid">
  <div class="card"><div class="card-label">Open TCP/UDP ports</div><div class="card-value"><?= count($openPorts) ?></div></div>
  <div class="card"><div class="card-label">Enabled services</div><div class="card-value"><?= count($services) ?></div></div>
  <div class="card"><div class="card-label">Rich rules</div><div class="card-value"><?= count($richRules) ?></div></div>
  <div class="card"><div class="card-label">Blocked IPs</div><div class="card-value"><?= count($rules) ?></div></div>
</div>

<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('admin/security/firewall/open-port')) ?>" class="filter-bar" style="border-bottom:0;padding:0"><?= csrf_field() ?>
    <input class="input" type="number" name="port" placeholder="Port" min="1" max="65535" style="max-width:110px" required>
    <select class="input" name="protocol" style="max-width:110px">
      <option value="tcp">TCP</option>
      <option value="udp">UDP</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Open port</button>
  </form>

  <form method="post" action="<?= e(url('admin/security/firewall/close-port')) ?>" class="filter-bar" style="border-bottom:0;padding:0;margin-top:8px"><?= csrf_field() ?>
    <input class="input" type="number" name="port" placeholder="Port" min="1" max="65535" style="max-width:110px" required>
    <select class="input" name="protocol" style="max-width:110px">
      <option value="tcp">TCP</option>
      <option value="udp">UDP</option>
    </select>
    <button type="submit" class="btn btn-danger btn-sm">Close port</button>
  </form>
</div>

<div class="table-wrap"><table class="table">
  <thead><tr><th>Port</th><th>Protocol</th><th>Type</th></tr></thead>
  <tbody>
    <?php
    $rows = [];
    foreach ($openPorts as $p) {
        $port = $p; $proto = 'tcp';
        if (str_contains($p, '/')) { [$port, $proto] = explode('/', $p, 2); }
        $rows[] = ['label' => $port, 'proto' => strtoupper($proto), 'type' => 'Port'];
    }
    foreach ($services as $s) {
        $rows[] = ['label' => $s, 'proto' => '—', 'type' => 'Service'];
    }
    if (empty($rows)): ?>
      <?= empty_state(3, 'No ports or services reported.') ?>
    <?php else: foreach ($rows as $r): ?>
      <tr><td><code><?= e($r['label']) ?></code></td><td><?= e($r['proto']) ?></td><td><?= e($r['type']) ?></td></tr>
    <?php endforeach; endif; ?>
  </tbody>
</table></div>

<h2 class="section-title">Remote MySQL access</h2>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('admin/security/firewall/mysql-remote')) ?>" class="form-stack" style="margin:0"><?= csrf_field() ?>
    <input type="hidden" name="enabled" value="0">
    <label class="check"><input type="checkbox" name="enabled" value="1" <?= $mysqlRemote ? 'checked' : '' ?>> <span>Allow remote MySQL (open 3306/tcp in the firewall)</span></label>
    <p class="field-help" style="margin:0">Off (default) = MariaDB reachable from localhost only. Per-host DB grants are refused while off. On = 3306/tcp opened zone-wide; per-host grants + per-IP 3306 rich rules still apply on top. Turning off closes the port but keeps existing grants, so re-enabling restores them.</p>
    <?php if (!empty($mysqlHosts)): ?>
      <p class="field-help" style="margin:8px 0 0">Active remote grants (up to 100): <?= e(implode(', ', array_map(fn($h) => ($h['username'] ?? '?') . '@' . ($h['host'] ?? '?'), $mysqlHosts))) ?></p>
    <?php endif; ?>
    <div class="form-actions"><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
  </form>
</div>


<h2 class="section-title">Blocked IPs</h2>
<div class="panel" style="padding:16px"><form method="post" action="<?= e(url('admin/security/firewall/block')) ?>" class="filter-bar" style="border-bottom:0;padding:0;flex-wrap:wrap;gap:10px" data-confirm="Block this IP address?"><?= csrf_field() ?>
<input class="input" type="text" name="ip" placeholder="IP address" style="min-width:160px" required>
<input class="input" type="text" name="service" placeholder="Service (e.g. ssh, smtp)" style="min-width:160px">
<input class="input" type="text" name="reason" placeholder="Reason (optional)" style="min-width:180px">
<select class="input" name="scope" id="fw-scope" style="min-width:220px">
  <option value="all">All websites (host firewall)</option>
  <option value="websites">Selected websites only (nginx)</option>
</select>
<select class="input" name="website_ids[]" id="fw-sites" multiple size="6" style="min-width:260px;display:none">
  <?php foreach (($websites ?? []) as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
</select>
<button type="submit" class="btn btn-danger">Block IP</button>
</form>
<p class="field-help" style="margin:8px 0 0">"All websites" installs a host-wide firewalld rule (affects every tenant, use for real attackers). "Selected websites" adds an nginx <code>deny</code> to those vhosts only.</p>
</div>
<script>
(function(){
  var s=document.getElementById('fw-scope'), w=document.getElementById('fw-sites');
  if(!s||!w) return;
  function sync(){ w.style.display = s.value==='websites' ? '' : 'none'; }
  s.addEventListener('change', sync); sync();
})();
</script>
<div class="table-wrap"><table class="table"><thead><tr><th>IP</th><th>Action</th><th>Reason</th><th>When</th><th></th></tr></thead>
<tbody><?php if (empty($rules)): ?><?= empty_state(5, 'No blocked IPs.') ?>
<?php else: foreach ($rules as $rule): ?><tr><td><code><?= e($rule['ip_address']) ?></code></td><td><span class="badge badge-warning"><?= e($rule['action']) ?></span></td><td><?= e($rule['reason'] ?? '—') ?></td><td class="nowrap"><?= e($rule['created_at']) ?></td>
<td class="actions"><form method="post" action="<?= e(url('admin/security/firewall/unblock/'.$rule['id'])) ?>" class="inline-form" data-confirm="Unblock this IP address?"><?= csrf_field() ?><button type="submit" class="btn btn-sm">Unblock</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div>

<?php if (($siteDeniesError ?? '') !== ''): ?>
<div class="alert alert-error" style="margin:12px 0">Website blocks could not be read: <?= e($siteDeniesError) ?></div>
<?php endif; ?>
<h2 class="section-title">Website-scoped blocks</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>IP</th><th>Website</th><th>Source</th><th>Reason</th><th>When</th><th></th></tr></thead>
<tbody><?php if (empty($siteDenies)): ?><?= empty_state(6, 'No website-scoped blocks.') ?>
<?php else: foreach ($siteDenies as $d): ?><tr><td><code><?= e($d['ip_address']) ?></code></td><td><?= e($d['domain']) ?></td><td><span class="badge <?= $d['source'] === 'admin' ? 'badge-warning' : '' ?>"><?= e($d['source']) ?></span></td><td><?= e($d['reason'] ?? '—') ?></td><td class="nowrap"><?= e($d['created_at']) ?></td>
<td class="actions"><form method="post" action="<?= e(url('admin/security/firewall/unblock-site/'.$d['id'])) ?>" class="inline-form" data-confirm="Remove this website block?"><?= csrf_field() ?><button type="submit" class="btn btn-sm">Unblock</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div>

<h2 class="section-title">Recently blocked attacks</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>Source IP</th><th>Service</th><th>Action</th><th>Reason</th><th>When</th></tr></thead>
<tbody><?php if (empty($attacks)): ?><?= empty_state(5, 'No blocked attacks recorded.') ?>
<?php else: foreach ($attacks as $a): ?><tr><td><code><?= e($a['source_ip']) ?></code></td><td><?= e($a['service']) ?></td><td><span class="badge badge-warning"><?= e($a['action']) ?></span></td><td><?= e($a['reason'] ?? '—') ?></td><td class="nowrap"><?= e($a['created_at']) ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
