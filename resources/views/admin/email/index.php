<?php $domains = $domains ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Mail domains</h2><a href="<?= e(url('admin/email/domains/create')) ?>" class="btn btn-primary">Add Domain</a></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Domain</th><th>Account</th><th>Status</th><th></th></tr></thead>
<tbody><?php if (empty($domains)): ?><?= empty_state(4, 'No mail domains yet.') ?>
<?php else: foreach ($domains as $d): ?><tr><td><strong><?= e($d['domain']) ?></strong></td><td><?= e($d['username']??'—') ?></td>
<td><?= $d['status']==='active'?'<span class="status status-active">Active</span>':'<span class="status status-disabled">'.e($d['status']).'</span>' ?></td>
<td class="actions"><a href="<?= e(url('admin/email/domains/'.$d['id'])) ?>" class="btn btn-sm">Manage</a>
<form method="post" action="<?= e(url('admin/email/domains/'.$d['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete domain?">Delete</button></form></td>
</tr><?php endforeach; endif; ?></tbody></table></div></div>