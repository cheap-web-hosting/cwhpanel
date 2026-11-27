<?php $settings = $settings ?? []; $wafInstalled = $wafInstalled ?? false; $blocks = $blocks ?? []; $blocksError = $blocksError ?? ''; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Web Application Firewall</h2></div>
<?php if (!$wafInstalled): ?>
<p class="text-muted" style="padding:0 16px 16px">ModSecurity is not yet installed on the server. Contact your administrator to enable the web application firewall.</p>
<?php else: ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Website</th><th>Domain</th><th>WAF</th><th>Disabled rules</th><th></th></tr></thead>
<tbody>
<?php if (empty($settings)): ?><?= empty_state(5, 'No website security settings yet. Create a website to configure its WAF.') ?>
<?php else: foreach ($settings as $s): $excl = $s['exclusions'] ?? []; ?>
<tr><td><?= e($s['label']) ?></td><td><?= e($s['domain']) ?></td>
<td><?= $s['waf_enabled']?'<span class="status status-active">Enabled</span>':'<span class="status status-disabled">Disabled</span>' ?><?= $s['waf_enabled']?' <small class="text-muted">(' . e($s['waf_mode']) . ')</small>':'' ?></td>
<td><?php if (empty($excl)): ?><span class="text-muted">—</span><?php else: foreach ($excl as $rid): ?>
<form method="post" action="<?= e(url('panel/security/waf/exclusions/delete')) ?>" class="inline-form"><?= csrf_field() ?>
<input type="hidden" name="website_id" value="<?= e($s['website_id']) ?>">
<input type="hidden" name="rule_id" value="<?= (int)$rid ?>">
<code><?= (int)$rid ?></code> <button type="submit" class="btn btn-sm" data-confirm="Re-enable rule <?= (int)$rid ?> for this site?" title="Re-enable rule">×</button></form>
<?php endforeach; endif; ?></td>
<td class="actions">
<?php if (!$s['waf_enabled']): ?><form method="post" action="<?= e(url('panel/security/waf/toggle')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="website_id" value="<?= e($s['website_id']) ?>"><input type="hidden" name="enabled" value="1"><button type="submit" class="btn btn-sm">Enable</button></form>
<?php else: ?><form method="post" action="<?= e(url('panel/security/waf/toggle')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="website_id" value="<?= e($s['website_id']) ?>"><input type="hidden" name="enabled" value="0"><button type="submit" class="btn btn-sm">Disable</button></form><?php endif; ?>
</td></tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<?php endif; ?>
</div>

<div class="panel"><div class="panel-header"><h2 class="panel-title">Disable a rule by ID</h2></div>
<div style="padding:0 16px 16px">
<p class="text-muted">If a legitimate page of yours is blocked, disable the rule ID shown in the log below. Ask your administrator first if you are unsure — disabled rules weaken protection.</p>
<form method="post" action="<?= e(url('panel/security/waf/exclusions')) ?>"><?= csrf_field() ?>
<label class="field-label">Website</label>
<select class="input" name="website_id"><?php foreach ($settings as $s): ?><option value="<?= e($s['website_id']) ?>"><?= e($s['label']) ?> (<?= e($s['domain']) ?>)</option><?php endforeach; ?></select>
<label class="field-label">Rule ID</label>
<input class="input" type="text" name="rule_id" placeholder="e.g. 941110" style="max-width:200px">
<div class="form-actions"><button type="submit" class="btn btn-primary">Disable rule</button></div></form>
</div></div>

<div class="panel"><div class="panel-header"><h2 class="panel-title">Recent blocked attacks</h2></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th>Site</th><th>IP</th><th>URI</th><th>Rule</th><th>Message</th><th>Action</th><th></th></tr></thead>
<tbody><?php if ($blocksError !== ''): ?><tr><td colspan="8"><span class="status status-warn">Attack attribution unavailable</span> <span class="text-muted">— <?= e($blocksError) ?>. Your rules and exclusions still apply; ask the administrator to check the audit log.</span></td></tr>
<?php elseif (empty($blocks)): ?><?= empty_state(8, 'No ModSecurity hits for your sites in the recent audit log.') ?>
<?php else: foreach ($blocks as $b): ?>
<tr><td><small><?= e($b['time']) ?></small></td><td><?= e($b['domain'] !== '' ? $b['domain'] : '(unknown host)') ?></td><td><code><?= e($b['ip']) ?></code></td>
<td><code><?= e(mb_substr($b['uri'], 0, 60)) ?></code></td><td><code><?= (int)$b['rule'] ?></code></td><td><?= e(mb_substr($b['msg'], 0, 80)) ?></td>
<td><?= $b['blocked'] ? '<span class="status status-disabled">Blocked</span>' : '<span class="status status-warn">Detected</span>' ?></td>
<td class="actions"><?php if (!empty($b['website_id'])): ?>
<form method="post" action="<?= e(url('panel/security/waf/exclusions')) ?>" class="inline-form"><?= csrf_field() ?>
<input type="hidden" name="website_id" value="<?= (int)$b['website_id'] ?>">
<input type="hidden" name="rule_id" value="<?= (int)$b['rule'] ?>">
<button type="submit" class="btn btn-sm" data-confirm="Disable rule <?= (int)$b['rule'] ?> for <?= e($b['domain']) ?>?" title="Disable this rule for the site">Disable rule</button></form>
<?php endif; ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
</div>