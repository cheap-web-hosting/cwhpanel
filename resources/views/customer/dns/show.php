<?php
$zone = $zone ?? [];
$records = $records ?? [];
$templates = $templates ?? [];
$error = $error ?? null;
$dsRecords = $dsRecords ?? [];
function enc(string $s): string {
    // Reversible for the controller (rawurldecode + explode ':'):
    // the old dash-replacement destroyed the separator AND the dots.
    return rawurlencode($s);
}
?>

<?php if ($error): ?>
  <div class="flash flash-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Zone</div><div class="card-value"><?= e($zone['name']) ?></div></div>
  <div class="card"><div class="card-label">DNSSEC</div><div class="card-value"><?= $zone['dnssec'] ? 'Enabled' : 'Disabled' ?></div></div>
  <div class="card"><div class="card-label">Nameservers</div><div class="card-value"><?= e(trim(($zone['nameserver'] ?? '') . ' ' . ($zone['nameserver2'] ?? '')) ?: 'None published') ?></div></div>
</div>

<?php if (!empty($zone['dnssec'])): ?>
<h2 class="section-title">DS records (registrar glue)</h2>
<div class="panel" style="padding:16px">
  <?php if (empty($dsRecords)): ?>
    <p class="text-muted">No DS records published yet.</p>
  <?php else: ?>
    <p class="text-muted">Give these to the domain registrar to complete the chain of trust:</p>
    <?php foreach ($dsRecords as $ds): ?>
      <p><code><?= e($ds) ?></code></p>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2 class="section-title">Apply template</h2>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('panel/dns/zones/' . $zone['name'] . '/apply-template')) ?>" class="filter-bar" style="border-bottom:0;padding:0">
    <?= csrf_field() ?>
    <select class="input" name="template_id" style="min-width:200px">
      <option value="">Select a template…</option>
      <?php foreach ($templates as $t): ?>
        <option value="<?= e($t['id']) ?>"><?= e($t['name']) ?><?= $t['is_system'] ? ' (system)' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  </form>
</div>

<h2 class="section-title">Add record</h2>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('panel/dns/zones/' . $zone['name'] . '/records')) ?>" class="filter-bar" style="border-bottom:0;padding:0">
    <?= csrf_field() ?>
    <input class="input" type="text" name="name" placeholder="Record name (@ for apex)" style="min-width:160px">
    <select class="input" name="type" style="min-width:70px">
      <option value="A">A</option>
      <option value="AAAA">AAAA</option>
      <option value="CNAME">CNAME</option>
      <option value="MX">MX</option>
      <option value="TXT">TXT</option>
      <option value="NS">NS</option>
      <option value="SRV">SRV</option>
      <option value="CAA">CAA</option>
    </select>
    <input class="input" type="text" name="content" placeholder="Value" style="min-width:160px">
    <input class="input" type="number" name="ttl" value="3600" min="1" max="604800" style="min-width:70px">
    <button type="submit" class="btn btn-primary btn-sm">Add</button>
  </form>
</div>

<h2 class="section-title">Records</h2>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Name</th><th>Type</th><th>Content</th><th>TTL</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($records)): ?>
        <?= empty_state(5, 'No records.') ?>
      <?php else: ?>
        <?php foreach ($records as $r): ?>
          <?php if ($r['type'] === 'SOA') continue; ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td><code><?= e($r['type']) ?></code></td>
            <td><?= e($r['content']) ?></td>
            <td><?= e($r['ttl']) ?></td>
            <?php if ($r['type'] !== 'NS'): ?>
              <td class="actions">
                <form method="post" action="<?= e(url('panel/dns/zones/' . $zone['name'] . '/records/' . enc($r['type'] . ':' . $r['name']) . '/delete')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete record?">Delete</button>
                </form>
              </td>
            <?php else: ?>
              <td></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
