<?php $accounts = $accounts ?? []; ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Add zone</h2></div>
  <form method="post" action="<?= e(url('admin/dns/zones')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="name">Zone name <span class="text-muted">*</span></label>
      <input class="input" type="text" id="name" name="name" placeholder="example.com" required>
    </div>
    <div class="field">
      <label class="field-label" for="account_id">Account <span class="text-muted">*</span></label>
      <select class="input" id="account_id" name="account_id" required>
        <option value="">Select account…</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= e($a['id']) ?>"><?= e($a['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="nameserver">Nameserver 1 <span class="text-muted">(optional)</span></label>
      <input class="input" type="text" id="nameserver" name="nameserver" placeholder="ns1.yourprovider.com" autocomplete="off" spellcheck="false">
    </div>
    <div class="field">
      <label class="field-label" for="nameserver2">Nameserver 2 <span class="text-muted">(optional)</span></label>
      <input class="input" type="text" id="nameserver2" name="nameserver2" placeholder="ns2.yourprovider.com" autocomplete="off" spellcheck="false">
      <p class="field-help">The nameservers the domain is delegated to. The panel never inserts its own nameservers; leave blank only if the zone is not used.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Create zone</button>
      <a href="<?= e(url('admin/dns/zones')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>