<?php $domains = $domains ?? []; ?>
<?php if (empty($domains)): ?><div class="panel" style="padding:16px"><p class="text-muted" style="margin:0">No mail domains.</p></div>
<?php else: foreach ($domains as $d): ?>
<div class="panel" style="padding:16px;margin-bottom:12px"><form method="post" action="<?= e(url('panel/email/domains/'.$d['id'].'/catchall')) ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="return_to" value="/panel/email/catch-all">
<label class="field" style="margin:0;flex:1 1 220px"><span class="field-label"><?= e($d['domain']) ?> — unknown recipients go to (empty = bounce)</span><input class="input" type="text" name="catchall" value="<?= e($d['catchall'] ?? '') ?>" placeholder="catchall@example.com" spellcheck="false"></label>
<button type="submit" class="btn btn-primary btn-sm">Save</button></form></div>
<?php endforeach; endif; ?>
