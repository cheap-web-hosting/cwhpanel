<?php $domain = $domain ?? []; ?>

<div class="cards-grid">
  <div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($domain['name']) ?></div></div>
  <div class="card"><div class="card-label">Type</div><div class="card-value"><?= e($domain['type']) ?></div></div>
  <div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($domain['status']) ?></div></div>
  <div class="card"><div class="card-label">Document root</div><div class="card-value"><code><?= e($domain['document_root'] ?? '—') ?></code></div></div>
</div>

<?php if (($domain['type'] ?? '') !== 'subdomain'): ?>
<div class="panel" style="padding:16px; margin-top:16px">
  <h3 class="panel-title" style="margin-top:0">Mail &amp; DNS</h3>
  <p class="field-help" style="margin-top:0">What is published in your zone for mail. Changes are applied to DNS immediately.</p>
  <form method="post" action="<?= e(url('panel/domains/' . $domain['id'] . '/mail-dns')) ?>" class="form-stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label" for="mail_mode">Mail hosting</label>
      <select class="input" id="mail_mode" name="mail_mode">
        <option value="local" <?= ($domain['mail_mode'] ?? 'local') === 'local' ? 'selected' : '' ?>>Hosted on this server</option>
        <option value="external" <?= ($domain['mail_mode'] ?? 'local') === 'external' ? 'selected' : '' ?>>External (Google, M365, …)</option>
      </select>
      <p class="field-help">External skips the MX, mail.&lt;domain&gt; and DKIM records — your provider publishes those.</p>
    </div>

    <div class="field">
      <label class="field-label" for="spf_extra">Extra SPF mechanisms <span class="text-muted">(optional)</span></label>
      <input class="input" type="text" id="spf_extra" name="spf_extra" value="<?= e($domain['spf_extra'] ?? '') ?>" placeholder="include:_spf.google.com include:sendgrid.net" autocomplete="off" spellcheck="false">
      <p class="field-help">Added before <code>-all</code>. With external mail and no mechanisms, no SPF record is published.</p>
    </div>

    <div class="field">
      <label class="field-label" for="dmarc_policy">DMARC policy</label>
      <select class="input" id="dmarc_policy" name="dmarc_policy">
        <?php foreach (['none' => 'none (monitor)', 'quarantine' => 'quarantine', 'reject' => 'reject'] as $pv => $pl): ?>
          <option value="<?= e($pv) ?>" <?= ($domain['dmarc_policy'] ?? 'none') === $pv ? 'selected' : '' ?>><?= e($pl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="field-label" for="dmarc_rua">DMARC reports to <span class="text-muted">(optional)</span></label>
      <input class="input" type="email" id="dmarc_rua" name="dmarc_rua" value="<?= e($domain['dmarc_rua'] ?? '') ?>" placeholder="dmarc-reports@example.com" autocomplete="off">
      <p class="field-help">Leave empty for <code>postmaster@&lt;domain&gt;</code>.</p>
    </div>

    <div class="field">
      <label class="field-label" for="dns_ttl">DNS TTL <span class="text-muted">(optional)</span></label>
      <input class="input" type="number" id="dns_ttl" name="dns_ttl" min="60" max="604800" value="<?= e((string) ($domain['dns_ttl'] ?? '')) ?>" placeholder="3600">
      <p class="field-help">Seconds; leave empty for the server default (3600).</p>
    </div>

    <div class="field">
      <label class="field-label" style="display:flex;align-items:center;gap:8px">
        <input type="hidden" name="webmail_record" value="0">
        <input type="checkbox" name="webmail_record" value="1" <?= (int) ($domain['webmail_record'] ?? 1) === 1 ? 'checked' : '' ?>>
        Publish <code>webmail.&lt;domain&gt;</code>
      </label>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save mail &amp; DNS</button>
    </div>
  </form>
</div>

<?php endif; ?>

<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('panel/domains/' . $domain['id'] . '/delete')) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger" data-confirm="Delete domain <?= e($domain['name']) ?>?">Delete domain</button>
  </form>
</div>