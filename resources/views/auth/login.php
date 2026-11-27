<div class="auth-inner">
  <div class="auth-brand">
    <img class="auth-logo" src="<?= e(asset('img/logo.png')) ?>" alt="CWH Control Panel" width="480" height="178" decoding="async">
  </div>

  <h1 class="auth-title">Sign in</h1>
  <p class="auth-subtitle"><?= e($heading) ?> control panel</p>

  <?= $view->partial('partials/flash') ?>

  <form method="post" action="<?= e($postUrl) ?>" class="form-stack" novalidate>
    <?= csrf_field() ?>

    <div class="field">
      <label class="field-label" for="email">Email address</label>
      <input class="input" type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="username" autofocus>
    </div>

    <div class="field">
      <label class="field-label" for="password">Password</label>
      <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
    </div>

    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
  </form>

  <?php if ($type === 'admin'): ?>
    <p class="auth-switch">
      <a href="<?= e(url('panel/login')) ?>">Customer sign in</a>
    </p>
  <?php endif; ?>
</div>
