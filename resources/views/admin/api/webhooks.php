<?php $webhooks = $webhooks ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Webhooks</h2></div>
<div class="panel" style="padding:16px"><form method="post" action="<?= e(url('admin/api/webhooks')) ?>" class="filter-bar" style="border-bottom:0;padding:0"><?= csrf_field() ?>
<input class="input" type="text" name="event" placeholder="Event (e.g. AccountCreated)" required><input class="input" type="url" name="url" placeholder="https://…" required><input class="input" type="number" name="account_id" placeholder="Account ID" required>
<button type="submit" class="btn btn-primary">Add webhook</button></form></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Event</th><th>URL</th><th>Account</th><th>Status</th><th></th></tr></thead>
<tbody><?php if (empty($webhooks)): ?><?= empty_state(5, 'No webhooks.') ?>
<?php else: foreach ($webhooks as $w): ?><tr><td><code><?= e($w['event']) ?></code></td><td><?= e($w['url']) ?></td><td><?= e($w['username']??'—') ?></td>
<td><?= e($w['status']) ?></td><td class="actions"><form method="post" action="<?= e(url('admin/api/webhooks/'.$w['id'].'/delete')) ?>" class="inline-form" data-confirm="Delete this webhook?"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Delete</button></form></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div>