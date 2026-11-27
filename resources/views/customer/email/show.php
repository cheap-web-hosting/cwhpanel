<?php $domain = $domain ?? []; $mailboxes = $mailboxes ?? []; $forwarders = $forwarders ?? []; $lists = $lists ?? []; $listMembers = $listMembers ?? []; $quotaMax = $quotaMax ?? 1024; ?>
<div class="cards-grid"><div class="card"><div class="card-label">Domain</div><div class="card-value"><?= e($domain['domain']) ?></div></div>
<div class="card"><div class="card-label">Status</div><div class="card-value"><?= e($domain['status']) ?></div></div></div>
<h2 class="section-title" id="mailboxes">Add mailbox</h2>
<div class="panel" style="padding:16px"><form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/mailbox')) ?>" class="filter-bar" style="border-bottom:0;padding:0;align-items:flex-end"><?= csrf_field() ?>
<label class="field" style="margin:0"><span class="field-label">Mailbox</span><input class="input" type="text" name="email" placeholder="name <?=e($domain['domain'])?>" required></label>
<label class="field" style="margin:0"><span class="field-label">Password</span><input class="input" type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Min 8 characters" title="Minimum 8 characters"></label>
<label class="field" style="margin:0"><span class="field-label">Quota (MB<?= $quotaMax > 0 ? ', max ' . (int) $quotaMax : '' ?>)</span><input class="input" type="number" name="quota_mb" value="100" min="1"<?= $quotaMax > 0 ? ' max="' . (int) $quotaMax . '"' : '' ?> inputmode="numeric" style="max-width:110px"></label>
<button type="submit" class="btn btn-primary btn-sm">Add</button></form></div>
<h2 class="section-title">Mailboxes</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>Email</th><th>Quota</th><th></th></tr></thead>
<tbody><?php if (empty($mailboxes)): ?><?= empty_state(3, 'No mailboxes.') ?>
<?php else: foreach ($mailboxes as $mb): ?><tr><td><?= e($mb['email']) ?></td><td><?= e($mb['quota_mb']) ?> MB</td>
<td class="actions"><?php if (feature_enabled('webmail')): ?><a href="<?= e(url('panel/webmail/open/'.$mb['id'])) ?>" class="btn btn-sm">Webmail</a><?php endif; ?>
<form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/mailbox/'.$mb['id'].'/password')) ?>" class="inline-form"><?= csrf_field() ?><label class="sr-only" for="pw-<?= (int)$mb['id'] ?>">New password</label><input class="input" id="pw-<?= (int)$mb['id'] ?>" type="password" name="password" placeholder="New password (min 8)" title="Minimum 8 characters" required minlength="8" autocomplete="new-password"><button type="submit" class="btn btn-sm">Change password</button></form>
<form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/mailbox/'.$mb['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete mailbox <?= e($mb['email']) ?>?">Delete</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
<h2 class="section-title" id="forwarders">Add forwarder</h2>
<div class="panel" style="padding:16px"><form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/forwarder')) ?>" class="filter-bar" style="border-bottom:0;padding:0;align-items:flex-end"><?= csrf_field() ?>
<label class="field" style="margin:0"><span class="field-label">From</span><input class="input" type="text" name="source" placeholder="name <?=e($domain['domain'])?>" required></label>
<label class="field" style="margin:0"><span class="field-label">To</span><input class="input" type="text" name="destination" placeholder="destination@example.com" required spellcheck="false"></label>
<button type="submit" class="btn btn-primary btn-sm">Add</button></form></div>
<h2 class="section-title" id="catch-all">Default (catch-all) address</h2>
<div class="panel" style="padding:16px"><form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/catchall')) ?>" class="filter-bar" style="border-bottom:0;padding:0;align-items:flex-end"><?= csrf_field() ?>
<label class="field" style="margin:0"><span class="field-label">Unknown recipients go to (empty = bounce)</span><input class="input" type="text" name="catchall" value="<?= e($domain['catchall'] ?? '') ?>" placeholder="catchall@example.com" spellcheck="false"></label>
<button type="submit" class="btn btn-primary btn-sm">Save</button></form></div>
<h2 class="section-title">Forwarders</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>From</th><th>To</th><th></th></tr></thead>
<tbody><?php if (empty($forwarders)): ?><?= empty_state(3, 'No forwarders.') ?>
<?php else: foreach ($forwarders as $f): ?><tr><td><?= e($f['source']) ?></td><td><?= e($f['destination']) ?></td>
<td class="actions"><form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/forwarder/'.$f['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Delete</button></form></td>
</tr><?php endforeach; endif; ?></tbody></table></div>

