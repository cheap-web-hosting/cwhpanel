<?php
$enabled = $enabled ?? false;
$bound = $bound ?? [];
$pool = $pool ?? [];
$sharedIp = $sharedIp ?? '';
$agentError = $agentError ?? '';
$appNetns = $appNetns ?? false;
?>
<?php if ($agentError !== ''): ?>
  <div class="flash flash-error" style="margin-bottom:16px"><?= e($agentError) ?></div>
<?php endif; ?>
<div class="panel" style="margin-bottom:16px">
  <div class="panel-header"><h2 class="panel-title">Dedicated IPv4 <?= $enabled ? '<span class="status status-active">Enabled</span>' : '<span class="status status-disabled">Disabled</span>' ?></h2></div>
  <form method="post" action="<?= e(url('admin/network/settings')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> <span>Enable the dedicated IPv4 pool (per-website assignments)</span></label>
    <label class="check"><input type="checkbox" name="app_netns" value="1" <?= $appNetns ? 'checked' : '' ?>> <span>Isolate each account's applications in their own network namespace (blocks app-to-app access; requires an app restart)</span></label>
    <p class="field-help">Off = every website shares <code><?= e($sharedIp !== '' ? $sharedIp : 'the panel IP') ?></code>. When on, an address from the pool below can be assigned to individual websites, and the vhost gets an extra <code>listen</code> for it.</p>
    <p class="field-help"><strong>Note:</strong> per-account IPv6 is not included in this edition. The host itself stays dual-stack (its own <code>[::]</code> listeners and AAAA records are unaffected); accounts are never assigned a dedicated IPv6 address.</p>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save</button></div>
  </form>
</div>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-header"><h2 class="panel-title">Host addresses <span class="text-muted">(live, from the host)</span></h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Family</th><th>Interface</th><th>Address</th></tr></thead>
    <tbody>
      <?php if (empty($bound)): ?><?= empty_state(3, 'No addresses reported (agent unreachable?).') ?>
      <?php else: foreach ($bound as $b): ?>
        <tr>
          <td><?= e($b['family']) ?></td>
          <td><?= e($b['ifname']) ?></td>
          <td><code><?= e($b['address']) ?></code></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-header"><h2 class="panel-title">IPv4 pool <span class="text-muted">(dedicated website assignments; empty until you add future IPs)</span></h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Address</th><th>Prefix</th><th>Note</th><th>Bound</th><th>Assigned</th><th style="width:110px"></th></tr></thead>
    <tbody>
      <?php if (empty($pool)): ?><?= empty_state(6, 'No pool addresses. Websites use the shared IP.') ?>
      <?php else: foreach ($pool as $p): ?>
        <tr>
          <td><code><?= e($p['address']) ?></code></td>
          <td>/<?= (int) ($p['prefixlen'] ?? 32) ?></td>
          <td><?= e($p['note'] ?? '—') ?></td>
          <td><?= !empty($p['bound']) ? '<span class="status status-active">yes</span>' : '<span class="status status-disabled">no</span>' ?></td>
          <td><?= (int) ($p['assigned'] ?? 0) ?></td>
          <td class="actions">
            <form method="post" action="<?= e(url('admin/network/pool/' . $p['id'] . '/delete')) ?>" class="inline-form" data-confirm="Remove this address from the pool?"><?= csrf_field() ?>
              <button type="submit" class="btn btn-sm">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
  <form method="post" action="<?= e(url('admin/network/pool')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="pool-address">IPv4 address</label>
      <input class="input" type="text" id="pool-address" name="address" required maxlength="45" placeholder="203.0.113.10" style="max-width:220px">
      <p class="field-help">An address already routed to this host. It is bound on eth0 and offered to websites.</p>
    </div>
    <div class="field">
      <label class="field-label" for="pool-prefixlen">Prefix length</label>
      <input class="input" type="number" id="pool-prefixlen" name="prefixlen" value="32" min="1" max="32" style="max-width:120px">
    </div>
    <div class="field">
      <label class="field-label" for="pool-note">Note</label>
      <input class="input" type="text" id="pool-note" name="note" maxlength="255" style="max-width:320px">
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Add to pool</button></div>
  </form>
</div>
