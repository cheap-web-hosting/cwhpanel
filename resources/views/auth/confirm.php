<div class="auth-inner">
  <div class="auth-brand">
    <img class="auth-logo" src="<?= e(asset('img/logo.png')) ?>" alt="CWH Control Panel" width="480" height="178" decoding="async">
  </div>

  <h1 class="auth-title"><?= e($heading) ?></h1>
  <p class="auth-subtitle">Re-enter your password to authorize this action.</p>

  <?= $view->partial('partials/flash') ?>

  <form method="post" action="<?= e($postUrl) ?>" class="form-stack" novalidate>
    <?= csrf_field() ?>

    <div class="field">
      <label class="field-label" for="password">Password</label>
      <input class="input" type="password" id="password" name="password" required autocomplete="current-password" autofocus>
    </div>

    <?php if (!empty($requiresTwoFactor)): ?>
      <div class="field">
        <label class="field-label" for="totp">Two-factor code</label>
        <input class="input" type="text" id="totp" name="totp" inputmode="numeric" autocomplete="one-time-code" placeholder="000000">
      </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary btn-block">Confirm</button>
  </form>
</div>
