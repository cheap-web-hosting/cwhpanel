<?php $mailbox = $mailbox ?? []; $auto = $auto ?? null; $filters = $filters ?? []; $mid = (int) ($mailbox['id'] ?? 0); ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Autoresponder: <?= e($mailbox['email'] ?? '') ?></h2></div>
  <form method="post" action="<?= e(url('admin/email/mailbox/' . $mid . '/filters')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <div class="field">
      <label class="field-label">
        <input type="checkbox" name="enabled" value="1" <?= !empty($auto['enabled']) ? 'checked' : '' ?> style="vertical-align:middle">
        Enable autoresponder
      </label>
    </div>
    <div class="field">
      <label class="field-label" for="subject">Subject</label>
      <input class="input" id="subject" name="subject" value="<?= e((string) ($auto['subject'] ?? '')) ?>" maxlength="255">
    </div>
    <div class="field">
      <label class="field-label" for="body">Message</label>
      <textarea class="input" id="body" name="body" rows="5" maxlength="8000"><?= e((string) ($auto['body'] ?? '')) ?></textarea>
      <p class="field-help">Senders get at most one automatic reply per day.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save autoresponder</button>
    </div>
  </form>
</div>

<h2 class="section-title">Filter rules (run top to bottom)</h2>
<div class="panel">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>#</th><th>When</th><th>Action</th><th class="actions">Actions</th></tr></thead>
    <tbody>
      <?php if (empty($filters)): ?>
        <?= empty_state(4, 'No filter rules. Mail is delivered to the inbox.') ?>
      <?php else: ?>
        <?php foreach ($filters as $i => $f): ?>
          <tr>
            <td><?= e((string) ($i + 1)) ?></td>
            <td><code><?= e($f['field']) ?> <?= e($f['operator']) ?> "<?= e($f['value']) ?>"</code></td>
            <td><code><?= e($f['action']) ?><?= $f['target'] !== null && $f['target'] !== '' ? ' → ' . e($f['target']) : '' ?></code></td>
            <td class="actions">
              <form method="post" action="<?= e(url('admin/email/mailbox/' . $mid . '/filters/rules/' . $f['id'] . '/delete')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete this rule?">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table></div>
  <form method="post" action="<?= e(url('admin/email/mailbox/' . $mid . '/filters/rules')) ?>" class="form-stack" style="padding:16px">
    <?= csrf_field() ?>
    <h3 class="panel-title">Add rule</h3>
    <div class="field">
      <label class="field-label" for="field">Field</label>
      <select class="input" id="field" name="field" style="max-width:200px">
        <option value="from">From</option>
        <option value="to">To</option>
        <option value="subject">Subject</option>
        <option value="body">Body</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="operator">Matches when it</label>
      <select class="input" id="operator" name="operator" style="max-width:200px">
        <option value="contains">contains</option>
        <option value="is">is exactly</option>
        <option value="begins">begins with</option>
        <option value="ends">ends with</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="value">Value</label>
      <input class="input" id="value" name="value" maxlength="255" required>
    </div>
    <div class="field">
      <label class="field-label" for="action">Then</label>
      <select class="input" id="action" name="action" style="max-width:200px">
        <option value="discard">Discard</option>
        <option value="fileinto">Move to folder</option>
        <option value="redirect">Forward to address</option>
        <option value="keep">Keep (continue to next rule)</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="target">Folder or address (for move / forward)</label>
      <input class="input" id="target" name="target" maxlength="255" placeholder="Archive or someone@example.com">
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Add rule</button>
    </div>
  </form>
</div>
