<?php
$nav = app(\Modules\Packages\Services\FeatureService::class)->filterNav(
    app('navigation')->tree('customer'),
    app('auth')->user()
);
$currentPath = app('request')->path();
$breadcrumbs = app('navigation')->breadcrumbs('customer', $currentPath);
$user = app('auth')->user();
$headerBrand = config('app.name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Control Panel') ?> — <?= e($headerBrand) ?></title>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/favicon-32.png')) ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= e(asset('img/favicon-192.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="customer-shell">
  <a class="skip-link" href="#main-content">Skip to main content</a>

  <header class="topbar">
    <div class="topbar-brand">
      <img class="brand-logo" src="<?= e(asset('img/logo.png')) ?>" alt="CWH Control Panel" width="480" height="178" decoding="async">
    </div>
    <button class="topbar-menu" type="button" data-sidebar-toggle aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"><?= icon('menu', 20) ?></button>
    <div class="topbar-title"><?= e($title ?? 'Dashboard') ?></div>
    <div class="topbar-actions">
      <a class="topbar-icon" href="<?= e(url('panel')) ?>" aria-label="Home"><?= icon('layout-dashboard', 20) ?></a>
      <a class="topbar-icon" href="<?= e(url('panel/account')) ?>" aria-label="Account"><?= icon('settings', 20) ?></a>
      <?= $view->partial('partials/notifications') ?>
      <div class="user-menu">
        <span class="user-avatar"><?= e(strtoupper(substr($user->name ?? 'U', 0, 1))) ?></span>
        <span class="user-name"><?= e($user->name ?? 'Customer') ?></span>
        <form method="post" action="<?= e(url('logout')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm">Sign out</button>
        </form>
      </div>
    </div>
  </header>

  <div class="shell">
    <aside class="sidebar sidebar-light" id="sidebar">
      <div class="sidebar-brand">
        <span class="brand-mark"><?= icon('layout-dashboard', 20) ?></span>
        <span class="brand-name">My Account</span>
        <button class="sidebar-close" type="button" data-sidebar-close aria-label="Close menu"><?= icon('x', 18) ?></button>
      </div>
      <div class="sidebar-search">
        <span class="search-icon"><?= icon('search', 16) ?></span>
        <input type="search" id="nav-search" placeholder="Search tools" autocomplete="off" aria-label="Search tools">
      </div>
      <nav class="sidebar-nav" aria-label="Customer navigation">
        <?= $view->partial('partials/nav', ['nav' => $nav, 'currentPath' => $currentPath]) ?>
        <p id="nav-empty" role="status" hidden>No navigation matches.</p>
      </nav>
      <div class="sidebar-footer">
        <form method="post" action="<?= e(url('logout')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-block" style="color:inherit;border-color:var(--border)">Sign out</button>
        </form>
      </div>
    </aside>

    <div class="main">
      <?php $masq = app('auth')->masquerading(); ?>
      <?php if ($masq !== null): ?>
        <div class="flash flash-warning masq-banner" style="margin:0;border-radius:0;border-left:0;border-right:0" role="alert">
          <span>Viewing as <strong><?= e($user->email ?? 'customer') ?></strong> (admin impersonation).</span>
          <form method="post" action="<?= e(url('impersonate/stop')) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-sm">Return to admin</button></form>
        </div>
      <?php endif; ?>
      <?php if ($breadcrumbs): ?>
        <?= $view->partial('partials/breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>
      <?php endif; ?>

      <div class="content-wrap">
        <main class="content" id="main-content" tabindex="-1">
          <?= $view->partial('partials/flash') ?>
          <?php if (!empty($title) && $title !== 'Dashboard'): ?>
            <div class="page-head"><h1 class="page-title"><?= e($title ?? '') ?></h1></div>
          <?php endif; ?>
          <?= $content ?>
        </main>
      </div>
    </div>
  </div>

  <div class="sidebar-overlay" data-sidebar-toggle hidden></div>

  <script src="<?= e(asset('js/qrcodegen.js')) ?>" defer></script>
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>