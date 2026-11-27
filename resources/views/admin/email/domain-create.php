<?php $accounts = $accounts ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Add mail domain</h2></div>
<form method="post" action="<?= e(url('admin/email/domains')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="domain">Domain <span class="text-muted">*</span></label><input class="input" type="text" id="domain" name="domain" placeholder="example.com" required></div>
<div class="field"><label class="field-label" for="account_id">Account <span class="text-muted">*</span></label><select class="input" id="account_id" name="account_id" required><option value="">Select…</option><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['username']) ?></option><?php endforeach; ?></select></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Add domain</button><a href="<?= e(url('admin/email')) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>