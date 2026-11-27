<?php $website = $website ?? []; $privacy = $privacy ?? []; $wid = (int) ($website['id'] ?? 0); $logs = $logs ?? null; $isolated = $isolated ?? false; $ipEnabled = $ipEnabled ?? false; $sharedIp = $sharedIp ?? ''; $accountIpv6 = $accountIpv6 ?? ''; ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Label</div><div class="card-value"><?= e($website['label']) ?></div></div>
  <div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($website['domain_name'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">PHP</div><div class="card-value"><?= e($website['php_version'] ?? '—') ?></div></div>
  <div class="card"><div class="card-label">Document root</div><div class="card-value"><code><?= e($website['document_root'] ?? '—') ?></code></div></div>
  <?php if ($ipEnabled): ?>
  <div class="card"><div class="card-label">IPv6</div><div class="card-value"><?= $accountIpv6 !== '' ? '<code>' . e($accountIpv6) . '</code>' : '—' ?></div></div>
  <div class="card"><div class="card-label">IPv4</div><div class="card-value"><?= trim((string) ($website['ipv4_address'] ?? '')) !== '' ? '<code>' . e($website['ipv4_address']) . '</code> <span class="text-muted">(dedicated)</span>' : '<code>' . e($sharedIp) . '</code> <span class="text-muted">(shared)</span>' ?></div></div>
  <?php endif; ?>
</div>

<div class="panel" style="padding:16px">
  <div class="form-actions">
    <a href="<?= e(url('panel/websites/' . $website['id'] . '/php')) ?>" class="btn btn-primary">PHP Settings</a>
    <a href="<?= e(url('panel/websites/' . $website['id'] . '/nginx')) ?>" class="btn">View Nginx config</a>
    <form method="post" action="<?= e(url('panel/websites/' . $website['id'] . '/delete')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-danger" data-confirm="Delete website <?= e($website['label']) ?>?">Delete</button>
    </form>
    <form method="post" action="<?= e(url('panel/websites/' . $website['id'] . '/maintenance')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="enabled" value="<?= empty($website['maintenance_mode']) ? '1' : '0' ?>">
      <button type="submit" class="btn" data-confirm="<?= empty($website['maintenance_mode']) ? 'Take site offline (503 for all requests)?' : 'Bring site back online?' ?>">
        <?= empty($website['maintenance_mode']) ? 'Maintenance on' : 'Maintenance off' ?>
      </button>
    </form>
    <?php if (!empty($website['maintenance_mode'])): ?>
      <span class="status status-disabled">In maintenance (503)</span>
    <?php endif; ?>
    <?php if (!empty($website['suspended_bandwidth'])): ?>
      <span class="status status-error">Suspended: bandwidth overage — contact your administrator</span>
    <?php endif; ?>
    <?php if ($isolated): ?>
      <form method="post" action="<?= e(url('panel/websites/' . $website['id'] . '/worker/restart')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn" data-confirm="Restart this website's dedicated web server?">Restart web server</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php $hasCert = $has_cert ?? false; ?>
<h2 class="section-title">HTTPS</h2>
<div class="panel" style="padding:16px;margin-bottom:12px">
  <?php if ($hasCert): ?>
    <p><span class="status status-ok">Certificate installed — site serves TLS on 443</span></p>
  <?php else: ?>
    <p class="muted">No certificate installed for this domain yet — ask your administrator to issue one; the 443 listener appears automatically.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('panel/websites/' . $website['id'] . '/https')) ?>" class="stack">
    <?= csrf_field() ?>
    <?php if (!$hasCert): ?><input type="hidden" name="force_https" value="0"><?php endif; ?>
    <label class="check">
      <input type="checkbox" name="force_https" value="1" <?= !empty($website['force_https']) ? 'checked' : '' ?> <?= $hasCert ? '' : 'disabled' ?>>
      <span>Force HTTPS — redirect all cleartext traffic to https (ACME challenge path stays exempt)</span>
    </label>
    <label>HSTS max-age (seconds, 0 = off)
      <input type="number" name="hsts_max_age" min="0" max="63072000" step="1" value="<?= (int) ($website['hsts_max_age'] ?? 0) ?>">
    </label>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save HTTPS settings</button></div>
  </form>
</div>

<h2 class="section-title">Bot shield (rate limiting)</h2>
<div class="panel" style="padding:16px;margin-bottom:12px">
  <?php $rl = $rateLimit ?? ['enabled' => false, 'rps' => 20, 'burst' => 40, 'inherited' => true]; $rs = $rateStats ?? null; ?>
  <?php if (!empty($website['rate_limit_enabled'])): ?>
    <p><span class="status status-active">Shield on (<?= (int) $rl['rps'] ?>/s, burst <?= (int) $rl['burst'] ?>)</span></p>
  <?php else: ?>
    <p><span class="status status-disabled">Shield off</span></p>
  <?php endif; ?>
  <p class="text-muted">Throttles bots and abusive clients<?= $isolated ? ' at the front-door proxy (real client IP)' : '' ?>. Throttled requests answer <code>429</code>.
    <?= !empty($rl['inherited']) ? 'Using the <strong>package default</strong> — leave a field empty to keep inheriting it.' : 'Using <strong>per-site overrides</strong> — clear a field to inherit the package default again.' ?></p>
  <?php if ($rs !== null && (int) $rs['total_requests'] > 0): ?>
    <p>Throttled in recent logs: <strong><?= (int) $rs['throttled_429'] ?> × 429</strong> of <?= (int) $rs['total_requests'] ?> requests<?php if ((int) $rs['limited_events'] > 0): ?> (<?= (int) $rs['limited_events'] ?> limit events in error log)<?php endif; ?></p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('panel/websites/' . $wid . '/rate-limit')) ?>" class="filter-bar" style="border-bottom:0;padding:0;align-items:flex-end">
    <?= csrf_field() ?>
    <label class="check" style="margin:0"><input type="checkbox" name="enabled" value="1" <?= !empty($website['rate_limit_enabled']) ? 'checked' : '' ?>> <span>Enabled</span></label>
    <label class="field" style="margin:0"><span class="field-label">Requests/sec</span><input class="input" type="number" name="rate_limit_rps" min="0" max="1000" placeholder="<?= (int) $rl['rps'] ?>" value="<?= e((string) ($website['rate_limit_rps'] ?? '')) ?>"></label>
    <label class="field" style="margin:0"><span class="field-label">Burst</span><input class="input" type="number" name="rate_limit_burst" min="0" max="5000" placeholder="<?= (int) $rl['burst'] ?>" value="<?= e((string) ($website['rate_limit_burst'] ?? '')) ?>"></label>
    <label class="field" style="margin:0"><span class="field-label">Max connections</span><input class="input" type="number" name="max_connections" min="0" max="100000" placeholder="<?= (int) ($rl['connections'] ?? 0) ?>" value="<?= e((string) ($website['max_connections'] ?? '')) ?>"></label>
    <button type="submit" class="btn btn-primary btn-sm">Apply shield</button>
  </form>
