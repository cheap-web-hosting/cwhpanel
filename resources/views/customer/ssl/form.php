<?php $domains = $domains ?? []; ?>
<?php $opts = function () use ($domains) {
    $h = '<option value="">Select…</option>';
    foreach ($domains as $d) { $h .= '<option value="' . e($d['id']) . '">' . e($d['name']) . '</option>'; }
    return $h;
}; ?>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-header"><h2 class="panel-title">Let's Encrypt certificate</h2></div>
  <form method="post" action="<?= e(url('panel/ssl')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <p class="text-muted" style="margin:0">Pick a domain — DNS is checked and the challenge path is handled automatically. Auto-renew stays on.</p>
    <div class="field">
      <label class="field-label" for="le_domain_id">Domain <span class="text-muted">*</span></label>
      <select class="input" id="le_domain_id" name="domain_id" required><?= $opts() ?></select>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Issue Let's Encrypt certificate</button>
    </div>
  </form>
</div>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-header"><h2 class="panel-title">Install your own certificate</h2></div>
  <form method="post" action="<?= e(url('panel/ssl/custom')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <p class="text-muted" style="margin:0">Paste a certificate issued elsewhere (e.g. a paid or wildcard cert). It must cover the chosen domain and match the private key.</p>
    <div class="field">
      <label class="field-label" for="custom_domain_id">Domain <span class="text-muted">*</span></label>
      <select class="input" id="custom_domain_id" name="domain_id" required><?= $opts() ?></select>
    </div>
    <div class="field">
      <label class="field-label" for="certificate">Certificate (PEM, chain allowed) <span class="text-muted">*</span></label>
      <textarea class="input" id="certificate" name="certificate" rows="8" required spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
    </div>
    <div class="field">
      <label class="field-label" for="private_key">Private key (PEM) <span class="text-muted">*</span></label>
      <textarea class="input" id="private_key" name="private_key" rows="8" required spellcheck="false" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Install custom certificate</button>
    </div>
  </form>
</div>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Self-signed certificate</h2></div>
  <form method="post" action="<?= e(url('panel/ssl/self-signed')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <p class="text-muted" style="margin:0">Instant, but browsers will warn — for testing or internal use.</p>
    <div class="field">
      <label class="field-label" for="ss_domain_id">Domain <span class="text-muted">*</span></label>
      <select class="input" id="ss_domain_id" name="domain_id" required><?= $opts() ?></select>
    </div>
    <div class="field">
      <label class="field-label" for="days">Validity (days)</label>
      <input class="input" type="number" id="days" name="days" value="90" min="1" max="3650">
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Issue self-signed certificate</button>
      <a href="<?= e(url('panel/ssl')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
