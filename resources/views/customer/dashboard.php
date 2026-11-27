<?php
use Modules\Core\Services\SystemInfo;
$user = app('auth')->user();
$nav = app(\Modules\Packages\Services\FeatureService::class)->filterNav(
    app('navigation')->tree('customer'),
    $user
);
$websites = $websites ?? [];
$mailboxes = $mailboxes ?? [];
$diskQuotaMb = $diskQuotaMb ?? null;
$diskUsedBytes = $diskUsedBytes ?? null;
$quotaUsage = $quotaUsage ?? null;
$totalDomains = count($websites);
$usedText = $diskUsedBytes === null ? 'Not measured' : SystemInfo::formatBytes((float) $diskUsedBytes);
$quotaText = $diskQuotaMb !== null ? SystemInfo::formatBytes((float) $diskQuotaMb * 1024 * 1024) : 'Unlimited';
$usedPercent = ($diskUsedBytes !== null && $diskQuotaMb !== null && $diskQuotaMb > 0)
    ? (int) min(100, round($diskUsedBytes / ((float) $diskQuotaMb * 1024 * 1024) * 100)) : null;
?>
<div class="dashboard-grid">
  <div class="dashboard-main">
    <div class="welcome">
      <h2 class="welcome-title">Welcome back, <?= e($user->name ?? 'there') ?></h2>
      <p class="welcome-sub">Manage your websites and hosting from one place.</p>
    </div>

    <div class="fn-search">
      <input type="search" id="fn-search" placeholder="Find functions quickly by typing here." autocomplete="off" aria-label="Find functions quickly by typing here.">
      <span class="search-icon"><?= icon('search', 18) ?></span>
    </div>

    <?php if (!$websites): ?>
      <?php if (feature_enabled('websites') || feature_enabled('domains')): ?>
      <div class="panel" style="padding:18px;border-left:3px solid var(--primary)">
        <h3 style="margin:0 0 6px;font-weight:500">Set up your first website</h3>
        <p class="text-muted" style="margin:0 0 14px">Websites are added by your administrator — once one is set up you can manage its files, email and SSL here.</p>
        <?php if (feature_enabled('domains')): ?><a href="<?= e(url('panel/domains')) ?>" class="btn btn-ghost">Manage Domains</a><?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($nav as $item): ?>
      <section class="fn-section" data-fn-group="<?= e($item['label']) ?>">
        <?php if (!empty($item['children'])): ?>
          <div class="fn-section-header">
            <h3 class="fn-section-title"><?= e($item['label']) ?></h3>
          </div>
          <div class="feature-grid">
            <?php foreach ($item['children'] as $child): ?>
              <a class="feature" href="<?= e($child['url'] ?? '#') ?>" data-fn-label="<?= e($child['label']) ?>">
                <span class="feature-icon"><?= icon($child['icon'] ?? ($item['icon'] ?? 'circle'), 24) ?></span>
                <span class="feature-label"><?= e($child['label']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php elseif (!empty($item['url'])): ?>
          <a class="feature feature-item" href="<?= e($item['url']) ?>" data-fn-label="<?= e($item['label']) ?>" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border:0;border-bottom:1px solid var(--border)">
            <span class="feature-icon"><?= icon($item['icon'] ?? 'circle', 24) ?></span>
            <span class="feature-label"><?= e($item['label']) ?></span>
          </a>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <aside class="rail" style="width:auto">
    <div class="rail-card">
      <div class="rail-card-header">General Information</div>
      <div class="rail-row">
        <div class="rail-row-label">Current User</div>
        <div class="rail-row-value"><?= e($user->name ?? '—') ?></div>
      </div>
      <div class="rail-row">
        <div class="rail-row-label">Primary Domain</div>
        <div class="rail-row-value"><?= e($websites[0]['domain_name'] ?? '—') ?></div>
      </div>
      <div class="rail-row">
        <div class="rail-row-label">Home Directory</div>
        <div class="rail-row-value"><code><?= e($accountHome ?? ('/home/' . ($accountUsername ?? 'user'))) ?></code></div>
      </div>
      <div class="rail-row">
        <div class="rail-row-label">Websites</div>
        <div class="rail-row-value"><?= (int) $totalDomains ?></div>
      </div>
    </div>

    <div class="rail-card">
      <div class="rail-card-header">Statistics</div>
      <div class="rail-stat">
        <div class="rail-row-label">Disk Usage</div>
        <div class="rail-row-value"><?= e($usedText) ?> / <?= e($quotaText) ?><?= $usedPercent !== null ? ' (' . $usedPercent . '%)' : '' ?></div>
        <?php if ($usedPercent !== null): ?>
          <div class="bar"><div class="bar-fill<?= $usedPercent > 80 ? '' : ' green' ?>" style="width:<?= $usedPercent ?>%"></div></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($quotaUsage) && (int) ($quotaUsage['inode_soft'] ?? 0) > 0): ?>
      <div class="rail-stat">
        <div class="rail-row-label">Inodes</div>
        <div class="rail-row-value"><?= number_format((int) $quotaUsage['inode_used']) ?> / <?= number_format((int) $quotaUsage['inode_soft']) ?></div>
      </div>
      <?php endif; ?>
      <div class="rail-stat">
        <div class="rail-row-label">Mailboxes</div>
        <div class="rail-row-value"><?= count($mailboxes) ?></div>
      </div>
    </div>
  </aside>
</div>