</div>

<h2 class="section-title">Logs</h2>
<?php if ($logs === null): ?>
  <div class="panel" style="padding:16px"><p class="text-muted">Logs unavailable.</p></div>
<?php else: ?>
  <div class="panel" style="padding:16px;margin-bottom:12px">
    <h3 class="panel-title">Access log <span class="text-muted"><code><?= e($logs['accessFile'] ?? '') ?></code></span>
      <a class="btn btn-sm" href="<?= e(url('panel/websites/' . $wid . '/logs/download?which=access')) ?>">Download</a></h3>
    <pre style="margin:0;padding:12px;background:#111;color:#9f9;border-radius:6px;white-space:pre-wrap;word-break:break-word;font-size:12px;max-height:300px;overflow:auto"><?= e($logs['access'] !== '' ? $logs['access'] : '(empty)') ?></pre>
  </div>
  <div class="panel" style="padding:16px">
    <h3 class="panel-title">Error log <span class="text-muted"><code><?= e($logs['errorFile'] ?? '') ?></code></span>
      <a class="btn btn-sm" href="<?= e(url('panel/websites/' . $wid . '/logs/download?which=error')) ?>">Download</a></h3>
    <pre style="margin:0;padding:12px;background:#111;color:#9f9;border-radius:6px;white-space:pre-wrap;word-break:break-word;font-size:12px;max-height:300px;overflow:auto"><?= e($logs['error'] !== '' ? $logs['error'] : '(empty)') ?></pre>
  </div>
<?php endif; ?>

<h2 class="section-title">Custom nginx directives</h2>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('panel/websites/' . $wid . '/nginx-custom')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="custom_nginx">Directives (inside the server block)</label>
      <textarea class="input" id="custom_nginx" name="custom_nginx" rows="6" maxlength="8192" placeholder="location /static/ {&#10;    expires 30d;&#10;}" style="font-family:monospace"><?= e((string) ($website['custom_nginx'] ?? '')) ?></textarea>
      <p class="field-help">Validated before applying. Some directives (listen, include, …) are not allowed. On isolated accounts a bad snippet can only affect this account.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Apply directives</button>
    </div>
  </form>
</div>

