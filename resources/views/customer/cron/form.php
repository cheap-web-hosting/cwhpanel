<?php $old = $old ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Add cron job</h2></div>
<form method="post" action="<?= e(url('panel/cron')) ?>" class="form-stack" style="padding:16px"><?= csrf_field() ?>
<div class="field"><label class="field-label" for="schedule">Schedule <span class="text-muted">*</span></label><input class="input" type="text" id="schedule" name="schedule" placeholder="*/5 * * * *" required><p class="field-help">Standard 5-field cron expression.</p></div>
<div class="field"><label class="field-label" for="command">Command <span class="text-muted">*</span></label><input class="input" type="text" id="command" name="command" placeholder="/usr/bin/php /home/user/script.php" required></div>
<div class="field"><label class="check"><input type="checkbox" name="jailed" value="1" <?= !empty($old['jailed']) ? 'checked' : '' ?>> <span>Run isolated (jailed)</span></label><p class="field-help">Executes in a filesystem jail: the job cannot see other accounts, panel secrets, backups or /root. (Applies to both scheduled runs and "Run now".)</p></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">Create</button><a href="<?= e(url('panel/cron')) ?>" class="btn btn-ghost">Cancel</a></div>
</form></div>