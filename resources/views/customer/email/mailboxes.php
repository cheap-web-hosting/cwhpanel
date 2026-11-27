<?php $hostDomains = $hostDomains ?? []; $mailboxes = $mailboxes ?? []; $quotaMax = $quotaMax ?? 1024; ?>
<div class="panel" style="padding:16px">
  <form method="post" action="<?= e(url('panel/email/accounts')) ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end"><?= csrf_field() ?>
    <label class="field" style="flex:2 1 150px;margin:0"><span class="field-label">Username</span><input class="input" type="text" name="local" placeholder="name" required></label>
    <label class="field" style="flex:2 1 170px;margin:0"><span class="field-label">Domain</span><select class="input" name="domain" required>
      <option value="">Select domain…</option>
      <?php foreach ($hostDomains as $hd): ?><option value="<?= e($hd['name']) ?>"><?= e($hd['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="field" style="flex:2 1 150px;margin:0"><span class="field-label">Password</span><input class="input" type="password" autocomplete="new-password" name="password" placeholder="Min 8 characters" title="Minimum 8 characters" required minlength="8"></label>
    <label class="field" style="flex:0 0 auto;margin:0"><span class="field-label">Quota</span><span style="display:inline-flex;align-items:center;gap:6px"><input class="input" type="number" name="quota_mb" value="100" min="1"<?= $quotaMax > 0 ? ' max="' . (int) $quotaMax . '"' : '' ?> inputmode="numeric" aria-label="Quota in megabytes" style="width:84px;min-width:0"><span class="text-muted" style="font-size:13px">MB<?= $quotaMax > 0 ? ' (max ' . (int) $quotaMax . ')' : '' ?></span></span></label>
    <button type="submit" class="btn btn-primary">Create account</button>
  </form>
  <?php if (empty($hostDomains)): ?><p class="text-muted" style="margin:12px 0 0">Add a domain first to create email accounts.</p><?php endif; ?>
</div>

<h2 class="section-title">Email accounts</h2>
<div class="panel">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Email</th><th>Quota</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($mailboxes)): ?><?= empty_state(4, 'No email accounts yet.') ?>
      <?php else: foreach ($mailboxes as $mb): ?>
        <tr><td><strong><?= e($mb['email']) ?></strong></td><td><?= e($mb['quota_mb']) ?> MB</td>
        <td><?= $mb['status']==='active'?'<span class="status status-active">Active</span>':'<span class="status status-disabled">'.e($mb['status']).'</span>' ?></td>
        <td class="actions">
          <?php if (feature_enabled('webmail')): ?><a href="<?= e(url('panel/webmail/open/'.$mb['id'])) ?>" class="btn btn-sm">Webmail</a><?php endif; ?>
          <a href="<?= e(url('panel/email/domains/'.$mb['domain_id'])) ?>" class="btn btn-sm">Manage</a>
        </td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
