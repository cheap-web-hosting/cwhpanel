<?php $wafInstalled = $wafInstalled ?? false; $sites = $sites ?? []; $blocks = $blocks ?? []; ?>
<div class="panel"><div class="panel-header"><h2 class="panel-title">Web Application Firewall</h2></div>
<?php if (!$wafInstalled): ?>
<div class="flash flash-error" role="alert">ModSecurity is not installed. Install it from <code>/root/cwhpanel/agent/install-modsecurity.sh</code> before enabling per-site WAF.</div>
<?php else: ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Website</th><th>Account</th><th>Domain</th><th>WAF</th><th>Hotlink</th><th>Disabled rules</th><th></th></tr></thead>
<tbody><?php if (empty($sites)): ?><?= empty_state(7, 'No websites yet.') ?>
<?php else: foreach ($sites as $s): $enabled = !empty($s['waf_enabled']); $mode = $s['waf_mode'] ?? 'detection'; $hotlink = !empty($s['hotlink_protection']); $excl = $s['exclusions'] ?? []; ?>
<tr><td><?= e($s['label']) ?></td><td><?= e($s['username']??'—') ?></td><td><?= e($s['domain']??'—') ?></td>
<td><?= $enabled?'<span class="status status-active">Enabled</span>':'<span class="status status-disabled">Disabled</span>' ?></td>
<td><?= $hotlink?'<span class="status status-active">On</span>':'<span class="status status-disabled">Off</span>' ?></td>
<td><?php if (empty($excl)): ?><span class="text-muted">—</span><?php else: foreach ($excl as $rid): ?>
<form method="post" action="<?= e(url('admin/security/waf/exclusions/delete')) ?>" class="inline-form"><?= csrf_field() ?>
<input type="hidden" name="website_id" value="<?= (int)$s['website_id'] ?>">
<input type="hidden" name="rule_id" value="<?= (int)$rid ?>">
<code><?= (int)$rid ?></code> <button type="submit" class="btn btn-sm" data-confirm="Re-enable rule <?= (int)$rid ?> for this site?" title="Re-enable rule">×</button></form>
<?php endforeach; endif; ?></td>
<td class="actions">
<?php if ($enabled): ?>
<form method="post" action="<?= e(url('admin/security/waf/toggle')) ?>" class="inline-form"><?= csrf_field() ?>
<input type="hidden" name="website_id" value="<?= (int)$s['website_id'] ?>">
<input type="hidden" name="enabled" value="0">
<input type="hidden" name="mode" value="<?= e($mode) ?>">
<button type="submit" class="btn btn-sm btn-danger">Disable WAF</button></form>
<?php else: ?>
<form method="post" action="<?= e(url('admin/security/waf/toggle')) ?>" class="inline-form"><?= csrf_field() ?>
<input type="hidden" name="website_id" value="<?= (int)$s['website_id'] ?>">
<input type="hidden" name="enabled" value="1">
<select class="input" name="mode" style="max-width:110px"><option value="detection">Detection</option><option value="on">On</option></select>
<button type="submit" class="btn btn-sm btn-primary">Enable WAF</button></form>
<?php endif; ?>
<form method="post" action="<?= e(url('admin/security/hotlink/toggle')) ?>" class="inline-form"><?= csrf_field() ?>
<input type="hidden" name="website_id" value="<?= (int)$s['website_id'] ?>">
<input type="hidden" name="enabled" value="<?= $hotlink ? 0 : 1 ?>">
<button type="submit" class="btn btn-sm"><?= $hotlink ? 'Hotlink off' : 'Hotlink on' ?></button></form>
</td></tr>
<?php endforeach; endif; ?></tbody></table></div>
<?php endif; ?>
</div>

<div class="panel"><div class="panel-header"><h2 class="panel-title">Disable a rule by ID</h2></div>
<div style="padding:0 16px 16px">
<p class="text-muted">Use when a legitimate page trips a CRS rule (false positive). Find the ID in the blocked-attacks log below, or in the audit log. The exclusion lands as <code>SecRuleRemoveById</code> in that site's ModSecurity config.</p>
<form method="post" action="<?= e(url('admin/security/waf/exclusions')) ?>"><?= csrf_field() ?>
<label class="field-label">Website</label>
<select class="input" name="website_id"><?php foreach ($sites as $s): ?><option value="<?= (int)$s['website_id'] ?>"><?= e($s['label']) ?> (<?= e($s['domain'] ?? '') ?>)</option><?php endforeach; ?></select>
<label class="field-label">Rule ID</label>
<input class="input" type="text" name="rule_id" placeholder="e.g. 941110" style="max-width:200px">
<label class="field-label">Reason (optional)</label>
<input class="input" type="text" name="reason" placeholder="Why is this a false positive?" style="width:100%">
<div class="form-actions"><button type="submit" class="btn btn-primary">Disable rule</button></div></form>
</div></div>

<div class="panel"><div class="panel-header"><h2 class="panel-title">Recent blocked attacks</h2></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th>Site</th><th>IP</th><th>URI</th><th>Rule</th><th>Message</th><th>Action</th><th></th></tr></thead>
<tbody><?php if (empty($blocks)): ?><?= empty_state(8, 'No ModSecurity hits in the recent audit log.') ?>
<?php else: foreach ($blocks as $b): ?>
<tr><td><small><?= e($b['time']) ?></small></td><td><?= e($b['domain'] !== '' ? $b['domain'] : '(unknown host)') ?></td><td><code><?= e($b['ip']) ?></code></td>
<td><code><?= e(mb_substr($b['uri'], 0, 60)) ?></code></td><td><code><?= (int)$b['rule'] ?></code></td><td><?= e(mb_substr($b['msg'], 0, 80)) ?></td>
<td><?= $b['blocked'] ? '<span class="status status-disabled">Blocked</span>' : '<span class="status status-warn">Detected</span>' ?></td>
<td class="actions"><?php if (!empty($b['website_id'])): ?>
<form method="post" action="<?= e(url('admin/security/waf/exclusions')) ?>" class="inline-form"><?= csrf_field() ?>
<input type="hidden" name="website_id" value="<?= (int)$b['website_id'] ?>">
<input type="hidden" name="rule_id" value="<?= (int)$b['rule'] ?>">
<button type="submit" class="btn btn-sm" data-confirm="Disable rule <?= (int)$b['rule'] ?> for <?= e($b['domain']) ?>?" title="Disable this rule for the site">Disable rule</button></form>
<?php endif; ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
</div>