<?php
$domain = $domain ?? null;
$old = $old ?? [];
$accounts = $accounts ?? [];
$lockedType = $lockedType ?? false;
$type = $old['type'] ?? $domain['type'] ?? 'main';

function domValue(?array $domain, array $old, string $key, $default = '') {
    if (array_key_exists($key, $old)) return $old[$key];
    return $domain[$key] ?? $default;
}

$typeLabel = ['main' => 'Main domain', 'addon' => 'Addon domain', 'parked' => 'Parked domain', 'subdomain' => 'Subdomain'][$type] ?? 'Domain';
?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title"><?= $domain ? 'Edit domain' : ('Add ' . strtolower($typeLabel)) ?></h2></div>
  <form method="post" action="<?= e($domain ? url('admin/domains/' . $domain['id']) : url('admin/domains')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>

    <?php if ($lockedType): ?>
      <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php endif; ?>

    <?php if (!$domain && $type === 'subdomain' && !$lockedType): ?>
      <!-- handled by the dedicated subdomain form -->
    <?php elseif (!$domain): ?>
      <div class="field">
        <label class="field-label" for="name">Domain name <span class="text-muted">*</span></label>
        <input class="input" type="text" id="name" name="name" value="<?= e(domValue($domain, $old, 'name')) ?>" placeholder="example.com" required>
      </div>
    <?php else: ?>
      <div class="field">
        <label class="field-label">Domain name</label>
        <input class="input" type="text" value="<?= e($domain['name']) ?>" disabled>
      </div>
    <?php endif; ?>


    <div class="field">
      <label class="field-label" for="account_id">Account <span class="text-muted">*</span></label>
      <select class="input" id="account_id" name="account_id" required>
        <option value="">Select account…</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= e($a['id']) ?>" <?= (string) domValue($domain, $old, 'account_id') === (string) $a['id'] ? 'selected' : '' ?>>
            <?= e($a['username']) ?> (<?= e($a['domain']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if (!$lockedType): ?>
    <div class="field">
      <label class="field-label" for="type">Type</label>
      <select class="input" id="type" name="type" <?= $domain ? '' : '' ?>>
        <option value="main" <?= $type === 'main' ? 'selected' : '' ?>>Main</option>
        <option value="addon" <?= $type === 'addon' ? 'selected' : '' ?>>Addon</option>
        <option value="parked" <?= $type === 'parked' ? 'selected' : '' ?>>Parked</option>
        <option value="subdomain" <?= $type === 'subdomain' ? 'selected' : '' ?>>Subdomain</option>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($type === 'parked'): ?>
    <div class="field">
      <label class="field-label" for="redirect_url">Redirect URL <span class="text-muted">(optional)</span></label>
      <input class="input" type="text" id="redirect_url" name="redirect_url" value="<?= e(domValue($domain, $old, 'redirect_url')) ?>" placeholder="https://example.com">
    </div>
    <?php endif; ?>

    <div class="field">
      <label class="field-label" for="mail_mode">Mail hosting</label>
      <select class="input" id="mail_mode" name="mail_mode">
        <option value="local" <?= (domValue($domain, $old, 'mail_mode', 'local')) === 'local' ? 'selected' : '' ?>>Hosted on this server</option>
        <option value="external" <?= (domValue($domain, $old, 'mail_mode', 'local')) === 'external' ? 'selected' : '' ?>>External (Google, M365, …)</option>
      </select>
      <p class="field-help">External skips the MX, mail.&lt;domain&gt; and DKIM records — your provider publishes those.</p>
    </div>

    <div class="field">
      <label class="field-label" for="spf_extra">Extra SPF mechanisms <span class="text-muted">(optional)</span></label>
      <input class="input" type="text" id="spf_extra" name="spf_extra" value="<?= e(domValue($domain, $old, 'spf_extra')) ?>" placeholder="include:_spf.google.com include:sendgrid.net" autocomplete="off" spellcheck="false">
      <p class="field-help">Added before <code>-all</code>. With external mail and no mechanisms, no SPF record is published.</p>
    </div>

    <div class="field">
      <label class="field-label" for="dmarc_policy">DMARC policy</label>
      <select class="input" id="dmarc_policy" name="dmarc_policy">
        <?php foreach (['none' => 'none (monitor)', 'quarantine' => 'quarantine', 'reject' => 'reject'] as $pv => $pl): ?>
          <option value="<?= e($pv) ?>" <?= (domValue($domain, $old, 'dmarc_policy', 'none')) === $pv ? 'selected' : '' ?>><?= e($pl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="field-label" for="dmarc_rua">DMARC reports to <span class="text-muted">(optional)</span></label>
      <input class="input" type="email" id="dmarc_rua" name="dmarc_rua" value="<?= e(domValue($domain, $old, 'dmarc_rua')) ?>" placeholder="dmarc-reports@example.com" autocomplete="off">
      <p class="field-help">Leave empty for <code>postmaster@&lt;domain&gt;</code>.</p>
    </div>

    <div class="field">
      <label class="field-label" for="dns_ttl">DNS TTL <span class="text-muted">(optional)</span></label>
      <input class="input" type="number" id="dns_ttl" name="dns_ttl" min="60" max="604800" value="<?= e((string) domValue($domain, $old, 'dns_ttl', '')) ?>" placeholder="3600">
      <p class="field-help">Seconds; leave empty for the server default (3600).</p>
    </div>

    <div class="field">
      <label class="field-label" style="display:flex;align-items:center;gap:8px">
        <input type="hidden" name="webmail_record" value="0">
        <input type="checkbox" name="webmail_record" value="1" <?= (int) domValue($domain, $old, 'webmail_record', 1) === 1 ? 'checked' : '' ?>>
        Publish <code>webmail.&lt;domain&gt;</code>
      </label>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save</button>
      <a href="<?= e(url('admin/domains')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>