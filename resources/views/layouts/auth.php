<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Control Panel') ?></title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/favicon-32.png')) ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= e(asset('img/favicon-192.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-shell">
  <main class="auth-wrap">
    <div class="auth-card">
      <?= $content ?>
    </div>
  </main>
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
