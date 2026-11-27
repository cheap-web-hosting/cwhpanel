<?php $remotes = $remotes ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Remote storage</h2></div>
  <p class="text-muted" style="padding:12px 16px 0">S3-compatible destinations (AWS S3, Backblaze B2, Wasabi, Hetzner Object Storage, MinIO…). Secrets are encrypted and never shown again. Always <strong>test the connection</strong> before relying on a remote.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Endpoint</th><th>Bucket</th><th>Prefix</th><th>Status</th><th style="width:170px"></th></tr></thead>
    <tbody>
      <?php if (empty($remotes)): ?><?= empty_state(6, 'No remotes yet — add one below.') ?>
      <?php else: foreach ($remotes as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong></td>
          <td class="ellipsis"><?= e($r['endpoint']) ?></td>
          <td><?= e($r['bucket']) ?></td>
          <td><?= e($r['path_prefix'] !== '' ? $r['path_prefix'] . '/' : '—') ?></td>
          <td><?= ($r['status'] ?? '') === 'ok' ? '<span class="status status-active">Tested</span>' : ((($r['status'] ?? '') === 'failed' ? '<span class="status status-failed">Failed</span>' : '<span class="status status-disabled">Untested</span>')) ?></td>
          <td class="actions">
            <form method="post" action="<?= e(url('panel/backups/remotes/' . $r['id'] . '/test')) ?>" class="inline-form"><?= csrf_field() ?>
              <button type="submit" class="btn btn-sm">Test</button>
            </form>
            <form method="post" action="<?= e(url('panel/backups/remotes/' . $r['id'] . '/delete')) ?>" class="inline-form" data-confirm="Remove remote <?= e($r['name']) ?>? Already-uploaded objects stay on the remote."><?= csrf_field() ?>
              <button type="submit" class="btn btn-sm btn-danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="panel"><div class="panel-header"><h2 class="panel-title">Add remote (S3-compatible)</h2></div>
  <form method="post" action="<?= e(url('panel/backups/remotes')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
    <div class="field"><label class="field-label" for="rem-name">Name <span class="text-muted">*</span></label>
      <input class="input" id="rem-name" type="text" name="name" placeholder="My S3 bucket" required maxlength="64"></div>
    <div class="field"><label class="field-label" for="rem-endpoint">Endpoint URL <span class="text-muted">*</span></label>
      <input class="input" id="rem-endpoint" type="text" name="endpoint" placeholder="https://s3.eu-central-1.amazonaws.com" required maxlength="255" spellcheck="false">
      <p class="field-help">Plain https://host, no bucket, no path. Examples: https://s3.eu-central-1.amazonaws.com, https://s3.eu-central-1.wasabisys.com, https://hel1.your-objectstorage.com.</p></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <div class="field"><label class="field-label" for="rem-bucket">Bucket <span class="text-muted">*</span></label>
        <input class="input" id="rem-bucket" type="text" name="bucket" required maxlength="128" spellcheck="false"></div>
      <div class="field"><label class="field-label" for="rem-region">Region</label>
        <input class="input" id="rem-region" type="text" name="region" value="us-east-1" maxlength="32" spellcheck="false"></div>
      <div class="field"><label class="field-label" for="rem-prefix">Path prefix <span class="text-muted">(optional)</span></label>
        <input class="input" id="rem-prefix" type="text" name="path_prefix" placeholder="backups" maxlength="128" spellcheck="false"></div>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <div class="field"><label class="field-label" for="rem-key">Access key <span class="text-muted">*</span></label>
        <input class="input" id="rem-key" type="text" name="access_key" required maxlength="255" spellcheck="false" autocomplete="off"></div>
      <div class="field"><label class="field-label" for="rem-secret">Secret key <span class="text-muted">*</span></label>
        <input class="input" id="rem-secret" type="password" name="secret" required maxlength="500" autocomplete="new-password"></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Add remote</button></div>
  </form>
</div>
