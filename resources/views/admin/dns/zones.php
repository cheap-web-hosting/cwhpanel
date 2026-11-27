<?php $ms = $mailSettings ?? ['prefix' => 'mail.', 'dkim' => 'default']; ?>
<div class="panel" style="padding:16px; margin-bottom:16px">
  <h3 class="panel-title" style="margin-top:0">Mail hostname &amp; DKIM</h3>
  <p class="field-help" style="margin-top:0">
    Server-wide: <code><?= e($ms['prefix']) ?><em>domain</em></code> is published for mail and
    <code><?= e($ms['dkim']) ?>._domainkey</code> carries the DKIM key. Applies to zones provisioned from now on.
  </p>
  <form method="post" action="<?= e(url('admin/dns/mail-settings')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="mail_hostname_prefix">Mail hostname prefix <span class="text-muted">*</span></label>
      <input class="input" type="text" id="mail_hostname_prefix" name="mail_hostname_prefix" value="<?= e($ms['prefix']) ?>" placeholder="mail." autocomplete="off" spellcheck="false">
      <p class="field-help">Must end with a dot — <code>mail.</code> produces <code>mail.example.com</code>.</p>
    </div>
    <div class="field">
      <label class="field-label" for="dkim_selector">DKIM selector <span class="text-muted">*</span></label>
      <input class="input" type="text" id="dkim_selector" name="dkim_selector" value="<?= e($ms['dkim']) ?>" placeholder="default" autocomplete="off" spellcheck="false">
      <p class="field-help">A single DNS label. Changing it affects newly provisioned zones; existing zones keep the record they were published with unless the domain is re-saved.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save mail settings</button>
    </div>
  </form>
</div>

<?php $ns = $nameservers ?? ['ns1' => null, 'ns2' => null]; ?>
<div class="panel" style="padding:16px; margin-bottom:16px">
  <h3 class="panel-title" style="margin-top:0">Server nameservers</h3>
  <p class="field-help" style="margin-top:0">
    These apply to <strong>every</strong> zone this panel hosts. Customers never set nameservers.
    Current: <code><?= e(($ns['ns1'] ?? '') !== null && $ns['ns1'] !== '' ? $ns['ns1'] : 'not set') ?></code>
    <?= ($ns['ns2'] ?? '') !== null && $ns['ns2'] !== '' ? ' / <code>' . e($ns['ns2']) . '</code>' : '' ?>
  </p>
  <form method="post" action="<?= e(url('admin/dns/nameservers')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="nameserver">Nameserver 1 <span class="text-muted">*</span></label>
      <input class="input" type="text" id="nameserver" name="nameserver" value="<?= e($ns['ns1'] ?? '') ?>" placeholder="ns1.yourprovider.com" autocomplete="off" spellcheck="false">
    </div>
    <div class="field">
      <label class="field-label" for="nameserver2">Nameserver 2 <span class="text-muted">(optional)</span></label>
      <input class="input" type="text" id="nameserver2" name="nameserver2" value="<?= e($ns['ns2'] ?? '') ?>" placeholder="ns2.yourprovider.com" autocomplete="off" spellcheck="false">
      <p class="field-help">Saving rewrites the NS records (and SOA mname) of every zone in the list below.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save server nameservers</button>
    </div>
  </form>
</div>

<?php $zones = $zones ?? []; $search = $search ?? ''; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">DNS zones</h2>
    <a href="<?= e(url('admin/dns/zones/create')) ?>" class="btn btn-primary">Add Zone</a>
  </div>
  <form method="get" action="<?= e(url('admin/dns/zones')) ?>" class="filter-bar">
    <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search zone…">
    <button type="submit" class="btn">Search</button>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Zone</th><th>Account</th><th>DNSSEC</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($zones)): ?>
          <?= empty_state(5, 'No zones yet. <a href="' . e(url('admin/dns/zones/create')) . '">Create one</a>.') ?>
        <?php else: ?>
          <?php foreach ($zones as $z): ?>
            <tr>
              <td><strong><?= e($z['name']) ?></strong></td>
              <td><?= e($z['username'] ?? '—') ?></td>
              <td><?= $z['dnssec'] ? '<span class="status status-active">Enabled</span>' : '<span class="status status-disabled">Disabled</span>' ?></td>
              <td><?= e($z['status']) ?></td>
              <td class="actions">
                <a href="<?= e(url('admin/dns/zones/' . $z['name'])) ?>" class="btn btn-sm">Manage</a>
                <form method="post" action="<?= e(url('admin/dns/zones/' . $z['name'] . '/delete')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete zone <?= e($z['name']) ?>?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>