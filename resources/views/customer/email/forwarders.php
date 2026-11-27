<?php $forwarders = $forwarders ?? []; $domainOptions = $domainOptions ?? []; ?>
<div class="panel" style="padding:16px">
  <?php if (empty($domainOptions)): ?><p class="text-muted" style="margin:0">Add a domain first to create forwarders.</p>
  <?php else: ?>
  <form method="post" action="<?= e(url('panel/email/forwarders')) ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end"><?= csrf_field() ?>
    <label class="field" style="flex:2 1 170px;margin:0"><span class="field-label">Domain</span><select class="input" name="domain" required>
      <option value="">Select domain…</option>
      <?php foreach ($domainOptions as $o): ?><option value="<?= e($o['name']) ?>"><?= e($o['name']) ?><?= empty($o['mail']) ? ' (enables mail)' : '' ?></option><?php endforeach; ?>
    </select></label>
    <label class="field" style="flex:2 1 170px;margin:0"><span class="field-label">From (local part or full address)</span><input class="input" type="text" name="source" placeholder="sales" required spellcheck="false"></label>
    <label class="field" style="flex:2 1 170px;margin:0"><span class="field-label">Forwards to</span><input class="input" type="email" name="destination" placeholder="you@example.com" required maxlength="255" spellcheck="false"></label>
    <button type="submit" class="btn btn-primary">Add forwarder</button>
  </form>
  <?php endif; ?>
</div>

<h2 class="section-title">Forwarders</h2>
<div class="panel">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>From</th><th>To</th><th>Domain</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($forwarders)): ?><?= empty_state(4, 'No forwarders yet.') ?>
      <?php else: foreach ($forwarders as $f): ?>
        <tr><td><code><?= e($f['source']) ?></code></td><td><?= e($f['destination']) ?></td><td><?= e($f['domain']) ?></td>
        <td class="actions"><a href="<?= e(url('panel/email/domains/'.$f['domain_id'].'#forwarders')) ?>" class="btn btn-sm">Manage</a>
        <form method="post" action="<?= e(url('panel/email/domains/'.$f['domain_id'].'/forwarder/'.$f['id'].'/delete')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="return_to" value="/panel/email/forwarders"><button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete forwarder <?= e($f['source']) ?>?">Delete</button></form></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
