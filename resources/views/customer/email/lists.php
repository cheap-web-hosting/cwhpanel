<?php $lists = $lists ?? []; $listCounts = $listCounts ?? []; $listMembers = $listMembers ?? []; $domainOptions = $domainOptions ?? []; ?>
<div class="panel" style="padding:16px">
  <?php if (empty($domainOptions)): ?><p class="text-muted" style="margin:0">Add a domain first to create mailing lists.</p>
  <?php else: ?>
  <form method="post" action="<?= e(url('panel/email/lists')) ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end"><?= csrf_field() ?>
    <label class="field" style="flex:2 1 170px;margin:0"><span class="field-label">Domain</span><select class="input" name="domain" required>
      <option value="">Select domain…</option>
      <?php foreach ($domainOptions as $o): ?><option value="<?= e($o['name']) ?>"><?= e($o['name']) ?><?= empty($o['mail']) ? ' (enables mail)' : '' ?></option><?php endforeach; ?>
    </select></label>
    <label class="field" style="flex:2 1 150px;margin:0"><span class="field-label">List name</span><input class="input" type="text" name="name" placeholder="team" required maxlength="64" pattern="[A-Za-z0-9._-]+" spellcheck="false"></label>
    <label class="field" style="flex:1 1 150px;margin:0"><span class="field-label">Posting</span><select class="input" name="posting"><option value="members">Members can post</option><option value="open">Anyone can post</option></select></label>
    <button type="submit" class="btn btn-primary">Create list</button>
  </form>
  <?php endif; ?>
</div>

<h2 class="section-title">Mailing lists</h2>
<?php if (empty($lists)): ?>
<div class="panel" style="padding:16px"><p class="text-muted" style="margin:0">No mailing lists yet.</p></div>
<?php else: ?>
<?php foreach ($lists as $l): ?>
<div class="panel" style="padding:16px;margin-bottom:12px">
  <h3 class="panel-title"><code><?= e($l['name']) ?>@<?= e($l['domain']) ?></code>
  (<?= e($l['posting'] === 'members' ? 'members can post' : 'anyone can post') ?>, <?= (int) ($listCounts[(int) $l['id']] ?? 0) ?> members)</h3>
  <div class="form-actions" style="margin-bottom:8px">
    <form method="post" action="<?= e(url('panel/email/domains/'.$l['domain_id'].'/mailing-list/'.$l['id'].'/posting')) ?>" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="return_to" value="/panel/email/lists">
      <select class="input" name="posting" style="width:auto;display:inline-block">
        <option value="members" <?= $l['posting'] === 'members' ? 'selected' : '' ?>>Members can post</option>
        <option value="open" <?= $l['posting'] === 'open' ? 'selected' : '' ?>>Anyone can post</option>
      </select>
      <button type="submit" class="btn btn-sm">Save</button>
    </form>
    <form method="post" action="<?= e(url('panel/email/domains/'.$l['domain_id'].'/mailing-list/'.$l['id'].'/delete')) ?>" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="return_to" value="/panel/email/lists">
      <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete list <?= e($l['name']) ?>?">Delete</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Member</th><th class="actions">Actions</th></tr></thead>
    <tbody>
    <?php $members = $listMembers[(int) $l['id']] ?? []; ?>
    <?php if (empty($members)): ?>
      <?= empty_state(2, 'No members — posts to an empty list bounce.') ?>
    <?php else: ?>
      <?php foreach ($members as $m): ?>
      <tr>
        <td><code><?= e($m['email']) ?></code></td>
        <td class="actions">
          <form method="post" action="<?= e(url('panel/email/domains/'.$l['domain_id'].'/mailing-list/'.$l['id'].'/member/'.$m['id'].'/delete')) ?>" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="return_to" value="/panel/email/lists">
            <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove <?= e($m['email']) ?>?">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table></div>
  <form method="post" action="<?= e(url('panel/email/domains/'.$l['domain_id'].'/mailing-list/'.$l['id'].'/member')) ?>" class="filter-bar" style="border-bottom:0;padding:12px 0 0">
    <?= csrf_field() ?><input type="hidden" name="return_to" value="/panel/email/lists">
    <label class="field" style="margin:0"><span class="field-label">Email</span><input class="input" type="email" name="email" required maxlength="255" placeholder="member@example.com"></label>
    <button type="submit" class="btn btn-primary btn-sm">Subscribe</button>
  </form>
</div>
<?php endforeach; ?>
<?php endif; ?>
