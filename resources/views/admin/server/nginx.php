<?php $vhosts = $vhosts ?? []; $testOutput = $testOutput ?? ''; $agentError = $agentError ?? null; ?>
<?php if ($agentError !== null): ?>
  <div class="flash flash-error" role="alert"><?= e($agentError) ?></div>
<?php endif; ?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Nginx</h2></div>
  <div class="form-actions" style="padding:16px">
    <form method="post" action="<?= e(url('admin/server/nginx/action')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn">Test configuration (nginx -t)</button>
    </form>
    <form method="post" action="<?= e(url('admin/server/nginx/action')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reload">
      <button type="submit" class="btn btn-primary" data-confirm="Reload nginx (applies vhost changes)?">Reload nginx</button>
    </form>
  </div>
  <?php if ($testOutput !== ''): ?>
    <pre style="margin:0 16px 16px;padding:12px;background:#111;color:#9f9;border-radius:6px;white-space:pre-wrap;word-break:break-word;font-size:12px"><?= e($testOutput) ?></pre>
  <?php endif; ?>
</div>

<h2 class="section-title">Vhosts (conf.d)</h2>
<div class="panel">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>File</th><th>Size</th><th>Modified</th></tr></thead>
    <tbody>
      <?php if (empty($vhosts)): ?>
        <?= empty_state(3, 'No vhosts listed.') ?>
      <?php else: ?>
        <?php foreach ($vhosts as $v): ?>
          <tr>
            <td><code><?= e($v['name']) ?></code></td>
            <td><?= e((string) $v['size']) ?></td>
            <td><?= e($v['modified']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>
