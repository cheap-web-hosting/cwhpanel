<?php
/**
 * Degraded/skipped module banner (admin only).
 *
 * A module that failed to load must never disappear silently: every admin
 * page shows which modules are not running and why, with a link to the
 * Modules page. The banner is informational — the rest of the panel keeps
 * working (ModuleManager degrades instead of fataling).
 */
try {
    $manager = app()->moduleManager();
    $skipped = $manager->skipped();
    $degraded = $manager->degraded();
} catch (\Throwable) {
    $skipped = [];
    $degraded = [];
}

// "disabled by configuration" is a deliberate admin choice — no banner noise.
$cascade = array_filter($skipped, fn ($r) => !str_starts_with((string) $r, 'disabled by configuration'));

if ($cascade === [] && $degraded === []) {
    return;
}
?>
<div class="flash flash-error" role="alert">
  <strong>Some modules are not running.</strong>
  The panel is operating without them — features they own are unavailable.
  <a href="<?= e(url('admin/settings/modules')) ?>">Review modules</a>
  <ul style="margin:6px 0 0 18px">
    <?php foreach ($cascade as $id => $reason): ?>
      <li><code><?= e($id) ?></code> — <?= e($reason) ?></li>
    <?php endforeach; ?>
    <?php foreach ($degraded as $id => $reason): ?>
      <li><code><?= e($id) ?></code> — <?= e($reason) ?></li>
    <?php endforeach; ?>
  </ul>
</div>