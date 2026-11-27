<?php
$notifCount = 0;
try {
    $uid = (int) (app('auth')->user()?->id ?? 0);
    if ($uid > 0) {
        $notifCount = app(\Modules\Core\Services\NotificationService::class)->unreadCount($uid);
    }
} catch (\Throwable $e) {
    $notifCount = 0;
}
?>
<div class="notif" data-notif>
  <button class="topbar-icon notif-toggle" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" data-notif-toggle>
    <?= icon('bell', 20) ?>
    <?php if ($notifCount > 0): ?><span class="notif-badge" data-notif-count><?= $notifCount ?></span><?php endif; ?>
  </button>
  <div class="notif-panel" data-notif-panel hidden>
    <div class="notif-head">Notifications<button type="button" class="btn btn-ghost btn-sm" data-notif-readall>Mark all read</button></div>
    <ul class="notif-list" data-notif-list></ul>
    <p class="notif-empty" data-notif-empty hidden>No notifications.</p>
  </div>
</div>
