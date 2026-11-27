<?php
$version = (string) ($version ?? '84');
$label = (string) ($label ?? '8.4');
$iniPath = (string) ($iniPath ?? '/etc/php.ini');
$ini = (string) ($ini ?? '');
$disabled = array_map('strval', (array) ($disabled ?? []));
$agentError = $agentError ?? null;
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
  <h1 style="margin:0 0 16px">PHP <span style="text-transform:none"><?= e($label) ?></span> config</h1>
  <a href="<?= e(url('admin/server/php-versions')) ?>" class="btn btn-sm">← PHP versions</a>
</div>

<?php if ($agentError !== null): ?>
  <div class="flash flash-error" role="alert"><?= e($agentError) ?></div>
<?php else: ?>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Disabled functions</h2></div>
  <div style="padding:16px">
    <p style="margin-top:0;color:#666">
      Controls the <code>disable_functions</code> directive in
      <code><?= e($iniPath) ?></code>. Functions listed here are disabled for every
      PHP-FPM pool on this version. Leave empty to clear the directive and restore the
      stock behavior. Changes are validated with <code>php-fpm -t</code> and the daemon
      is reloaded before anything else is touched — a bad value never applies.
    </p>
    <form method="post" action="<?= e(url('admin/server/php-config/' . $version . '/functions')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <textarea class="input" id="functions" name="functions" rows="10" spellcheck="false"
                placeholder="exec,shell_exec,system,…&#10;(one per line or comma separated)"><?= e(implode("\n", $disabled)) ?></textarea>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary" data-confirm="Save disabled functions for PHP <?= e($label) ?>? The FPM daemon will be reloaded.">Save disabled functions</button>
      </div>
    </form>
    <p style="margin:0;color:#666">Currently disabled: <strong><?= $disabled === [] ? 'none' : e(count($disabled) . ' function(s)') ?></strong></p>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2 class="panel-title">php.ini</h2></div>
  <div style="padding:16px">
    <p style="margin-top:0;color:#666">
      Full contents of <code><?= e($iniPath) ?></code> (<?= number_format(strlen($ini)) ?> bytes).
      On save the agent validates the file with <code>php-fpm -t</code> and reloads the
      daemon; if validation fails the previous contents are restored.<?= $version === '84'
        ? ' This is the system PHP the panel itself runs on — edits take effect for every 8.4 pool.'
        : ' Edits apply to every pool running this version.' ?>
    </p>
    <form method="post" action="<?= e(url('admin/server/php-config/' . $version . '/ini')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <textarea class="input" id="ini" name="ini" rows="30" spellcheck="false" style="font-family:monospace"><?= e($ini) ?></textarea>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary" data-confirm="Save php.ini for PHP <?= e($label) ?>? Invalid config is rejected and rolled back automatically.">Save php.ini</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>