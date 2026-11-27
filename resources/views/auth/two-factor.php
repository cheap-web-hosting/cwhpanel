<div class="auth-inner">
  <div class="auth-brand">
    <img class="auth-logo" src="<?= e(asset('img/logo.png')) ?>" alt="CWH Control Panel" width="480" height="178" decoding="async">
  </div>

  <h1 class="auth-title">Two-factor authentication</h1>
  <p class="auth-subtitle">Enter the 6-digit code from your authenticator app to continue.</p>

  <?= $view->partial('partials/flash') ?>

  <form method="post" action="<?= e($postUrl) ?>" class="form-stack" novalidate>
    <?= csrf_field() ?>

    <div class="field">
      <label class="field-label" for="code">Authentication code</label>
      <input class="input" type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code" autofocus>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Verify</button>
  </form>
</div>