<h2 class="section-title">Directory Privacy</h2>
<?php $leech = $leech ?? []; ?>
<?php if (!empty($leech)): ?>
<div class="panel" style="padding:16px;margin-bottom:12px;border-left:3px solid var(--danger,#c0392b)">
<h3 class="panel-title">Suspected password sharing</h3>
<div class="table-wrap"><table class="table">
<thead><tr><th>User</th><th>Directory</th><th>IPs (24h)</th><th>Hits</th><th class="actions">Actions</th></tr></thead>
<tbody>
<?php foreach ($leech as $s): ?>
<tr>
<td><code><?= e($s['username']) ?></code></td>
<td><code>/<?= e($s['path']) ?>/</code></td>
<td><?= count($s['ips']) ?> (limit <?= (int)$s['max'] ?>): <code><?= e(implode(', ', array_slice($s['ips'], 0, 5))) ?></code><?= count($s['ips']) > 5 ? '…' : '' ?></td>
<td><?= (int)$s['hits'] ?></td>
<td class="actions">
<?php if ($s['user_id'] !== null): ?>
<form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs/' . $s['dir_id'] . '/users/' . $s['user_id'] . '/suspend')) ?>" class="inline-form">
<?= csrf_field() ?>
<button type="submit" class="btn btn-sm btn-danger" data-confirm="Suspend <?= e($s['username']) ?>? Their password will stop working.">Suspend</button>
</form>
<?php else: ?>
<span class="text-muted">unknown user</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
<?php endif; ?>
<?php if (empty($privacy)): ?>
  <div class="panel" style="padding:16px"><p class="text-muted">No protected directories.</p></div>
<?php else: ?>
  <?php foreach ($privacy as $d): ?>
    <div class="panel" style="padding:16px;margin-bottom:12px">
      <h3 class="panel-title"><code>/<?= e($d['path']) ?>/</code> — <?= e($d['title']) ?>
        (<?= !empty($d['enabled']) ? 'protected' : 'disabled' ?>)</h3>
      <div class="form-actions" style="margin-bottom:8px">
        <form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs/' . $d['id'] . '/toggle')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="enabled" value="<?= empty($d['enabled']) ? '1' : '0' ?>">
          <button type="submit" class="btn btn-sm"><?= empty($d['enabled']) ? 'Enable' : 'Disable' ?></button>
        </form>
        <form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs/' . $d['id'] . '/delete')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove protection for /<?= e($d['path']) ?>/?">Remove</button>
        </form>
        <form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs/' . $d['id'] . '/leech')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <label class="field-label" for="leech-<?= (int)$d['id'] ?>" style="margin:0 4px 0 8px">Max logins/IPs (24h, empty = off)</label>
          <input class="input" type="number" id="leech-<?= (int)$d['id'] ?>" name="max_logins" min="1" max="1000" placeholder="off" value="<?= e((string)($d['max_logins'] ?? '')) ?>" style="width:90px">
          <button type="submit" class="btn btn-sm">Apply</button>
        </form>
      </div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>User</th><th class="actions">Actions</th></tr></thead>
        <tbody>
          <?php if (empty($d['users'])): ?>
            <?= empty_state(2, 'No users — directory is unreachable until a user is added.') ?>
          <?php else: ?>
            <?php foreach ($d['users'] as $u): ?>
              <tr>
                <td><code><?= e($u['username']) ?></code><?= !empty($u['suspended']) ? ' <span class="status status-disabled">suspended</span>' : '' ?></td>
                <td class="actions">
                  <?php if (!empty($u['suspended'])): ?>
                    <form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs/' . $d['id'] . '/users/' . $u['id'] . '/unsuspend')) ?>" class="inline-form">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-sm">Reinstate</button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs/' . $d['id'] . '/users/' . $u['id'] . '/suspend')) ?>" class="inline-form">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-sm" data-confirm="Suspend <?= e($u['username']) ?>?">Suspend</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs/' . $d['id'] . '/users/' . $u['id'] . '/delete')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove user <?= e($u['username']) ?>?">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table></div>
      <form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs/' . $d['id'] . '/users')) ?>" class="filter-bar" style="border-bottom:0;padding:12px 0 0">
        <?= csrf_field() ?>
        <label class="field" style="margin:0"><span class="field-label">Username</span><input class="input" type="text" name="username" required maxlength="64"></label>
        <label class="field" style="margin:0"><span class="field-label">Password</span><input class="input" type="password" autocomplete="new-password" name="password" required minlength="8" placeholder="Min 8 characters" title="Minimum 8 characters"></label>
        <button type="submit" class="btn btn-primary btn-sm">Add user</button>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('panel/websites/' . $wid . '/privacy/dirs')) ?>" class="filter-bar" style="border-bottom:0;padding:0;align-items:flex-end">
    <?= csrf_field() ?>
    <label class="field" style="margin:0"><span class="field-label">Path (relative to docroot)</span><input class="input" type="text" name="path" placeholder="private" required maxlength="128"></label>
    <label class="field" style="margin:0"><span class="field-label">Title</span><input class="input" type="text" name="title" placeholder="Restricted area" required maxlength="128"></label>
    <button type="submit" class="btn btn-primary btn-sm">Protect directory</button>
  </form>
</div>
