<div class="auth-inner auth-center">
  <div class="error-code"><?= !empty($debug) ? e($exception->getCode() ?: 500) : '500' ?></div>
  <h1 class="auth-title">Something went wrong</h1>
  <p class="auth-subtitle"><?= e($message) ?></p>

  <?php if (!empty($debug)): ?>
    <pre class="error-trace"><?= e($exception->getMessage()) . "\n\n" . e($exception->getTraceAsString()) ?></pre>
  <?php endif; ?>

  <a href="<?= e(url('/')) ?>" class="btn btn-primary">Return home</a>
</div>