<h2 class="section-title" id="mailing-lists">Mailing lists</h2>
<?php if (empty($lists)): ?>
<div class="panel" style="padding:16px"><p class="text-muted">No mailing lists.</p></div>
<?php else: ?>
<?php foreach ($lists as $l): ?>
<div class="panel" style="padding:16px;margin-bottom:12px">
<h3 class="panel-title"><code><?= e($l['name']) ?>@<?= e($domain['domain']) ?></code>
(<?= e($l['posting'] === 'members' ? 'members can post' : 'anyone can post') ?>, <?= (int)($l['member_count'] ?? 0) ?> members)</h3>
<div class="form-actions" style="margin-bottom:8px">
<form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/mailing-list/'.$l['id'].'/posting')) ?>" class="inline-form">
<?= csrf_field() ?>
<select class="input" name="posting" style="width:auto;display:inline-block">
<option value="members" <?= $l['posting'] === 'members' ? 'selected' : '' ?>>Members can post</option>
<option value="open" <?= $l['posting'] === 'open' ? 'selected' : '' ?>>Anyone can post</option>
</select>
<button type="submit" class="btn btn-sm">Save</button>
</form>
<form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/mailing-list/'.$l['id'].'/delete')) ?>" class="inline-form">
<?= csrf_field() ?>
<button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete list <?= e($l['name']) ?>?">Delete</button>
</form>
</div>
<div class="table-wrap"><table class="table">
<thead><tr><th>Member</th><th class="actions">Actions</th></tr></thead>
<tbody>
<?php $members = $listMembers[(int)$l['id']] ?? []; ?>
<?php if (empty($members)): ?>
<?= empty_state(2, 'No members — posts to an empty list bounce.') ?>
<?php else: ?>
<?php foreach ($members as $m): ?>
<tr>
<td><code><?= e($m['email']) ?></code></td>
<td class="actions">
<form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/mailing-list/'.$l['id'].'/member/'.$m['id'].'/delete')) ?>" class="inline-form">
<?= csrf_field() ?>
<button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove <?= e($m['email']) ?>?">Remove</button>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table></div>
<form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/mailing-list/'.$l['id'].'/member')) ?>" class="filter-bar" style="border-bottom:0;padding:12px 0 0">
<?= csrf_field() ?>
<label class="field" style="margin:0"><span class="field-label">Email</span><input class="input" type="email" name="email" required maxlength="255" placeholder="member@example.com"></label>
<button type="submit" class="btn btn-primary btn-sm">Subscribe</button>
</form>
</div>
<?php endforeach; ?>
<?php endif; ?>
<div class="panel" style="padding:16px">
<form method="post" action="<?= e(url('panel/email/domains/'.$domain['id'].'/mailing-list')) ?>" class="filter-bar" style="border-bottom:0;padding:0;align-items:flex-end">
<?= csrf_field() ?>
<label class="field" style="margin:0"><span class="field-label">List name</span><input class="input" type="text" name="name" required maxlength="64" placeholder="team" pattern="[A-Za-z0-9._-]+"></label>
<label class="field" style="margin:0"><span class="field-label">Posting</span><select class="input" name="posting"><option value="members">Members can post</option><option value="open">Anyone can post</option></select></label>
<button type="submit" class="btn btn-primary btn-sm">Create list</button>
</form>
</div>
