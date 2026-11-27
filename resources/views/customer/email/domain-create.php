<div class="panel"><div class="panel-header"><h2 class="panel-title">Add mail domain</h2></div>
<form method="post" action="<?= e(url('panel/email/domains')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="domain">Domain <span class="text-muted">*</span></label><input class="input" type="text" id="domain" name="domain" placeholder="example.com" required></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Add domain</button><a href="<?= e(url('panel/email')) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>