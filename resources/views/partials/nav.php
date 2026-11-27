<?php
$nav = $nav ?? [];
$currentPath = $currentPath ?? '/';
$registry = app('navigation');
$activeUrl = null;
foreach ($nav as $item) {
    foreach ($item['children'] ?? [$item] as $link) {
        if ($registry->isActive($link, $currentPath) && strlen($link['url'] ?? '') > strlen($activeUrl ?? '')) {
            $activeUrl = $link['url'];
        }
    }
}
?>
<ul class="nav">
  <?php foreach ($nav as $item): ?>
    <?php $hasChildren = !empty($item['children']); ?>
    <?php $active = $activeUrl !== null && ($hasChildren ? in_array($activeUrl, array_column($item['children'], 'url'), true) : ($item['url'] ?? null) === $activeUrl); ?>

    <?php if ($hasChildren): ?>
      <li class="nav-group<?= $active ? ' is-open is-active' : '' ?>">
        <button type="button" class="nav-group-toggle" aria-expanded="<?= $active ? 'true' : 'false' ?>">
          <span class="nav-icon"><?= icon($item['icon'] ?? 'circle', 18) ?></span>
          <span class="nav-label"><?= e($item['label']) ?></span>
          <span class="nav-chevron"><?= icon('chevron-down', 14) ?></span>
        </button>
        <ul class="nav-children">
          <?php foreach ($item['children'] as $child): ?>
            <?php $childActive = $activeUrl !== null && ($child['url'] ?? null) === $activeUrl; ?>
            <li>
              <a href="<?= e($child['url'] ?? '#') ?>" class="nav-child<?= $childActive ? ' is-active' : '' ?>"<?= $childActive ? ' aria-current="page"' : '' ?>>
                <span class="nav-child-label"><?= e($child['label']) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </li>
    <?php else: ?>
      <li>
        <a href="<?= e($item['url'] ?? '#') ?>" class="nav-link<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= icon($item['icon'] ?? 'circle', 18) ?></span>
          <span class="nav-label"><?= e($item['label']) ?></span>
        </a>
      </li>
    <?php endif; ?>
  <?php endforeach; ?>
</ul>
