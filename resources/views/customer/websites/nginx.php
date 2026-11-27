<?php $website = $website ?? []; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Generated Nginx configuration</h2>
    <span class="badge"><?= e($website['domain_name'] ?? '') ?></span>
  </div>
  <pre class="error-trace" style="margin:0;border:0"><?= e($website['nginx_config'] ?? '') ?></pre>
</div>